<?php
declare(strict_types=1);

if (!extension_loaded('pdo_sqlite') || !extension_loaded('curl')) {
    fwrite(STDERR, "FAIL butuh pdo_sqlite dan curl; jalankan dengan PHP XAMPP.\n");
    exit(1);
}

require_once __DIR__ . '/../php/orders.php';
require_once __DIR__ . '/../php/device.php';
require_once __DIR__ . '/support.php';

$root = dirname(__DIR__);
$tempDir = sys_get_temp_dir() . '/rll-cli-reconcile-' . bin2hex(random_bytes(4));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    fwrite(STDERR, "FAIL tidak bisa membuat direktori sementara.\n");
    exit(1);
}

$failures = 0;
$servers = [];
$dbPath = $tempDir . '/orders.sqlite';
$configPath = $tempDir . '/config.php';

try {
    $db = open_test_db($dbPath);
    $future = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
    $past = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
    $token = str_repeat('8', 64);

    seed_order($db, 'RLL-20261111-000002', $token, 'expired', $past);
    seed_order($db, 'RLL-20261111-000014', $token, 'pending', $future);
    $db = null;

    write_config($configPath, [
        'server_key' => 'SB-Mid-server-reconcile-test',
        'signature_secret_key' => 'reconcile-test-secret',
        'is_production' => false,
        'status_api_timeout' => 3,
    ]);

    [$fakeProcess, $fakePort] = start_php_server(
        $root,
        $root . '/tests/fake-midtrans.php',
        $tempDir . '/fake-server.log',
        ['RLL_FAKE_LOG' => $tempDir . '/fake-requests.log']
    );
    $servers[] = $fakeProcess;
    $fakeBase = 'http://127.0.0.1:' . $fakePort;

    $runReconcile = static function (array $args) use (
        $root,
        $configPath,
        $dbPath,
        $fakeBase
    ): array {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['RLL_ENV'] = 'test';
        $env['RLL_CONFIG_PATH'] = $configPath;
        $env['RLL_DB_PATH'] = $dbPath;
        $env['RLL_MIDTRANS_API_BASE_URL'] = $fakeBase;

        $process = proc_open(
            array_merge([PHP_BINARY, $root . '/php/reconcile.php'], $args),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
            $env,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Gagal menjalankan reconcile.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [(int) $exitCode, (string) $stdout, (string) $stderr];
    };

    [$defaultCode, $defaultOut] = $runReconcile(['--dry-run', '--min-age=0']);
    assert_true('reconcile default berhasil', $defaultCode === 0, $failures);
    assert_true(
        'reconcile default tidak memeriksa expired',
        strpos($defaultOut, 'RLL-20261111-000002') === false
            && strpos($defaultOut, 'RLL-20261111-000014') !== false,
        $failures
    );

    [$dryCode, $dryOut] = $runReconcile([
        '--dry-run',
        '--include-expired',
        '--min-age=0',
        '--sleep=0',
    ]);
    assert_true('dry-run include-expired berhasil', $dryCode === 0, $failures);
    assert_true(
        'dry-run melaporkan promosi tanpa menulis',
        strpos($dryOut, 'akan dipromosikan ke paid (REVIEW)') !== false,
        $failures
    );
    assert_true(
        'body 404 dilaporkan not_found',
        strpos($dryOut, 'tidak ditemukan di Midtrans') !== false
            && strpos($dryOut, 'data tidak cocok') === false
            && strpos($dryOut, 'mismatch=0') !== false,
        $failures
    );

    $dbCheck = new PDO('sqlite:' . $dbPath);
    $dbCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbCheck->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $expiredRow = find_order($dbCheck, 'RLL-20261111-000002');
    assert_true(
        'dry-run tidak mengubah order expired',
        $expiredRow !== null && $expiredRow['status'] === 'expired',
        $failures
    );
    $dbCheck = null;

    [$liveCode, $liveOut] = $runReconcile([
        '--include-expired',
        '--min-age=0',
        '--sleep=0',
    ]);
    assert_true('reconcile live berhasil', $liveCode === 0, $failures);
    assert_true(
        'reconcile live mempromosikan expired',
        strpos($liveOut, 'dipromosikan ke paid (REVIEW)') !== false
            && strpos($liveOut, 'not_found=1') !== false,
        $failures
    );

    $dbCheck = new PDO('sqlite:' . $dbPath);
    $dbCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbCheck->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $paidRow = find_order($dbCheck, 'RLL-20261111-000002');
    $pendingRow = find_order($dbCheck, 'RLL-20261111-000014');
    assert_true(
        'order expired menjadi paid setelah reconcile live',
        $paidRow !== null
            && $paidRow['status'] === 'paid'
            && $paidRow['paid_at'] !== null,
        $failures
    );
    assert_true(
        'body 404 tidak mengubah order pending',
        $pendingRow !== null && $pendingRow['status'] === 'pending',
        $failures
    );
    $dbCheck = null;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    $failures++;
} finally {
    foreach ($servers as $server) {
        stop_server($server);
    }
    remove_tree($tempDir);
}

echo $failures === 0
    ? 'CLI RECONCILE: OK' . PHP_EOL
    : 'CLI RECONCILE: ' . $failures . ' gagal' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
