<?php
declare(strict_types=1);

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "FAIL pdo_sqlite tidak tersedia; jalankan dengan PHP XAMPP.\n");
    exit(1);
}

require_once __DIR__ . '/../php/orders.php';
require_once __DIR__ . '/../php/device.php';
require_once __DIR__ . '/support.php';

$tempDir = sys_get_temp_dir() . '/rll-unit-orders-' . bin2hex(random_bytes(4));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    fwrite(STDERR, "FAIL tidak bisa membuat direktori sementara.\n");
    exit(1);
}

$failures = 0;

try {
    $dbPath = $tempDir . '/legacy.sqlite';
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');

    $db->exec(
        "CREATE TABLE orders (
            order_id TEXT PRIMARY KEY,
            customer_name TEXT NOT NULL,
            customer_email TEXT NOT NULL,
            customer_phone TEXT NOT NULL,
            gross_amount INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending'
                CHECK (status IN ('pending', 'paid', 'failed', 'expired')),
            payment_type TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )"
    );
    $db->exec(
        "CREATE TABLE order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id TEXT NOT NULL,
            product_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            price INTEGER NOT NULL,
            qty INTEGER NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
        )"
    );

    $legacyOrderId = 'RLL-20261110-abc123';
    $db->prepare(
        'INSERT INTO orders
            (order_id, customer_name, customer_email, customer_phone, gross_amount, status, created_at, updated_at)
         VALUES
            (:order_id, :name, :email, :phone, :gross_amount, :status, :created_at, :updated_at)'
    )->execute([
        ':order_id' => $legacyOrderId,
        ':name' => 'Legacy Customer',
        ':email' => 'legacy@example.test',
        ':phone' => '081200000000',
        ':gross_amount' => 57000,
        ':status' => 'pending',
        ':created_at' => '2026-11-10 01:00:00',
        ':updated_at' => '2026-11-10 01:00:00',
    ]);
    $db->prepare(
        'INSERT INTO order_items (order_id, product_id, name, price, qty)
         VALUES (:order_id, :product_id, :name, :price, :qty)'
    )->execute([
        ':order_id' => $legacyOrderId,
        ':product_id' => 1,
        ':name' => 'Paket Hemat Duo',
        ':price' => 57000,
        ':qty' => 1,
    ]);

    $legacyPaidOrderId = 'RLL-20261110-legacy-paid';
    $db->prepare(
        'INSERT INTO orders
            (order_id, customer_name, customer_email, customer_phone, gross_amount, status, created_at, updated_at)
         VALUES
            (:order_id, :name, :email, :phone, :gross_amount, :status, :created_at, :updated_at)'
    )->execute([
        ':order_id' => $legacyPaidOrderId,
        ':name' => 'Legacy Paid',
        ':email' => 'legacy-paid@example.test',
        ':phone' => '081200000009',
        ':gross_amount' => 57000,
        ':status' => 'paid',
        ':created_at' => '2026-11-10 01:00:00',
        ':updated_at' => '2026-11-10 02:00:00',
    ]);

    migrate_db($db);
    assert_true(
        'migrasi menerima status cancelled',
        orders_table_accepts_cancelled($db),
        $failures
    );

    $columns = [];
    foreach ($db->query('PRAGMA table_info(orders)') as $column) {
        $columns[(string) $column['name']] = true;
    }
    assert_true(
        'kolom cancelled_by tersedia',
        isset($columns['cancelled_by']),
        $failures
    );
    assert_true(
        'kolom device_hash dan expires_at tersedia',
        isset($columns['device_hash'], $columns['expires_at']),
        $failures
    );
    assert_true(
        'kolom paid_at tersedia',
        isset($columns['paid_at']),
        $failures
    );

    $legacyPaid = find_order($db, $legacyPaidOrderId);
    assert_true(
        'paid_at order legacy dibiarkan NULL (waktu bayar tidak pasti)',
        $legacyPaid !== null && $legacyPaid['paid_at'] === null,
        $failures
    );

    $legacy = find_order($db, $legacyOrderId);
    assert_true(
        'data lama tetap utuh setelah migrasi',
        $legacy !== null && (int) $legacy['gross_amount'] === 57000,
        $failures
    );
    assert_true(
        'order_items lama tetap utuh',
        count(find_order_items($db, $legacyOrderId)) === 1,
        $failures
    );

    $indexes = [];
    foreach ($db->query('PRAGMA index_list(orders)') as $index) {
        $indexes[(string) $index['name']] = true;
    }
    assert_true(
        'index device_hash+status tersedia',
        isset($indexes['idx_orders_device_status']),
        $failures
    );

    migrate_db($db);
    $count = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    assert_true('migrasi idempoten', $count === 2, $failures);

    assert_true(
        'status cancel dipetakan ke cancelled',
        map_transaction_status('cancel') === 'cancelled',
        $failures
    );
    assert_true(
        'status deny dipetakan ke failed',
        map_transaction_status('deny') === 'failed',
        $failures
    );
    assert_true(
        'status expire dipetakan ke expired',
        map_transaction_status('expire') === 'expired',
        $failures
    );

    assert_true(
        'waktu expiry WIB dinormalkan ke UTC',
        normalize_expiry_time('2026-11-10 10:00:00') === '2026-11-10T03:00:00Z',
        $failures
    );
    assert_true(
        'waktu database UTC dinormalkan ke ISO',
        utc_db_to_iso('2026-11-10 03:00:00') === '2026-11-10T03:00:00Z',
        $failures
    );

    assert_true(
        'expiry masa lalu terdeteksi',
        is_order_expired(
            ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)],
            time()
        ),
        $failures
    );
    assert_true(
        'expiry masa depan tidak terdeteksi',
        !is_order_expired(
            ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)],
            time()
        ),
        $failures
    );

    $statusOrderId = 'RLL-20261110-def456';
    save_order($db, [
        'order_id' => $statusOrderId,
        'customer_name' => 'Status Customer',
        'customer_email' => 'status@example.test',
        'customer_phone' => '081200000001',
        'gross_amount' => 57000,
        'device_hash' => device_hash(str_repeat('a', 64)),
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
    ], [[
        'id' => '1',
        'name' => 'Paket Hemat Duo',
        'price' => 57000,
        'quantity' => 1,
    ]]);

    $changed = update_order_status($db, $statusOrderId, 'cancelled', null, [
        'cancel_reason_code' => 'wrong_items',
        'cancel_reason_text' => null,
        'cancelled_by' => 'customer',
    ]);
    $cancelled = find_order($db, $statusOrderId);
    assert_true('pembatalan pending berhasil', $changed, $failures);
    assert_true(
        'alasan dan pelaku pembatalan tersimpan',
        $cancelled !== null
            && $cancelled['status'] === 'cancelled'
            && $cancelled['cancel_reason_code'] === 'wrong_items'
            && $cancelled['cancelled_by'] === 'customer'
            && $cancelled['cancelled_at'] !== null,
        $failures
    );

    $paidOrderId = 'RLL-20261110-abc999';
    save_order($db, [
        'order_id' => $paidOrderId,
        'customer_name' => 'Paid Customer',
        'customer_email' => 'paid@example.test',
        'customer_phone' => '081200000002',
        'gross_amount' => 57000,
    ], [[
        'id' => '1',
        'name' => 'Paket Hemat Duo',
        'price' => 57000,
        'quantity' => 1,
    ]]);
    update_order_status($db, $paidOrderId, 'paid');
    $paidRow = find_order($db, $paidOrderId);
    assert_true(
        'transisi ke paid mengisi paid_at',
        $paidRow !== null && $paidRow['paid_at'] !== null,
        $failures
    );
    $paidChange = update_order_status($db, $paidOrderId, 'cancelled');
    assert_true(
        'paid tidak bisa menjadi cancelled',
        !$paidChange && find_order($db, $paidOrderId)['status'] === 'paid',
        $failures
    );

    $promotedOrderId = 'RLL-20261110-def789';
    save_order($db, [
        'order_id' => $promotedOrderId,
        'customer_name' => 'Promoted Customer',
        'customer_email' => 'promoted@example.test',
        'customer_phone' => '081200000003',
        'gross_amount' => 57000,
    ], [[
        'id' => '1',
        'name' => 'Paket Hemat Duo',
        'price' => 57000,
        'quantity' => 1,
    ]]);
    update_order_status($db, $promotedOrderId, 'cancelled');
    $promoted = promote_cancelled_order_to_paid($db, $promotedOrderId, 'bank_transfer');
    $promotedRow = find_order($db, $promotedOrderId);
    assert_true(
        'settlement cancelled ke paid mengisi paid_at',
        $promoted
            && $promotedRow !== null
            && $promotedRow['status'] === 'paid'
            && $promotedRow['paid_at'] !== null,
        $failures
    );

    $evaluated = evaluate_midtrans_status($promotedRow, [
        'status_code' => '200',
        'order_id' => $promotedOrderId,
        'transaction_status' => 'settlement',
        'gross_amount' => '57000.00',
    ]);
    assert_true(
        'status valid dengan nominal cocok dapat diterapkan',
        $evaluated['result'] === 'apply' && ($evaluated['status'] ?? '') === 'paid',
        $failures
    );

    $invalidStatus = evaluate_midtrans_status($promotedRow, [
        'status_code' => '404',
        'status_message' => "Transaction doesn't exist.",
    ]);
    assert_true(
        'body 404 tanpa transaction_status bukan mismatch nominal',
        $invalidStatus['result'] === 'invalid'
            && ($invalidStatus['reason'] ?? '') === 'missing_transaction_status',
        $failures
    );

    $mismatchAmount = evaluate_midtrans_status($promotedRow, [
        'status_code' => '200',
        'order_id' => $promotedOrderId,
        'transaction_status' => 'settlement',
        'gross_amount' => '1.00',
    ]);
    assert_true(
        'nominal berbeda tetap terdeteksi mismatch',
        $mismatchAmount['result'] === 'mismatch'
            && ($mismatchAmount['reason'] ?? '') === 'gross_amount',
        $failures
    );

    $expiredOrderId = 'RLL-20261110-ea9999';
    save_order($db, [
        'order_id' => $expiredOrderId,
        'customer_name' => 'Expired Customer',
        'customer_email' => 'expired@example.test',
        'customer_phone' => '081200000004',
        'gross_amount' => 57000,
    ], [[
        'id' => '1',
        'name' => 'Paket Hemat Duo',
        'price' => 57000,
        'quantity' => 1,
    ]]);
    update_order_status($db, $expiredOrderId, 'expired');
    $promotedExpired = promote_expired_order_to_paid($db, $expiredOrderId, 'qris');
    $expiredRow = find_order($db, $expiredOrderId);
    assert_true(
        'settlement expired ke paid mengisi paid_at',
        $promotedExpired
            && $expiredRow !== null
            && $expiredRow['status'] === 'paid'
            && $expiredRow['paid_at'] !== null
            && $expiredRow['payment_type'] === 'qris',
        $failures
    );
    assert_true(
        'promosi expired tidak mengubah order pending',
        !promote_expired_order_to_paid($db, $legacyOrderId, 'qris')
            && find_order($db, $legacyOrderId)['status'] === 'pending',
        $failures
    );

    assert_true(
        'normalisasi tiga format nomor telepon sama',
        normalize_phone_contact('0812-3456-7890') === '6281234567890'
            && normalize_phone_contact('6281234567890') === '6281234567890'
            && normalize_phone_contact('+62 812 3456 7890') === '6281234567890',
        $failures
    );
    assert_true(
        'kontak email tidak peka huruf besar/kecil',
        contact_matches_order([
            'customer_email' => 'Customer@Example.Test',
            'customer_phone' => '081200000000',
        ], '  CUSTOMER@example.test '),
        $failures
    );
    assert_true(
        'kontak telepon cocok dalam format berbeda',
        contact_matches_order([
            'customer_email' => 'customer@example.test',
            'customer_phone' => '081234567890',
        ], '+62 812 3456 7890'),
        $failures
    );

    $token = generate_device_token();
    assert_true(
        'device token 64 hex',
        preg_match('/^[a-f0-9]{64}$/', $token) === 1,
        $failures
    );
    assert_true(
        'device hash SHA-256 dan bukan token mentah',
        strlen(device_hash($token)) === 64 && device_hash($token) !== $token,
        $failures
    );

    $cookieOptions = device_cookie_options($token, false);
    assert_true(
        'cookie HttpOnly SameSite Lax',
        $cookieOptions['httponly'] === true && $cookieOptions['samesite'] === 'Lax',
        $failures
    );
    assert_true(
        'cookie tidak Secure pada HTTP',
        $cookieOptions['secure'] === false,
        $failures
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . PHP_EOL);
    $failures++;
} finally {
    remove_tree($tempDir);
}

echo $failures === 0
    ? 'UNIT ORDERS: OK' . PHP_EOL
    : 'UNIT ORDERS: ' . $failures . ' gagal' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
