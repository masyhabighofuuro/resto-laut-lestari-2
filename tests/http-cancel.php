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
$tempDir = sys_get_temp_dir() . '/rll-http-cancel-' . bin2hex(random_bytes(4));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    fwrite(STDERR, "FAIL tidak bisa membuat direktori sementara.\n");
    exit(1);
}

$failures = 0;
$servers = [];
$dbPath = $tempDir . '/orders.sqlite';
$configPath = $tempDir . '/config.php';
$fakeRequestLog = $tempDir . '/fake-requests.log';
$cancelLog = $root . '/php/cancel-order.log';
$cancelLogExisted = is_file($cancelLog);

try {
    $db = open_test_db($dbPath);

    $tokenOwner = str_repeat('1', 64);
    $tokenOther = str_repeat('2', 64);
    $future = gmdate('Y-m-d\TH:i:s\Z', time() + 3600);

    $orders = [
        'RLL-20261110-000001' => ['pending', $tokenOwner],
        'RLL-20261110-000002' => ['pending', $tokenOwner],
        'RLL-20261110-000003' => ['pending', $tokenOwner],
        'RLL-20261110-000004' => ['pending', $tokenOwner],
        'RLL-20261110-000005' => ['pending', $tokenOwner],
        'RLL-20261110-000006' => ['pending', $tokenOwner],
        'RLL-20261110-000007' => ['pending', $tokenOwner],
        'RLL-20261110-000008' => ['pending', $tokenOwner],
        'RLL-20261110-000009' => ['pending', $tokenOwner],
        'RLL-20261110-000010' => ['pending', $tokenOwner],
        'RLL-20261110-000011' => ['pending', $tokenOwner],
        'RLL-20261110-000012' => ['pending', $tokenOwner],
        'RLL-20261110-000013' => ['pending', $tokenOwner],
        'RLL-20261110-000014' => ['pending', $tokenOwner],
        'RLL-20261110-000015' => ['pending', $tokenOwner],
        'RLL-20261110-000016' => ['pending', $tokenOwner],
    ];

    foreach ($orders as $orderId => [$status, $token]) {
        seed_order($db, $orderId, $token, $status, $future);
    }

    set_snap_token($db, 'RLL-20261110-000011', 'fake-snap-token-authfail');
    set_snap_token($db, 'RLL-20261110-000012', 'fake-snap-token-progress');
    set_snap_token($db, 'RLL-20261110-000013', 'fake-snap-token-notfound');
    $db = null;

    write_config($configPath, [
        'server_key' => 'SB-Mid-server-cancel-test',
        'signature_secret_key' => 'cancel-test-secret',
        'webhook_status_fallback' => false,
        'is_production' => false,
        'status_api_timeout' => 3,
        'cancel_api_timeout' => 3,
        'rate_limit_window' => 600,
        'cancel_rate_limit_device' => 100,
        'cancel_rate_limit_ip' => 100,
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

    $cookiesOwner = [DEVICE_COOKIE_NAME => $tokenOwner];
    $cookiesOther = [DEVICE_COOKIE_NAME => $tokenOther];
    $csrfHeaders = ['X-Requested-With: XMLHttpRequest'];

    $cancel = static function (
        string $orderId,
        string $reasonCode = 'wrong_items',
        string $reasonText = '',
        array $cookies = [],
        array $headers = ['X-Requested-With: XMLHttpRequest']
    ) use ($base): array {
        return http_request(
            'POST',
            $base . '/php/cancel-order.php',
            [
                'order_id' => $orderId,
                'reason_code' => $reasonCode,
                'reason_text' => $reasonText,
            ],
            $headers,
            $cookies
        );
    };

    $response = $cancel('RLL-20261110-000001', 'wrong_items', '', $cookiesOwner);
    assert_http_status('pemilik membatalkan pesanan', 200, $response, $failures);

    $dbCheck = new PDO('sqlite:' . $dbPath);
    $dbCheck->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbCheck->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $cancelled = find_order($dbCheck, 'RLL-20261110-000001');
    assert_true(
        'status, alasan, dan pelaku tersimpan',
        $cancelled !== null
            && $cancelled['status'] === 'cancelled'
            && $cancelled['cancel_reason_code'] === 'wrong_items'
            && $cancelled['cancelled_by'] === 'customer'
            && $cancelled['cancelled_at'] !== null,
        $failures
    );

    $cancelledAgain = $cancel('RLL-20261110-000001', 'wrong_items', '', $cookiesOwner);
    assert_http_status('pembatalan ulang ditolak spesifik', 409, $cancelledAgain, $failures);
    assert_true(
        'pesan lokal cancelled spesifik',
        (json_decode_body($cancelledAgain[1])['error'] ?? '') === 'Pesanan sudah dibatalkan.',
        $failures
    );

    $missingHeader = $cancel('RLL-20261110-000009', 'wrong_items', '', $cookiesOwner, []);
    assert_http_status('tanpa X-Requested-With ditolak', 400, $missingHeader, $failures);

    $invalidReason = $cancel('RLL-20261110-000010', 'bogus', '', $cookiesOwner);
    assert_http_status('alasan tidak valid ditolak', 400, $invalidReason, $failures);

    $shortOther = $cancel('RLL-20261110-000009', 'other', 'abc', $cookiesOwner);
    assert_http_status('alasan lainnya terlalu pendek ditolak', 400, $shortOther, $failures);

    $emptyOther = $cancel('RLL-20261110-000009', 'other', '', $cookiesOwner);
    assert_http_status('alasan lainnya kosong ditolak', 400, $emptyOther, $failures);

    $notOwner = $cancel('RLL-20261110-000007', 'wrong_items', '', $cookiesOther);
    assert_http_status('bukan pemilik mendapat 404', 404, $notOwner, $failures);
    $stillPending = find_order($dbCheck, 'RLL-20261110-000007');
    assert_true(
        'pesanan bukan pemilik tidak berubah',
        $stillPending !== null && $stillPending['status'] === 'pending',
        $failures
    );

    $paid = $cancel('RLL-20261110-000002', 'wrong_items', '', $cookiesOwner);
    assert_http_status('pesanan sudah dibayar ditolak', 409, $paid, $failures);
    $paidRow = find_order($dbCheck, 'RLL-20261110-000002');
    assert_true(
        'status paid dipertahankan',
        $paidRow !== null && $paidRow['status'] === 'paid',
        $failures
    );

    $snap404 = $cancel('RLL-20261110-000003', 'wrong_items', '', $cookiesOwner);
    assert_http_status('Cancel API 404 tetap dibatalkan lokal', 200, $snap404, $failures);
    $snapRow = find_order($dbCheck, 'RLL-20261110-000003');
    assert_true(
        'order Snap 404 menjadi cancelled',
        $snapRow !== null && $snapRow['status'] === 'cancelled',
        $failures
    );

    $auth = $cancel('RLL-20261110-000004', 'wrong_items', '', $cookiesOwner);
    assert_http_status('izin Core Cancel kurang dibatalkan lewat Snap session', 200, $auth, $failures);
    $authRow = find_order($dbCheck, 'RLL-20261110-000004');
    assert_true(
        'fallback Snap session menandai cancelled',
        $authRow !== null && $authRow['status'] === 'cancelled',
        $failures
    );

    $snapAuth = $cancel('RLL-20261110-000011', 'wrong_items', '', $cookiesOwner);
    assert_http_status('fallback Snap session ditolak tetap 500', 500, $snapAuth, $failures);
    $snapAuthRow = find_order($dbCheck, 'RLL-20261110-000011');
    assert_true(
        'fallback auth tidak mengubah status',
        $snapAuthRow !== null && $snapAuthRow['status'] === 'pending',
        $failures
    );

    $snapProgress = $cancel('RLL-20261110-000012', 'wrong_items', '', $cookiesOwner);
    assert_http_status('pembayaran sedang diproses menjadi 409', 409, $snapProgress, $failures);
    $snapProgressRow = find_order($dbCheck, 'RLL-20261110-000012');
    assert_true(
        'in progress tidak mengubah status',
        $snapProgressRow !== null && $snapProgressRow['status'] === 'pending',
        $failures
    );

    $snapNotFound = $cancel('RLL-20261110-000013', 'wrong_items', '', $cookiesOwner);
    assert_http_status('token Snap tidak ditemukan dianggap batal', 200, $snapNotFound, $failures);
    $snapNotFoundRow = find_order($dbCheck, 'RLL-20261110-000013');
    assert_true(
        'token tidak ditemukan menjadi cancelled',
        $snapNotFoundRow !== null && $snapNotFoundRow['status'] === 'cancelled',
        $failures
    );

    $bodyNotFound = $cancel('RLL-20261110-000014', 'wrong_items', '', $cookiesOwner);
    assert_http_status('body status 404 tidak menjadi mismatch palsu', 200, $bodyNotFound, $failures);
    $bodyNotFoundRow = find_order($dbCheck, 'RLL-20261110-000014');
    assert_true(
        'status body 404 tetap dapat dibatalkan lokal',
        $bodyNotFoundRow !== null && $bodyNotFoundRow['status'] === 'cancelled',
        $failures
    );

    $remoteExpired = $cancel('RLL-20261110-000015', 'wrong_items', '', $cookiesOwner);
    assert_http_status('status remote expired ditolak spesifik', 409, $remoteExpired, $failures);
    $remoteExpiredData = json_decode_body($remoteExpired[1]);
    assert_true(
        'pesan remote expired spesifik',
        ($remoteExpiredData['error'] ?? '') === 'Pesanan sudah kedaluwarsa di Midtrans.',
        $failures
    );
    $remoteExpiredRow = find_order($dbCheck, 'RLL-20261110-000015');
    assert_true(
        'status remote expired disinkronkan ke lokal',
        $remoteExpiredRow !== null && $remoteExpiredRow['status'] === 'expired',
        $failures
    );

    $trueMismatch = $cancel('RLL-20261110-000016', 'wrong_items', '', $cookiesOwner);
    assert_http_status('mismatch nominal asli tetap 409', 409, $trueMismatch, $failures);
    $trueMismatchData = json_decode_body($trueMismatch[1]);
    assert_true(
        'pesan mismatch spesifik',
        ($trueMismatchData['error'] ?? '') === 'Data pesanan tidak cocok dengan Midtrans. Silakan hubungi penjual.',
        $failures
    );
    $trueMismatchRow = find_order($dbCheck, 'RLL-20261110-000016');
    assert_true(
        'mismatch tidak mengubah status',
        $trueMismatchRow !== null && $trueMismatchRow['status'] === 'pending',
        $failures
    );

    $conflict = $cancel('RLL-20261110-000005', 'wrong_items', '', $cookiesOwner);
    assert_http_status('konflik pembatalan ditolak', 409, $conflict, $failures);

    $serverError = $cancel('RLL-20261110-000006', 'wrong_items', '', $cookiesOwner);
    assert_http_status('Cancel API 500 menjadi 503', 503, $serverError, $failures);
    $serverRow = find_order($dbCheck, 'RLL-20261110-000006');
    assert_true(
        'order tetap pending saat Cancel API 500',
        $serverRow !== null && $serverRow['status'] === 'pending',
        $failures
    );

    $xssText = '<img src=x onerror=alert(1)>';
    $xss = $cancel('RLL-20261110-000008', 'other', $xssText, $cookiesOwner);
    assert_http_status('alasan XSS diterima sebagai teks', 200, $xss, $failures);
    $xssRow = find_order($dbCheck, 'RLL-20261110-000008');
    assert_true(
        'teks alasan tersimpan apa adanya',
        $xssRow !== null && $xssRow['cancel_reason_text'] === $xssText,
        $failures
    );
    assert_true(
        'respons JSON tidak mengirim tag HTML mentah',
        strpos($xss[1], '<img') === false,
        $failures
    );

    $logLines = file($fakeRequestLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $statusCalls = [];
    $cancelCalls = [];
    $snapCancelCalls = [];
    foreach ($logLines as $line) {
        $entry = json_decode($line, true);
        if (!is_array($entry)) {
            continue;
        }
        $path = (string) ($entry['path'] ?? '');
        if (preg_match('#^/v2/([^/]+)/status$#', $path, $matches) === 1) {
            $statusCalls[rawurldecode($matches[1])] = true;
        }
        if (preg_match('#^/v2/([^/]+)/cancel$#', $path, $matches) === 1) {
            $cancelCalls[rawurldecode($matches[1])] = true;
        }
        if (preg_match('#^/snap/v1/transactions/([^/]+)/cancel$#', $path, $matches) === 1) {
            $snapCancelCalls[rawurldecode($matches[1])] = true;
        }
    }

    assert_true(
        'status dicek sebelum pembatalan',
        isset($statusCalls['RLL-20261110-000001']),
        $failures
    );
    assert_true(
        'Cancel API dipanggil untuk order pending',
        isset($cancelCalls['RLL-20261110-000001']),
        $failures
    );
    assert_true(
        'Cancel API tidak dipanggil untuk order paid',
        isset($statusCalls['RLL-20261110-000002'])
            && !isset($cancelCalls['RLL-20261110-000002']),
        $failures
    );

    assert_true(
        'fallback Snap session dipanggil untuk izin Core kurang',
        isset($snapCancelCalls['fake-snap-token'])
            && isset($snapCancelCalls['fake-snap-token-authfail']),
        $failures
    );

    assert_true(
        'log izin Cancel ditulis tanpa secret',
        is_file($cancelLog)
            && str_contains((string) file_get_contents($cancelLog), 'Cancel transaction: Write'),
        $failures
    );

    assert_true(
        'log pembatalan tidak memuat snap token',
        is_file($cancelLog)
            && !str_contains((string) file_get_contents($cancelLog), 'fake-snap-token'),
        $failures
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    $failures++;
} finally {
    foreach ($servers as $server) {
        stop_server($server);
    }
    if (!$cancelLogExisted) {
        @unlink($cancelLog);
    }
    remove_tree($tempDir);
}

echo $failures === 0
    ? 'HTTP CANCEL: OK' . PHP_EOL
    : 'HTTP CANCEL: ' . $failures . ' gagal' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
