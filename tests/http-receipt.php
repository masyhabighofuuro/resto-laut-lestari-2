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
$tempDir = sys_get_temp_dir() . '/rll-http-receipt-' . bin2hex(random_bytes(4));
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

    $tokenOwner = str_repeat('a', 64);
    $tokenOther = str_repeat('b', 64);
    $tokenLegacy = str_repeat('c', 64);
    $tokenRateA = str_repeat('d', 64);
    $tokenRateB = str_repeat('e', 64);

    $paidOwner = 'RLL-20261111-500001';
    $pendingOwner = 'RLL-20261111-500002';
    $paidOther = 'RLL-20261111-500003';
    $legacyPaid = 'RLL-20261111-500004';
    $rateOrderA = 'RLL-20261111-500005';
    $rateOrderB = 'RLL-20261111-500006';

    seed_order(
        $db,
        $paidOwner,
        $tokenOwner,
        'paid',
        null,
        'Budi@Example.Test',
        '081234567890',
        'secret-snap-token',
        'bank_transfer'
    );
    seed_order($db, $pendingOwner, $tokenOwner, 'pending', null, 'pending@example.test', '081200000002');
    seed_order(
        $db,
        $paidOther,
        $tokenOther,
        'paid',
        null,
        'other@example.test',
        '081298765432',
        'other-snap-token',
        'gopay'
    );
    seed_order(
        $db,
        $legacyPaid,
        $tokenLegacy,
        'paid',
        null,
        'legacy@example.test',
        '081200000004',
        null,
        null
    );
    $db->prepare('UPDATE orders SET paid_at = NULL WHERE order_id = :order_id')
        ->execute([':order_id' => $legacyPaid]);
    seed_order($db, $rateOrderA, $tokenRateA, 'paid', null, 'rate-a@example.test', '081200000005', null, 'qris');
    seed_order($db, $rateOrderB, $tokenRateB, 'paid', null, 'rate-b@example.test', '081200000006', null, 'qris');
    $db = null;

    write_config($configPath, [
        'server_key' => 'SB-Mid-server-receipt-test',
        'signature_secret_key' => 'receipt-test-secret',
        'webhook_status_fallback' => false,
        'is_production' => false,
        'rate_limit_window' => 600,
        'receipt_rate_limit_actor' => 100,
        'receipt_rate_limit_order' => 100,
        'business_name' => 'Resto Laut Lestari',
        'business_address' => 'Jl. Contoh 1',
        'business_phone' => '021-000000',
        'business_email' => 'halo@example.test',
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

    $get = static function (string $query, array $cookies = []) use ($base): array {
        return http_request('GET', $base . '/php/receipt.php' . $query, null, [], $cookies);
    };
    $post = static function (
        array $fields,
        array $cookies = []
    ) use ($base): array {
        return http_request(
            'POST',
            $base . '/php/receipt.php',
            $fields,
            [],
            $cookies,
            true
        );
    };

    $securityHeaders = [
        'Content-Type',
        'Cache-Control',
        'Pragma',
        'Referrer-Policy',
        'X-Robots-Tag',
        'X-Content-Type-Options',
        'X-Frame-Options',
    ];
    $headerSnapshot = static function (array $headers) use ($securityHeaders): array {
        $snapshot = [];
        foreach ($securityHeaders as $name) {
            $snapshot[$name] = header_values($headers, $name);
        }
        return $snapshot;
    };

    $ownerGet = $get('?order_id=' . urlencode($paidOwner), ['rll_device' => $tokenOwner]);
    assert_http_status('pemilik melihat struk paid', 200, $ownerGet, $failures);
    $ownerBody = $ownerGet[1];
    assert_true(
        'struk memuat data pesanan dan status lunas',
        str_contains($ownerBody, $paidOwner)
            && str_contains($ownerBody, 'LUNAS')
            && str_contains($ownerBody, 'Transfer Bank')
            && str_contains($ownerBody, 'Bukan faktur pajak.'),
        $failures
    );
    assert_true(
        'kontak pemilik disamarkan dan tanggal bayar WIB',
        str_contains($ownerBody, 'B***@Example.Test')
            && str_contains($ownerBody, '0812******90')
            && str_contains($ownerBody, 'WIB')
            && !str_contains($ownerBody, 'Budi@Example.Test'),
        $failures
    );
    assert_true(
        'server key dan snap token tidak tampil',
        !str_contains($ownerBody, 'secret-snap-token')
            && !str_contains($ownerBody, 'SB-Mid-server-receipt-test'),
        $failures
    );
    $ownerHeaders = $headerSnapshot($ownerGet[2]);
    assert_true(
        'header privasi struk lengkap',
        in_array('no-store, private, max-age=0', $ownerHeaders['Cache-Control'], true)
            && in_array('no-cache', $ownerHeaders['Pragma'], true)
            && in_array('no-referrer', $ownerHeaders['Referrer-Policy'], true)
            && in_array('noindex, nofollow, noarchive', $ownerHeaders['X-Robots-Tag'], true)
            && in_array('nosniff', $ownerHeaders['X-Content-Type-Options'], true)
            && in_array('DENY', $ownerHeaders['X-Frame-Options'], true),
        $failures
    );

    $legacyGet = $get('?order_id=' . urlencode($legacyPaid), ['rll_device' => $tokenLegacy]);
    assert_http_status('order paid legacy tetap bisa melihat struk', 200, $legacyGet, $failures);
    assert_true(
        'paid_at legacy NULL ditampilkan sebagai tanda hubung',
        str_contains($legacyGet[1], 'Tanggal Bayar</span><strong>-</strong>'),
        $failures
    );

    $checkPaid = $get('?order_id=' . urlencode($paidOwner) . '&check=1', ['rll_device' => $tokenOwner]);
    assert_http_status('cek struk pemilik paid', 200, $checkPaid, $failures);
    assert_true(
        'cek struk pemilik paid eligible',
        (json_decode_body($checkPaid[1])['eligible'] ?? false) === true,
        $failures
    );
    $checkPending = $get(
        '?order_id=' . urlencode($pendingOwner) . '&check=1',
        ['rll_device' => $tokenOwner]
    );
    $checkOther = $get(
        '?order_id=' . urlencode($paidOther) . '&check=1',
        ['rll_device' => $tokenOwner]
    );
    assert_true(
        'cek struk pending dan bukan pemilik tidak eligible',
        (json_decode_body($checkPending[1])['eligible'] ?? true) === false
            && (json_decode_body($checkOther[1])['eligible'] ?? true) === false,
        $failures
    );

    $failuresList = [];
    $failuresList[] = $get('?order_id=bukan-order', ['rll_device' => $tokenOwner]);
    $failuresList[] = $get('?order_id=RLL-20261111-999999', ['rll_device' => $tokenOwner]);
    $failuresList[] = $get('?order_id=' . urlencode($pendingOwner), ['rll_device' => $tokenOwner]);
    $failuresList[] = $get('?order_id=' . urlencode($paidOther), ['rll_device' => $tokenOwner]);
    $failuresList[] = $post(
        ['order_id' => $paidOwner, 'contact' => 'salah@example.test'],
        ['rll_device' => $tokenOwner]
    );
    $failuresList[] = $post(
        ['order_id' => 'RLL-20261111-999999', 'contact' => 'budi@example.test'],
        ['rll_device' => $tokenOwner]
    );
    $failuresList[] = $post(
        ['order_id' => $pendingOwner, 'contact' => 'pending@example.test'],
        ['rll_device' => $tokenOwner]
    );
    $failuresList[] = $get(
        '?order_id=' . urlencode($paidOther) . '&contact=other%40example.test',
        ['rll_device' => $tokenOther]
    );

    foreach ($failuresList as $index => $failureResponse) {
        assert_http_status('kegagalan struk #' . ($index + 1) . ' 404', 404, $failureResponse, $failures);
    }

    $firstFailure = $failuresList[0];
    $uniformBody = $firstFailure[1];
    $uniformHeaders = $headerSnapshot($firstFailure[2]);
    $uniform = true;
    foreach ($failuresList as $failureResponse) {
        if ($failureResponse[1] !== $uniformBody
            || $headerSnapshot($failureResponse[2]) !== $uniformHeaders
        ) {
            $uniform = false;
        }
    }
    assert_true(
        '404 struk identik untuk semua kegagalan',
        $uniform && !str_contains($uniformBody, 'RLL-'),
        $failures
    );

    $postEmail = $post(
        ['order_id' => $paidOther, 'contact' => '  OTHER@EXAMPLE.TEST '],
        ['rll_device' => $tokenOwner]
    );
    assert_http_status('POST struk dengan email beda huruf', 200, $postEmail, $failures);
    assert_true(
        'respons POST tidak memuat kontak penuh',
        str_contains($postEmail[1], 'o***@example.test')
            && !str_contains($postEmail[1], 'other@example.test'),
        $failures
    );

    $postPhone = $post(
        ['order_id' => $paidOther, 'contact' => '+62 812-9876-5432'],
        ['rll_device' => $tokenOwner]
    );
    assert_http_status('POST struk dengan telepon format +62', 200, $postPhone, $failures);

    $dbCheck = open_test_db($dbPath);
    $afterLookup = find_order($dbCheck, $paidOther);
    assert_true(
        'pencarian struk tidak mengubah device_hash',
        $afterLookup !== null
            && $afterLookup['device_hash'] === device_hash($tokenOther)
            && $afterLookup['paid_at'] !== null,
        $failures
    );

    $window = 600;
    $windowStart = intdiv(time(), $window) * $window;
    $insertBucket = static function (PDO $db, string $bucket, string $key, int $start): void {
        $stmt = $db->prepare(
            'INSERT OR REPLACE INTO rate_limits (bucket, key_hash, window_start, counter)
             VALUES (:bucket, :key_hash, :window_start, :counter)'
        );
        $stmt->execute([
            ':bucket' => $bucket,
            ':key_hash' => hash('sha256', $key),
            ':window_start' => $start,
            ':counter' => 100,
        ]);
    };
    $insertBucket(
        $dbCheck,
        'receipt_actor',
        device_hash($tokenRateA) . '|127.0.0.1',
        $windowStart
    );
    $dbCheck = null;

    $rateA = $get('?order_id=' . urlencode($rateOrderA), ['rll_device' => $tokenRateA]);
    assert_http_status('aktor yang kena batas laju ditolak 404', 404, $rateA, $failures);
    assert_true(
        '404 batas laju identik dengan kegagalan lain',
        $rateA[1] === $uniformBody && $headerSnapshot($rateA[2]) === $uniformHeaders,
        $failures
    );
    $rateCheck = $get(
        '?order_id=' . urlencode($rateOrderA) . '&check=1',
        ['rll_device' => $tokenRateA]
    );
    assert_http_status('cek struk saat batas laju juga 404', 404, $rateCheck, $failures);
    assert_true(
        '404 batas laju pada cek struk identik',
        $rateCheck[1] === $uniformBody && $headerSnapshot($rateCheck[2]) === $uniformHeaders,
        $failures
    );

    $rateB = $get('?order_id=' . urlencode($rateOrderB), ['rll_device' => $tokenRateB]);
    assert_http_status(
        'aktor lain pada IP yang sama tidak ikut terkunci',
        200,
        $rateB,
        $failures
    );

    $dbCheck = open_test_db($dbPath);
    $insertBucket(
        $dbCheck,
        'receipt_order',
        $rateOrderB . '|' . device_hash($tokenRateA) . '|127.0.0.1',
        $windowStart
    );
    $dbCheck = null;
    $rateBAfter = $get('?order_id=' . urlencode($rateOrderB), ['rll_device' => $tokenRateB]);
    assert_http_status(
        'bucket per order aktor lain tidak mengunci pemilik',
        200,
        $rateBAfter,
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
    ? 'HTTP RECEIPT: OK' . PHP_EOL
    : 'HTTP RECEIPT: ' . $failures . ' gagal' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
