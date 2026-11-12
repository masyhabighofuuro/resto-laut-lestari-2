<?php
declare(strict_types=1);

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "FAIL pdo_sqlite tidak tersedia; jalankan dengan PHP XAMPP.\n");
    exit(1);
}

require_once __DIR__ . '/../php/orders.php';

$root = dirname(__DIR__);
$tempDir = sys_get_temp_dir() . '/rll-webhook-test-' . bin2hex(random_bytes(4));

if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    fwrite(STDERR, "FAIL tidak bisa membuat direktori sementara.\n");
    exit(1);
}

$failures = 0;
$servers = [];

/**
 * @param array{0: int, 1: string, 2: string} $response
 */
function assert_http(
    string $name,
    int $expectedStatus,
    array $response,
    int &$failures,
    ?string $expectedReason = null
): void {
    [$status, $body, $error] = $response;
    $ok = $status === $expectedStatus;

    $decoded = json_decode($body, true);
    if ($ok && $expectedReason !== null) {
        $ok = is_array($decoded) && ($decoded['reason'] ?? null) === $expectedReason;
    }

    if ($ok) {
        echo 'PASS ' . $name . ' (HTTP ' . $status . ')' . PHP_EOL;
        return;
    }

    echo 'FAIL ' . $name . ' (HTTP ' . $status . ', expected ' . $expectedStatus
        . ($expectedReason !== null ? ', reason ' . $expectedReason : '')
        . ', error ' . $error . ')' . PHP_EOL;
    $failures++;
}

function free_port(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        return 8123;
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    $position = strrpos($name, ':');
    return $position === false ? 8123 : (int) substr($name, $position + 1);
}

/**
 * @return array{0: resource, 1: int}
 */
function start_server(
    string $root,
    string $configPath,
    string $dbPath,
    string $logPath
): array {
    $port = free_port();
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['RLL_ENV'] = 'test';
    $environment['RLL_CONFIG_PATH'] = $configPath;
    $environment['RLL_DB_PATH'] = $dbPath;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, $root . '/router.php'],
        $descriptors,
        $pipes,
        $root,
        $environment,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Gagal menjalankan server test.');
    }

    fclose($pipes[0]);

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            return [$process, $port];
        }
        usleep(50000);
    }

    stop_server($process);
    throw new RuntimeException('Server test tidak siap.');
}

/**
 * @param resource $process
 */
function stop_server($process): void
{
    if (!is_resource($process)) {
        return;
    }

    $status = proc_get_status($process);
    if ($status['running'] ?? false) {
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
        } else {
            proc_terminate($process, 9);
        }
    }

    @proc_close($process);
}

/**
 * @return array{0: int, 1: string, 2: string}
 */
function post_json(string $url, array $payload): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return [0, '', 'curl_init'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [$status, $body === false ? '' : (string) $body, $error];
}

function write_config(string $path, array $config): void
{
    file_put_contents(
        $path,
        "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n"
    );
}

function payload_for(string $orderId, string $secret, string $transactionStatus = 'settlement'): array
{
    $statusCode = '200';
    $grossAmount = '271000.00';

    return [
        'order_id' => $orderId,
        'status_code' => $statusCode,
        'gross_amount' => $grossAmount,
        'signature_key' => hash('sha512', $orderId . $statusCode . $grossAmount . $secret),
        'transaction_status' => $transactionStatus,
        'payment_type' => 'qris',
    ];
}

function remove_tree(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($directory);
}

$pendingOrderId = 'RLL-20261110-1e420c';
$paidOrderId = 'RLL-20261110-000001';
$cancelledOrderId = 'RLL-20261110-3c3c3c';
$expiredOrderId = 'RLL-20261110-4d4d4d';
$unknownOrderId = 'RLL-20261110-abcdef';
$dbPath = $tempDir . '/orders.sqlite';
$normalConfigPath = $tempDir . '/config-normal.php';
$emptyConfigPath = $tempDir . '/config-empty-secret.php';
$serverLog = $tempDir . '/server.log';

$baseConfig = [
    'server_key' => 'SB-Mid-server-http-test',
    'signature_secret_key' => 'http-test-signature-secret',
    'webhook_status_fallback' => false,
    'is_production' => false,
];

write_config($normalConfigPath, $baseConfig);

$emptyConfig = $baseConfig;
$emptyConfig['signature_secret_key'] = '';
write_config($emptyConfigPath, $emptyConfig);

try {
    $seed = new PDO('sqlite:' . $dbPath);
    $seed->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $seed->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $seed->exec('PRAGMA foreign_keys = ON');
    migrate_db($seed);

    $items = [[
        'id' => '1',
        'name' => 'Test Item',
        'price' => 271000,
        'quantity' => 1,
    ]];

    save_order($seed, [
        'order_id' => $pendingOrderId,
        'customer_name' => 'Test Pending',
        'customer_email' => 'pending@example.test',
        'customer_phone' => '0800000000',
        'gross_amount' => 271000,
    ], $items);

    save_order($seed, [
        'order_id' => $paidOrderId,
        'customer_name' => 'Test Paid',
        'customer_email' => 'paid@example.test',
        'customer_phone' => '0800000001',
        'gross_amount' => 271000,
    ], $items);

    update_order_status($seed, $paidOrderId, 'paid');

    save_order($seed, [
        'order_id' => $cancelledOrderId,
        'customer_name' => 'Test Cancelled',
        'customer_email' => 'cancelled@example.test',
        'customer_phone' => '0800000002',
        'gross_amount' => 271000,
    ], $items);

    update_order_status($seed, $cancelledOrderId, 'cancelled');

    save_order($seed, [
        'order_id' => $expiredOrderId,
        'customer_name' => 'Test Expired',
        'customer_email' => 'expired@example.test',
        'customer_phone' => '0800000003',
        'gross_amount' => 271000,
    ], $items);

    update_order_status($seed, $expiredOrderId, 'expired');

    $seed = null;

    [$process, $port] = start_server($root, $normalConfigPath, $dbPath, $serverLog);
    $servers[] = $process;

    $url = 'http://127.0.0.1:' . $port . '/php/notification.php';
    $secret = (string) $baseConfig['signature_secret_key'];
    $serverKey = (string) $baseConfig['server_key'];

    assert_http(
        'signature valid diterima',
        200,
        post_json($url, payload_for($pendingOrderId, $secret)),
        $failures
    );

    assert_http(
        'signature dari server_key ditolak',
        403,
        post_json($url, payload_for($pendingOrderId, $serverKey)),
        $failures
    );

    assert_http(
        'signature server_key untuk order tidak ada juga 403',
        403,
        post_json($url, payload_for($unknownOrderId, $serverKey)),
        $failures
    );

    assert_http(
        'signature acak untuk order tidak ada juga 403',
        403,
        post_json($url, payload_for($unknownOrderId, 'wrong-secret')),
        $failures
    );

    assert_http(
        'signature valid untuk order tidak ada diabaikan',
        200,
        post_json($url, payload_for($unknownOrderId, $secret)),
        $failures,
        'order_not_found'
    );

    assert_http(
        'signature valid untuk order bukan pending diabaikan',
        200,
        post_json($url, payload_for($paidOrderId, $secret)),
        $failures,
        'not_pending'
    );

    assert_http(
        'settlement terverifikasi mempromosikan order cancelled',
        200,
        post_json($url, payload_for($cancelledOrderId, $secret)),
        $failures
    );

    assert_http(
        'event expire tidak menurunkan order expired',
        200,
        post_json($url, payload_for($expiredOrderId, $secret, 'expire')),
        $failures
    );

    $check = new PDO('sqlite:' . $dbPath);
    $check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $check->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $cancelledRow = find_order($check, $cancelledOrderId);
    $expiredRow = find_order($check, $expiredOrderId);

    if ($cancelledRow !== null && $cancelledRow['status'] === 'paid') {
        echo 'PASS order cancelled berubah menjadi paid' . PHP_EOL;
    } else {
        echo 'FAIL order cancelled tidak berubah menjadi paid' . PHP_EOL;
        $failures++;
    }

    if ($expiredRow !== null && $expiredRow['status'] === 'expired') {
        echo 'PASS order expired tetap expired setelah event expire' . PHP_EOL;
    } else {
        echo 'FAIL order expired berubah status setelah event expire' . PHP_EOL;
        $failures++;
    }
    $check = null;

    assert_http(
        'settlement terverifikasi mempromosikan order expired',
        200,
        post_json($url, payload_for($expiredOrderId, $secret)),
        $failures
    );

    $check = new PDO('sqlite:' . $dbPath);
    $check->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $check->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $expiredRow = find_order($check, $expiredOrderId);

    if ($expiredRow !== null && $expiredRow['status'] === 'paid') {
        echo 'PASS order expired berubah menjadi paid' . PHP_EOL;
    } else {
        echo 'FAIL order expired tidak berubah menjadi paid' . PHP_EOL;
        $failures++;
    }
    $check = null;

    $missingOrder = payload_for($pendingOrderId, $secret);
    unset($missingOrder['order_id']);
    assert_http(
        'order_id tidak ada ditolak 400',
        400,
        post_json($url, $missingOrder),
        $failures
    );

    [$emptyProcess, $emptyPort] = start_server($root, $emptyConfigPath, $dbPath, $serverLog);
    $servers[] = $emptyProcess;

    $emptyUrl = 'http://127.0.0.1:' . $emptyPort . '/php/notification.php';
    assert_http(
        'signature_secret_key kosong menghasilkan 500 generik',
        500,
        post_json($emptyUrl, payload_for($pendingOrderId, 'anything')),
        $failures
    );
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
    ? 'HTTP WEBHOOK: OK' . PHP_EOL
    : 'HTTP WEBHOOK: ' . $failures . ' gagal' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
