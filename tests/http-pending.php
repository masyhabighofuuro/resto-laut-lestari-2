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
$tempDir = sys_get_temp_dir() . '/rll-http-pending-' . bin2hex(random_bytes(4));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    fwrite(STDERR, "FAIL tidak bisa membuat direktori sementara.\n");
    exit(1);
}

$failures = 0;
$servers = [];
$dbPath = $tempDir . '/orders.sqlite';
$configPath = $tempDir . '/config.php';
$fakeRequestLog = $tempDir . '/fake-requests.log';

try {
    $db = open_test_db($dbPath);

    $tokenPaid = str_repeat('b', 64);
    $tokenLimit = str_repeat('c', 64);
    $tokenExpired = str_repeat('d', 64);
    $tokenRefreshPaid = str_repeat('f', 64);
    $tokenThrottle = str_repeat('9', 64);
    $tokenNotFoundBody = str_repeat('7', 64);
    $future = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);
    $past = gmdate('Y-m-d\TH:i:s\Z', time() - 60);

    seed_order($db, 'RLL-20261110-100001', $tokenPaid, 'paid', $future);
    seed_order($db, 'RLL-20261110-200001', $tokenLimit, 'pending', $future);
    seed_order($db, 'RLL-20261110-200002', $tokenLimit, 'pending', $future);
    seed_order($db, 'RLL-20261110-200003', $tokenLimit, 'pending', $future);
    seed_order($db, 'RLL-20261110-300001', $tokenExpired, 'pending', $past);
    seed_order($db, 'RLL-20261110-000002', $tokenRefreshPaid, 'pending', $future);
    seed_order($db, 'RLL-20261110-000014', $tokenNotFoundBody, 'pending', $future);
    seed_order($db, 'RLL-20261110-400001', $tokenThrottle, 'pending', $future);
    $db = null;

    write_config($configPath, [
        'server_key' => 'SB-Mid-server-pending-test',
        'signature_secret_key' => 'pending-test-secret',
        'webhook_status_fallback' => false,
        'is_production' => false,
        'order_expiry_minutes' => 60,
        'max_pending_per_device' => 3,
        'rate_limit_window' => 600,
    ]);

    [$fakeProcess, $fakePort] = start_php_server(
        $root,
        $root . '/tests/fake-midtrans.php',
        $tempDir . '/fake-server.log',
        ['RLL_FAKE_LOG' => $fakeRequestLog]
    );
    $servers[] = $fakeProcess;
    $fakeBase = 'http://127.0.0.1:' . $fakePort;

    [$appProcess, $appPort] = start_php_server(
        $root,
        $root . '/router.php',
        $tempDir . '/app-server.log',
        [
            'RLL_ENV' => 'test',
            'RLL_CONFIG_PATH' => $configPath,
            'RLL_DB_PATH' => $dbPath,
            'RLL_MIDTRANS_SNAP_BASE_URL' => $fakeBase,
            'RLL_MIDTRANS_API_BASE_URL' => $fakeBase,
        ]
    );
    $servers[] = $appProcess;
    $base = 'http://127.0.0.1:' . $appPort;

    $customer = [
        'name' => 'Pending Test',
        'email' => 'pending@example.test',
        'phone' => '081234567890',
    ];
    $payload = [
        'customer' => $customer,
        'items' => [['id' => 1, 'qty' => 1]],
    ];

    $first = http_request('POST', $base . '/php/place-order.php', $payload);
    assert_http_status('checkout pertama berhasil', 200, $first, $failures);

    $setCookies = header_values($first[2], 'Set-Cookie');
    $setCookie = $setCookies[0] ?? null;
    $tokenA = cookie_value($setCookie, DEVICE_COOKIE_NAME);
    assert_true('cookie device dibuat', is_string($tokenA) && preg_match('/^[a-f0-9]{64}$/', $tokenA) === 1, $failures);
    assert_true(
        'cookie HttpOnly, SameSite Lax, Path /, tanpa Secure',
        is_string($setCookie)
            && stripos($setCookie, 'HttpOnly') !== false
            && stripos($setCookie, 'SameSite=Lax') !== false
            && stripos($setCookie, 'Path=/') !== false
            && stripos($setCookie, 'Secure') === false,
        $failures
    );

    $cookiesA = is_string($tokenA) ? [DEVICE_COOKIE_NAME => $tokenA] : [];
    $firstData = json_decode_body($first[1]);
    $orderA = (string) ($firstData['order_id'] ?? '');
    assert_true('respons checkout memuat expires_at', isset($firstData['expires_at']), $failures);

    $pendingA = http_request('GET', $base . '/php/orders-pending.php', null, [], $cookiesA);
    assert_http_status('daftar pending perangkat A', 200, $pendingA, $failures);
    $pendingAData = json_decode_body($pendingA[1]);
    assert_true('perangkat A melihat satu pesanan', (int) ($pendingAData['count'] ?? -1) === 1, $failures);
    assert_true('data pesanan A sesuai', ($pendingAData['orders'][0]['order_id'] ?? '') === $orderA, $failures);
    assert_true(
        'respons tidak memuat email/telepon',
        strpos($pendingA[1], 'pending@example.test') === false
            && strpos($pendingA[1], '081234567890') === false,
        $failures
    );

    $noCookie = http_request('GET', $base . '/php/orders-pending.php');
    assert_http_status('tanpa cookie tetap 200', 200, $noCookie, $failures);
    assert_true(
        'tanpa cookie daftar kosong dan tidak membuat cookie',
        (int) (json_decode_body($noCookie[1])['count'] ?? -1) === 0
            && header_values($noCookie[2], 'Set-Cookie') === [],
        $failures
    );

    $tokenB = str_repeat('e', 64);
    $second = http_request(
        'POST',
        $base . '/php/place-order.php',
        $payload,
        [],
        [DEVICE_COOKIE_NAME => $tokenB]
    );
    assert_http_status('checkout perangkat B berhasil', 200, $second, $failures);
    $orderB = (string) (json_decode_body($second[1])['order_id'] ?? '');
    assert_true('order A dan B berbeda', $orderA !== '' && $orderB !== '' && $orderA !== $orderB, $failures);

    $pendingB = http_request(
        'GET',
        $base . '/php/orders-pending.php',
        null,
        [],
        [DEVICE_COOKIE_NAME => $tokenB]
    );
    $pendingBData = json_decode_body($pendingB[1]);
    assert_true(
        'perangkat B hanya melihat pesanannya sendiri',
        (int) ($pendingBData['count'] ?? -1) === 1
            && ($pendingBData['orders'][0]['order_id'] ?? '') === $orderB,
        $failures
    );

    $paidList = http_request(
        'GET',
        $base . '/php/orders-pending.php',
        null,
        [],
        [DEVICE_COOKIE_NAME => $tokenPaid]
    );
    assert_true(
        'pesanan paid tidak muncul',
        (int) (json_decode_body($paidList[1])['count'] ?? -1) === 0,
        $failures
    );

    $expiredList = http_request(
        'GET',
        $base . '/php/orders-pending.php',
        null,
        [],
        [DEVICE_COOKIE_NAME => $tokenExpired]
    );
    assert_true(
        'pesanan kedaluwarsa tidak muncul',
        (int) (json_decode_body($expiredList[1])['count'] ?? -1) === 0,
        $failures
    );

    $refreshPaid = http_request(
        'GET',
        $base . '/php/orders-pending.php?refresh=1',
        null,
        [],
        [DEVICE_COOKIE_NAME => $tokenRefreshPaid]
    );
    assert_http_status('refresh status berhasil', 200, $refreshPaid, $failures);
    assert_true(
        'pesanan yang sudah settlement hilang dari daftar',
        (int) (json_decode_body($refreshPaid[1])['count'] ?? -1) === 0,
        $failures
    );

    $refreshNotFoundBody = http_request(
        'GET',
        $base . '/php/orders-pending.php?refresh=1',
        null,
        [],
        [DEVICE_COOKIE_NAME => $tokenNotFoundBody]
    );
    assert_http_status('refresh body 404 tetap 200', 200, $refreshNotFoundBody, $failures);
    assert_true(
        'body 404 tidak menyembunyikan order pending',
        (int) (json_decode_body($refreshNotFoundBody[1])['count'] ?? -1) === 1,
        $failures
    );

    $refreshThrottle1 = http_request(
        'GET',
        $base . '/php/orders-pending.php?refresh=1',
        null,
        [],
        [DEVICE_COOKIE_NAME => $tokenThrottle]
    );
    $refreshThrottle2 = http_request(
        'GET',
        $base . '/php/orders-pending.php?refresh=1',
        null,
        [],
        [DEVICE_COOKIE_NAME => $tokenThrottle]
    );
    assert_true(
        'refresh tetap mengembalikan pesanan pending',
        (int) (json_decode_body($refreshThrottle2[1])['count'] ?? -1) === 1,
        $failures
    );

    $dbCheck = new PDO('sqlite:' . $dbPath);
    $dbCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbCheck->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $refreshRow = find_order($dbCheck, 'RLL-20261110-000002');
    assert_true(
        'refresh menandai pesanan settlement sebagai paid',
        $refreshRow !== null && $refreshRow['status'] === 'paid',
        $failures
    );

    $notFoundBodyRow = find_order($dbCheck, 'RLL-20261110-000014');
    assert_true(
        'body status 404 tidak menurunkan status pending',
        $notFoundBodyRow !== null && $notFoundBodyRow['status'] === 'pending',
        $failures
    );

    $expiredRow = find_order($dbCheck, 'RLL-20261110-300001');
    assert_true(
        'lazy expire menandai status expired',
        $expiredRow !== null && $expiredRow['status'] === 'expired',
        $failures
    );

    $stored = find_order($dbCheck, $orderA);
    assert_true(
        'device_hash tersimpan sebagai SHA-256',
        $stored !== null
            && $stored['device_hash'] === device_hash((string) $tokenA)
            && $stored['device_hash'] !== $tokenA,
        $failures
    );

    $expiresAt = $stored === null ? null : strtotime((string) $stored['expires_at']);
    assert_true(
        'expires_at sekitar 60 menit',
        $expiresAt !== false && $expiresAt - time() > 3300 && $expiresAt - time() <= 3660,
        $failures
    );

    $limit = http_request(
        'POST',
        $base . '/php/place-order.php',
        $payload,
        [],
        [DEVICE_COOKIE_NAME => $tokenLimit]
    );
    assert_http_status('pending keempat ditolak', 409, $limit, $failures);
    assert_true(
        'pesan batas pending jelas',
        str_contains((string) (json_decode_body($limit[1])['error'] ?? ''), 'Terlalu banyak'),
        $failures
    );

    $snapRequests = [];
    foreach (file($fakeRequestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry) && ($entry['path'] ?? '') === '/snap/v1/transactions') {
            $snapRequests[] = json_decode((string) ($entry['body'] ?? ''), true);
        }
    }

    $throttleStatusCalls = 0;
    foreach (file($fakeRequestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $entry = json_decode($line, true);
        if (
            is_array($entry)
            && ($entry['path'] ?? '') === '/v2/RLL-20261110-400001/status'
        ) {
            $throttleStatusCalls++;
        }
    }

    assert_true(
        'status API dibatasi 30 detik per order',
        $throttleStatusCalls === 1,
        $failures
    );

    assert_true('ada permintaan Snap tercatat', count($snapRequests) >= 2, $failures);
    $allExpiryValid = true;
    foreach ($snapRequests as $snapPayload) {
        $expiry = is_array($snapPayload) ? ($snapPayload['expiry'] ?? null) : null;
        if (
            !is_array($expiry)
            || ($expiry['duration'] ?? null) !== 60
            || !in_array(($expiry['unit'] ?? null), ['minute', 'minutes'], true)
            || !is_string($expiry['start_time'] ?? null)
            || !str_ends_with((string) $expiry['start_time'], '+0700')
        ) {
            $allExpiryValid = false;
            break;
        }
    }
    assert_true('payload Snap memuat expiry 60 menit', $allExpiryValid, $failures);
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
    ? 'HTTP PENDING: OK' . PHP_EOL
    : 'HTTP PENDING: ' . $failures . ' gagal' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
