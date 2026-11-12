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
$tempDir = sys_get_temp_dir() . '/rll-http-lookup-' . bin2hex(random_bytes(4));
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

    $seedToken = str_repeat('9', 64);
    $future = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
    $past = gmdate('Y-m-d\TH:i:s\Z', time() - 60);

    seed_order(
        $db,
        'RLL-20261110-400001',
        $seedToken,
        'pending',
        $future,
        'lookup@example.test',
        '081234567890'
    );
    seed_order(
        $db,
        'RLL-20261110-400002',
        $seedToken,
        'pending',
        $future,
        'phone@example.test',
        '081298765432'
    );
    seed_order(
        $db,
        'RLL-20261110-400003',
        $seedToken,
        'paid',
        $future,
        'paid-lookup@example.test',
        '081200000003'
    );
    seed_order(
        $db,
        'RLL-20261110-400004',
        $seedToken,
        'pending',
        $past,
        'expired-lookup@example.test',
        '081200000004'
    );
    seed_order(
        $db,
        'RLL-20261110-400005',
        $seedToken,
        'pending',
        $future,
        'rate@example.test',
        '081200000005'
    );
    $db = null;

    write_config($configPath, [
        'server_key' => 'SB-Mid-server-lookup-test',
        'signature_secret_key' => 'lookup-test-secret',
        'webhook_status_fallback' => false,
        'is_production' => false,
        'rate_limit_window' => 600,
        'lookup_rate_limit_device' => 2,
        'lookup_rate_limit_ip' => 100,
    ]);

    [$appProcess, $appPort] = start_php_server(
        $root,
        $root . '/router.php',
        $tempDir . '/app-server.log',
        [
            'RLL_ENV' => 'test',
            'RLL_CONFIG_PATH' => $configPath,
            'RLL_DB_PATH' => $dbPath,
        ]
    );
    $servers[] = $appProcess;
    $base = 'http://127.0.0.1:' . $appPort;

    $csrfHeaders = ['X-Requested-With: XMLHttpRequest'];

    $lookup = static function (
        string $orderId,
        string $contact,
        array $cookies = [],
        array $headers = ['X-Requested-With: XMLHttpRequest']
    ) use ($base): array {
        return http_request(
            'POST',
            $base . '/php/order-lookup.php',
            ['order_id' => $orderId, 'contact' => $contact],
            $headers,
            $cookies
        );
    };

    $missingHeader = $lookup('RLL-20261110-400001', 'lookup@example.test', [], []);
    assert_http_status('lookup tanpa X-Requested-With ditolak', 400, $missingHeader, $failures);

    $invalidId = $lookup('bukan-order', 'lookup@example.test');
    assert_http_status('order_id tidak valid ditolak', 400, $invalidId, $failures);

    $wrong = $lookup(
        'RLL-20261110-400001',
        'salah@example.test',
        [DEVICE_COOKIE_NAME => str_repeat('7', 64)]
    );
    assert_http_status('kontak salah mendapat 404', 404, $wrong, $failures);
    $wrongBody = (string) (json_decode_body($wrong[1])['error'] ?? '');

    $paid = $lookup(
        'RLL-20261110-400003',
        'paid-lookup@example.test',
        [DEVICE_COOKIE_NAME => str_repeat('6', 64)]
    );
    assert_http_status('pesanan paid tidak bisa dicari', 404, $paid, $failures);

    $expired = $lookup(
        'RLL-20261110-400004',
        'expired-lookup@example.test',
        [DEVICE_COOKIE_NAME => str_repeat('5', 64)]
    );
    assert_http_status('pesanan expired tidak bisa dicari', 404, $expired, $failures);

    $paidBody = (string) (json_decode_body($paid[1])['error'] ?? '');
    $expiredBody = (string) (json_decode_body($expired[1])['error'] ?? '');
    assert_true(
        'respons gagal seragam',
        $wrongBody !== ''
            && $wrongBody === $paidBody
            && $wrongBody === $expiredBody,
        $failures
    );

    $emailMatch = $lookup('RLL-20261110-400001', 'LOOKUP@example.test');
    assert_http_status('email cocok menautkan pesanan', 200, $emailMatch, $failures);
    $setCookie = header_values($emailMatch[2], 'Set-Cookie')[0] ?? null;
    $emailToken = cookie_value($setCookie, DEVICE_COOKIE_NAME);
    assert_true(
        'lookup sukses membuat cookie perangkat',
        is_string($emailToken) && preg_match('/^[a-f0-9]{64}$/', $emailToken) === 1,
        $failures
    );

    if (is_string($emailToken)) {
        $list = http_request(
            'GET',
            $base . '/php/orders-pending.php',
            null,
            [],
            [DEVICE_COOKIE_NAME => $emailToken]
        );
        $listData = json_decode_body($list[1]);
        assert_true(
            'pesanan hasil lookup muncul di perangkat',
            (int) ($listData['count'] ?? -1) === 1
                && ($listData['orders'][0]['order_id'] ?? '') === 'RLL-20261110-400001',
            $failures
        );
    }

    $phoneToken = str_repeat('4', 64);
    $phoneMatch = $lookup(
        'RLL-20261110-400002',
        '+62 812-9876-5432',
        [DEVICE_COOKIE_NAME => $phoneToken]
    );
    assert_http_status('nomor telepon cocok menautkan pesanan', 200, $phoneMatch, $failures);

    $phoneList = http_request(
        'GET',
        $base . '/php/orders-pending.php',
        null,
        [],
        [DEVICE_COOKIE_NAME => $phoneToken]
    );
    $phoneListData = json_decode_body($phoneList[1]);
    assert_true(
        'pesanan hasil lookup telepon muncul',
        (int) ($phoneListData['count'] ?? -1) === 1
            && ($phoneListData['orders'][0]['order_id'] ?? '') === 'RLL-20261110-400002',
        $failures
    );

    $rateToken = str_repeat('3', 64);
    $rateCookies = [DEVICE_COOKIE_NAME => $rateToken];
    $rateFirst = $lookup('RLL-20261110-400005', 'salah@example.test', $rateCookies);
    $rateSecond = $lookup('RLL-20261110-400005', 'salah@example.test', $rateCookies);
    $rateThird = $lookup('RLL-20261110-400005', 'salah@example.test', $rateCookies);
    assert_http_status('percobaan lookup pertama 404', 404, $rateFirst, $failures);
    assert_http_status('percobaan lookup kedua 404', 404, $rateSecond, $failures);
    assert_http_status('percobaan lookup ketiga kena rate limit', 429, $rateThird, $failures);
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
    ? 'HTTP LOOKUP: OK' . PHP_EOL
    : 'HTTP LOOKUP: ' . $failures . ' gagal' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
