<?php
declare(strict_types=1);

const ORDER_ID_PATTERN = '/^RLL-\d{8}-[a-f0-9]{6}$/';
const ORDER_STATUSES = ['pending', 'paid', 'failed', 'expired', 'cancelled'];

function generate_order_id(): string
{
    return 'RLL-' . date('Ymd') . '-' . bin2hex(random_bytes(3));
}

function is_valid_order_id(string $orderId): bool
{
    return preg_match(ORDER_ID_PATTERN, $orderId) === 1;
}

function resolve_db_path(?string $path = null): string
{
    $path = $path ?? getenv('RLL_DB_PATH');

    if (is_string($path) && trim($path) !== '') {
        return $path;
    }

    return __DIR__ . '/storage/orders.sqlite';
}

function get_db(?string $path = null): PDO
{
    static $db = null;
    static $activePath = null;

    $path = resolve_db_path($path);

    if ($db instanceof PDO && $activePath === $path) {
        return $db;
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Tidak bisa membuat direktori database.');
    }

    $connection = new PDO('sqlite:' . $path);
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $connection->exec('PRAGMA foreign_keys = ON');
    $connection->exec('PRAGMA journal_mode = WAL');
    $connection->exec('PRAGMA busy_timeout = 5000');
    $connection->exec('PRAGMA synchronous = NORMAL');

    migrate_db($connection);

    $db = $connection;
    $activePath = $path;

    return $db;
}

function orders_table_sql(string $table = 'orders'): string
{
    return 'CREATE TABLE IF NOT EXISTS ' . $table . ' (
        order_id TEXT PRIMARY KEY,
        customer_name TEXT NOT NULL,
        customer_email TEXT NOT NULL,
        customer_phone TEXT NOT NULL,
        gross_amount INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT \'pending\'
            CHECK (status IN (\'pending\', \'paid\', \'failed\', \'expired\', \'cancelled\')),
        payment_type TEXT,
        paid_at TEXT,
        snap_token TEXT,
        expires_at TEXT,
        device_hash TEXT,
        cancel_reason_code TEXT,
        cancel_reason_text TEXT,
        cancelled_at TEXT,
        cancelled_by TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )';
}

function migrate_db(PDO $db): void
{
    $db->exec(orders_table_sql());
    ensure_orders_accept_cancelled($db);

    $db->exec(
        'CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id TEXT NOT NULL,
            product_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            price INTEGER NOT NULL,
            qty INTEGER NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
        )'
    );

    $db->exec(
        'CREATE INDEX IF NOT EXISTS idx_order_items_order_id ON order_items(order_id)'
    );
    $db->exec(
        'CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status)'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS rate_limits (
            bucket TEXT NOT NULL,
            key_hash TEXT NOT NULL,
            window_start INTEGER NOT NULL,
            counter INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (bucket, key_hash)
        )'
    );
    $db->exec(
        'CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(window_start)'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS status_checks (
            order_id TEXT PRIMARY KEY,
            checked_at INTEGER NOT NULL
        )'
    );
    $db->exec(
        'CREATE INDEX IF NOT EXISTS idx_status_checks_checked_at ON status_checks(checked_at)'
    );

    ensure_order_columns($db);
    // paid_at order lama sengaja dibiarkan NULL: updated_at bukan waktu bayar
    // yang pasti, jadi struk menampilkan "-" alih-alih tanggal perkiraan.
    $db->exec(
        'CREATE INDEX IF NOT EXISTS idx_orders_device_status ON orders(device_hash, status)'
    );
    normalize_legacy_expiry_values($db);
}

function orders_table_accepts_cancelled(PDO $db): bool
{
    $stmt = $db->query(
        "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'orders'"
    );
    $row = $stmt->fetch();

    return $row !== false && str_contains((string) $row['sql'], "'cancelled'");
}

function ensure_orders_accept_cancelled(PDO $db): void
{
    if (orders_table_accepts_cancelled($db)) {
        return;
    }

    $legacyColumns = [];
    foreach ($db->query('PRAGMA table_info(orders)') as $column) {
        $legacyColumns[] = (string) $column['name'];
    }

    $targetColumns = [
        'order_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'gross_amount',
        'status',
        'payment_type',
        'paid_at',
        'snap_token',
        'expires_at',
        'device_hash',
        'cancel_reason_code',
        'cancel_reason_text',
        'cancelled_at',
        'cancelled_by',
        'created_at',
        'updated_at',
    ];
    $commonColumns = array_values(array_intersect($targetColumns, $legacyColumns));
    $columnList = implode(', ', array_map(
        static fn (string $column): string => '"' . $column . '"',
        $commonColumns
    ));

    $db->exec('PRAGMA foreign_keys = OFF');

    try {
        $db->beginTransaction();
        $db->exec('DROP TABLE IF EXISTS orders_new');
        $db->exec(orders_table_sql('orders_new'));
        $db->exec(
            'INSERT INTO orders_new (' . $columnList . ')
             SELECT ' . $columnList . ' FROM orders'
        );
        $db->exec('DROP TABLE orders');
        $db->exec('ALTER TABLE orders_new RENAME TO orders');
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    } finally {
        $db->exec('PRAGMA foreign_keys = ON');
    }

    $db->query('PRAGMA foreign_key_check')->fetchAll();
}

function ensure_order_columns(PDO $db): void
{
    $columns = [];
    foreach ($db->query('PRAGMA table_info(orders)') as $column) {
        $columns[(string) $column['name']] = true;
    }

    $additions = [
        'paid_at' => 'TEXT',
        'snap_token' => 'TEXT',
        'expires_at' => 'TEXT',
        'device_hash' => 'TEXT',
        'cancel_reason_code' => 'TEXT',
        'cancel_reason_text' => 'TEXT',
        'cancelled_at' => 'TEXT',
        'cancelled_by' => 'TEXT',
    ];

    foreach ($additions as $name => $type) {
        if (!isset($columns[$name])) {
            $db->exec('ALTER TABLE orders ADD COLUMN ' . $name . ' ' . $type);
        }
    }
}

/**
 * @param array{
 *     order_id: string,
 *     customer_name: string,
 *     customer_email: string,
 *     customer_phone: string,
 *     gross_amount: int,
 *     device_hash?: ?string,
 *     expires_at?: ?string
 * } $order
 * @param array<int, array{id: string|int, name: string, price: int, quantity: int}> $items
 */
function save_order(PDO $db, array $order, array $items): void
{
    $orderId = (string) ($order['order_id'] ?? '');

    if (!is_valid_order_id($orderId)) {
        throw new InvalidArgumentException('Format order_id tidak valid.');
    }
    if ($items === []) {
        throw new InvalidArgumentException('Pesanan tidak memiliki item.');
    }

    $now = gmdate('Y-m-d H:i:s');

    $db->beginTransaction();

    try {
        $orderStmt = $db->prepare(
            'INSERT INTO orders
                (order_id, customer_name, customer_email, customer_phone, gross_amount,
                 status, device_hash, expires_at, created_at, updated_at)
             VALUES
                (:order_id, :customer_name, :customer_email, :customer_phone, :gross_amount,
                 :status, :device_hash, :expires_at, :created_at, :updated_at)'
        );

        $orderStmt->execute([
            ':order_id' => $orderId,
            ':customer_name' => (string) $order['customer_name'],
            ':customer_email' => (string) $order['customer_email'],
            ':customer_phone' => (string) $order['customer_phone'],
            ':gross_amount' => (int) $order['gross_amount'],
            ':status' => 'pending',
            ':device_hash' => $order['device_hash'] ?? null,
            ':expires_at' => $order['expires_at'] ?? null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $itemStmt = $db->prepare(
            'INSERT INTO order_items (order_id, product_id, name, price, qty)
             VALUES (:order_id, :product_id, :name, :price, :qty)'
        );

        foreach ($items as $item) {
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':product_id' => (int) $item['id'],
                ':name' => (string) $item['name'],
                ':price' => (int) $item['price'],
                ':qty' => (int) $item['quantity'],
            ]);
        }

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function find_order(PDO $db, string $orderId): ?array
{
    $stmt = $db->prepare('SELECT * FROM orders WHERE order_id = :order_id');
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch();

    return $order === false ? null : $order;
}

function find_order_items(PDO $db, string $orderId): array
{
    $stmt = $db->prepare(
        'SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id'
    );
    $stmt->execute([':order_id' => $orderId]);

    return $stmt->fetchAll();
}

function set_snap_token(PDO $db, string $orderId, string $token): void
{
    $stmt = $db->prepare(
        'UPDATE orders SET snap_token = :snap_token, updated_at = :updated_at
         WHERE order_id = :order_id'
    );
    $stmt->execute([
        ':snap_token' => $token,
        ':updated_at' => gmdate('Y-m-d H:i:s'),
        ':order_id' => $orderId,
    ]);
}

function normalize_expiry_time(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    try {
        $timezone = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1
            ? new DateTimeZone('Asia/Jakarta')
            : new DateTimeZone('UTC');
        $date = new DateTimeImmutable($value, $timezone);
    } catch (Exception) {
        return null;
    }

    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function utc_db_to_iso(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $value = trim($value);
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1) {
        return $value;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) === 1) {
        return str_replace(' ', 'T', $value) . 'Z';
    }

    return normalize_expiry_time($value);
}

function is_order_expired(array $order, ?int $now = null): bool
{
    $expiresAt = $order['expires_at'] ?? null;
    if (!is_string($expiresAt) || trim($expiresAt) === '') {
        return false;
    }

    $normalized = normalize_expiry_time($expiresAt);
    if ($normalized === null) {
        return false;
    }

    $timestamp = strtotime($normalized);
    return $timestamp !== false && $timestamp <= ($now ?? time());
}

function expire_pending_orders(
    PDO $db,
    ?string $deviceHash = null,
    ?int $now = null
): int {
    $sql = "SELECT * FROM orders
            WHERE status = 'pending'
              AND expires_at IS NOT NULL
              AND trim(expires_at) <> ''";
    $params = [];

    if ($deviceHash !== null) {
        $sql .= ' AND device_hash = :device_hash';
        $params[':device_hash'] = $deviceHash;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $expired = 0;
    foreach ($stmt->fetchAll() as $order) {
        if (!is_order_expired($order, $now)) {
            continue;
        }

        $updated = update_order_status(
            $db,
            (string) $order['order_id'],
            'expired',
            null,
            [
                'cancel_reason_code' => 'expired',
                'cancel_reason_text' => 'Batas waktu pembayaran habis.',
            ]
        );

        if ($updated) {
            $expired++;
        }
    }

    return $expired;
}

function find_pending_orders_for_device(
    PDO $db,
    string $deviceHash,
    int $limit = 50
): array {
    $limit = max(1, min(100, $limit));

    $stmt = $db->prepare(
        "SELECT * FROM orders
         WHERE status = 'pending' AND device_hash = :device_hash
         ORDER BY created_at DESC, order_id DESC
         LIMIT :limit"
    );
    $stmt->bindValue(':device_hash', $deviceHash, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function count_pending_orders_for_device(PDO $db, string $deviceHash): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM orders
         WHERE status = 'pending' AND device_hash = :device_hash"
    );
    $stmt->execute([':device_hash' => $deviceHash]);

    return (int) $stmt->fetchColumn();
}

function link_order_to_device(PDO $db, string $orderId, string $deviceHash): bool
{
    $stmt = $db->prepare(
        "UPDATE orders
         SET device_hash = :device_hash, updated_at = :updated_at
         WHERE order_id = :order_id AND status = 'pending'"
    );
    $stmt->execute([
        ':device_hash' => $deviceHash,
        ':updated_at' => gmdate('Y-m-d H:i:s'),
        ':order_id' => $orderId,
    ]);

    return $stmt->rowCount() > 0;
}

function normalize_legacy_expiry_values(PDO $db): void
{
    $rows = $db->query(
        "SELECT order_id, expires_at FROM orders
         WHERE expires_at IS NOT NULL AND trim(expires_at) <> ''"
    )->fetchAll();

    $update = $db->prepare(
        'UPDATE orders SET expires_at = :expires_at WHERE order_id = :order_id'
    );

    foreach ($rows as $row) {
        $raw = (string) $row['expires_at'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $raw) === 1) {
            continue;
        }

        $normalized = normalize_expiry_time($raw);
        if ($normalized === null || $normalized === $raw) {
            continue;
        }

        $update->execute([
            ':expires_at' => $normalized,
            ':order_id' => (string) $row['order_id'],
        ]);
    }
}

/**
 * Hanya order berstatus pending yang boleh berubah. Status terminal tidak
 * pernah kembali ke pending dan status paid tidak pernah diturunkan.
 *
 * @param array{
 *     expires_at?: ?string,
 *     device_hash?: ?string,
 *     cancel_reason_code?: ?string,
 *     cancel_reason_text?: ?string,
 *     cancelled_by?: ?string
 * } $meta
 */
function update_order_status(
    PDO $db,
    string $orderId,
    string $status,
    ?string $paymentType = null,
    array $meta = []
): bool {
    if (!in_array($status, ORDER_STATUSES, true)) {
        throw new InvalidArgumentException('Status pesanan tidak valid.');
    }

    $current = find_order($db, $orderId);
    if ($current === null || $current['status'] !== 'pending') {
        return false;
    }

    $now = gmdate('Y-m-d H:i:s');
    $paidAt = $status === 'paid' ? $now : null;
    $cancelledAt = $status === 'cancelled' ? $now : null;
    $cancelledBy = null;

    if ($status === 'cancelled') {
        $candidate = $meta['cancelled_by'] ?? null;
        $cancelledBy = in_array($candidate, ['customer', 'system'], true)
            ? $candidate
            : 'system';
    }

    $paymentType = $paymentType !== null && trim($paymentType) !== ''
        ? $paymentType
        : null;

    $stmt = $db->prepare(
        'UPDATE orders SET
            status = :status,
            payment_type = COALESCE(:payment_type, payment_type),
            paid_at = COALESCE(:paid_at, paid_at),
            expires_at = COALESCE(:expires_at, expires_at),
            device_hash = COALESCE(:device_hash, device_hash),
            cancel_reason_code = COALESCE(:cancel_reason_code, cancel_reason_code),
            cancel_reason_text = COALESCE(:cancel_reason_text, cancel_reason_text),
            cancelled_at = COALESCE(:cancelled_at, cancelled_at),
            cancelled_by = COALESCE(:cancelled_by, cancelled_by),
            updated_at = :updated_at
         WHERE order_id = :order_id AND status = \'pending\''
    );

    $stmt->execute([
        ':status' => $status,
        ':payment_type' => $paymentType,
        ':paid_at' => $paidAt,
        ':expires_at' => $meta['expires_at'] ?? null,
        ':device_hash' => $meta['device_hash'] ?? null,
        ':cancel_reason_code' => $meta['cancel_reason_code'] ?? null,
        ':cancel_reason_text' => $meta['cancel_reason_text'] ?? null,
        ':cancelled_at' => $cancelledAt,
        ':cancelled_by' => $cancelledBy,
        ':updated_at' => $now,
        ':order_id' => $orderId,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * Pengecualian terverifikasi: settlement untuk order yang sudah dibatalkan
 * tetap dicatat sebagai paid agar tidak ada pembayaran yang hilang.
 */
function promote_cancelled_order_to_paid(
    PDO $db,
    string $orderId,
    ?string $paymentType = null
): bool {
    $paymentType = $paymentType !== null && trim($paymentType) !== ''
        ? $paymentType
        : null;

    $stmt = $db->prepare(
        'UPDATE orders SET
            status = \'paid\',
            payment_type = COALESCE(:payment_type, payment_type),
            paid_at = COALESCE(paid_at, :paid_at),
            updated_at = :updated_at
         WHERE order_id = :order_id AND status = \'cancelled\''
    );

    $now = gmdate('Y-m-d H:i:s');
    $stmt->execute([
        ':payment_type' => $paymentType,
        ':paid_at' => $now,
        ':updated_at' => $now,
        ':order_id' => $orderId,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * Pengecualian terverifikasi: settlement untuk order yang sudah kedaluwarsa
 * tetap dicatat sebagai paid agar tidak ada pembayaran yang hilang.
 */
function promote_expired_order_to_paid(
    PDO $db,
    string $orderId,
    ?string $paymentType = null
): bool {
    $paymentType = $paymentType !== null && trim($paymentType) !== ''
        ? $paymentType
        : null;

    $stmt = $db->prepare(
        'UPDATE orders SET
            status = \'paid\',
            payment_type = COALESCE(:payment_type, payment_type),
            paid_at = COALESCE(paid_at, :paid_at),
            updated_at = :updated_at
         WHERE order_id = :order_id AND status = \'expired\''
    );

    $now = gmdate('Y-m-d H:i:s');
    $stmt->execute([
        ':payment_type' => $paymentType,
        ':paid_at' => $now,
        ':updated_at' => $now,
        ':order_id' => $orderId,
    ]);

    return $stmt->rowCount() > 0;
}

function status_check_is_fresh(
    PDO $db,
    string $orderId,
    int $minIntervalSeconds
): bool {
    $minIntervalSeconds = max(0, $minIntervalSeconds);
    if ($minIntervalSeconds === 0) {
        return false;
    }

    $stmt = $db->prepare(
        'SELECT checked_at FROM status_checks WHERE order_id = :order_id'
    );
    $stmt->execute([':order_id' => $orderId]);
    $checkedAt = $stmt->fetchColumn();

    return $checkedAt !== false && (time() - (int) $checkedAt) < $minIntervalSeconds;
}

function mark_status_checked(PDO $db, string $orderId): void
{
    $stmt = $db->prepare(
        'INSERT INTO status_checks (order_id, checked_at)
         VALUES (:order_id, :checked_at)
         ON CONFLICT(order_id) DO UPDATE SET checked_at = excluded.checked_at'
    );
    $stmt->execute([
        ':order_id' => $orderId,
        ':checked_at' => time(),
    ]);
}

function verify_midtrans_signature(array $payload, string $signatureSecretKey): bool
{
    $orderId = (string) ($payload['order_id'] ?? '');
    $statusCode = (string) ($payload['status_code'] ?? '');
    $grossAmount = (string) ($payload['gross_amount'] ?? '');
    $signature = (string) ($payload['signature_key'] ?? '');

    if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signature === '') {
        return false;
    }

    $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $signatureSecretKey);

    return hash_equals($expected, $signature);
}

function normalize_gross_amount(mixed $value): ?int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return (int) round($value);
    }
    if (is_string($value) && is_numeric(trim($value))) {
        return (int) round((float) trim($value));
    }

    return null;
}

/**
 * Memvalidasi data transaksi terhadap order lokal tanpa menulis ke database.
 *
 * @return array{result: string, status?: ?string, payment_type?: ?string, meta?: array<string, mixed>, reason?: string}
 */
function evaluate_midtrans_status(array $order, array $data): array
{
    $transactionStatus = isset($data['transaction_status']) && is_string($data['transaction_status'])
        ? trim($data['transaction_status'])
        : '';

    if ($transactionStatus === '') {
        return ['result' => 'invalid', 'reason' => 'missing_transaction_status'];
    }

    $orderId = (string) ($order['order_id'] ?? '');
    $responseOrderId = isset($data['order_id']) ? (string) $data['order_id'] : '';

    if ($responseOrderId !== '' && $responseOrderId !== $orderId) {
        return ['result' => 'mismatch', 'reason' => 'order_id'];
    }

    $grossAmount = normalize_gross_amount($data['gross_amount'] ?? null);
    if ($grossAmount === null || $grossAmount !== (int) ($order['gross_amount'] ?? 0)) {
        return ['result' => 'mismatch', 'reason' => 'gross_amount'];
    }

    $status = map_transaction_status(
        $transactionStatus,
        (string) ($data['fraud_status'] ?? '')
    );

    if ($status === null) {
        return ['result' => 'unmapped'];
    }

    $meta = [];
    if (isset($data['expiry_time']) && is_string($data['expiry_time'])) {
        $meta['expires_at'] = normalize_expiry_time($data['expiry_time']);
    }
    if (isset($data['device_hash']) && is_string($data['device_hash'])) {
        $meta['device_hash'] = $data['device_hash'];
    }
    if (in_array($status, ['cancelled', 'failed', 'expired'], true)) {
        $meta['cancel_reason_code'] = $status === 'cancelled'
            ? 'midtrans_cancel'
            : (isset($data['status_code']) ? (string) $data['status_code'] : '');
        $meta['cancel_reason_text'] = isset($data['status_message']) && is_string($data['status_message'])
            ? $data['status_message']
            : '';
    }
    if ($status === 'cancelled') {
        $meta['cancelled_by'] = 'system';
    }

    return [
        'result' => 'apply',
        'status' => $status,
        'payment_type' => isset($data['payment_type']) && is_string($data['payment_type'])
            ? $data['payment_type']
            : null,
        'meta' => $meta,
    ];
}

/**
 * @return array{result: string, status?: ?string, source?: string, reason?: string}
 */
function apply_midtrans_status(PDO $db, array $order, array $data, string $source): array
{
    $evaluation = evaluate_midtrans_status($order, $data);

    if ($evaluation['result'] !== 'apply') {
        return $evaluation;
    }

    $updated = update_order_status(
        $db,
        (string) $order['order_id'],
        (string) $evaluation['status'],
        $evaluation['payment_type'] ?? null,
        $evaluation['meta'] ?? []
    );

    return [
        'result' => $updated ? 'updated' : 'not_pending',
        'status' => $evaluation['status'],
        'source' => $source,
    ];
}

function find_pending_orders(PDO $db, int $minAgeSeconds = 0, int $limit = 50): array
{
    $limit = max(1, min(500, $limit));
    $cutoff = gmdate('Y-m-d H:i:s', time() - max(0, $minAgeSeconds));

    $stmt = $db->prepare(
        "SELECT * FROM orders
         WHERE status = 'pending' AND created_at <= :cutoff
         ORDER BY created_at ASC, order_id ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function find_expired_orders(PDO $db, int $minAgeSeconds = 0, int $limit = 50): array
{
    $limit = max(1, min(500, $limit));
    $cutoff = gmdate('Y-m-d H:i:s', time() - max(0, $minAgeSeconds));

    $stmt = $db->prepare(
        "SELECT * FROM orders
         WHERE status = 'expired' AND updated_at <= :cutoff
         ORDER BY updated_at ASC, order_id ASC
         LIMIT :limit"
    );
    $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * @return array{allowed: bool, limit: int, remaining: int, retry_after: int}
 */
function consume_rate_limit(
    PDO $db,
    string $bucket,
    string $key,
    int $limit,
    int $windowSeconds
): array {
    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);
    $now = time();
    $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
    $keyHash = hash('sha256', $key);

    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            'SELECT window_start, counter FROM rate_limits
             WHERE bucket = :bucket AND key_hash = :key_hash'
        );
        $stmt->execute([':bucket' => $bucket, ':key_hash' => $keyHash]);
        $row = $stmt->fetch();

        if ($row === false || (int) $row['window_start'] !== $windowStart) {
            if ($row === false) {
                $stmt = $db->prepare(
                    'INSERT INTO rate_limits (bucket, key_hash, window_start, counter)
                     VALUES (:bucket, :key_hash, :window_start, 1)'
                );
                $stmt->execute([
                    ':bucket' => $bucket,
                    ':key_hash' => $keyHash,
                    ':window_start' => $windowStart,
                ]);
            } else {
                $stmt = $db->prepare(
                    'UPDATE rate_limits SET window_start = :window_start, counter = 1
                     WHERE bucket = :bucket AND key_hash = :key_hash'
                );
                $stmt->execute([
                    ':window_start' => $windowStart,
                    ':bucket' => $bucket,
                    ':key_hash' => $keyHash,
                ]);
            }

            $allowed = true;
            $counter = 1;
        } else {
            $counter = (int) $row['counter'] + 1;
            $allowed = $counter <= $limit;

            if ($allowed) {
                $stmt = $db->prepare(
                    'UPDATE rate_limits SET counter = :counter
                     WHERE bucket = :bucket AND key_hash = :key_hash'
                );
                $stmt->execute([
                    ':counter' => $counter,
                    ':bucket' => $bucket,
                    ':key_hash' => $keyHash,
                ]);
            }
        }

        $cleanup = $db->prepare('DELETE FROM rate_limits WHERE window_start < :cutoff');
        $cleanup->execute([':cutoff' => $now - max(3600, $windowSeconds * 2)]);

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    $retryAfter = $allowed ? 0 : max(1, $windowStart + $windowSeconds - $now);

    return [
        'allowed' => $allowed,
        'limit' => $limit,
        'remaining' => max(0, $limit - $counter),
        'retry_after' => $retryAfter,
    ];
}

function normalize_email_contact(string $value): string
{
    return mb_strtolower(trim($value));
}

function normalize_phone_contact(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    if (!is_string($digits) || $digits === '') {
        return '';
    }

    if (str_starts_with($digits, '0')) {
        return '62' . substr($digits, 1);
    }
    if (str_starts_with($digits, '8')) {
        return '62' . $digits;
    }

    return $digits;
}

function contact_matches_order(array $order, string $contact): bool
{
    $storedEmail = normalize_email_contact((string) ($order['customer_email'] ?? ''));
    $candidateEmail = normalize_email_contact($contact);
    $storedPhone = normalize_phone_contact((string) ($order['customer_phone'] ?? ''));
    $candidatePhone = normalize_phone_contact($contact);

    $emailMatches = $storedEmail !== ''
        && $candidateEmail !== ''
        && hash_equals($storedEmail, $candidateEmail);
    $phoneMatches = $storedPhone !== ''
        && $candidatePhone !== ''
        && hash_equals($storedPhone, $candidatePhone);

    return $emailMatches || $phoneMatches;
}

function map_transaction_status(string $transactionStatus, string $fraudStatus = ''): ?string
{
    return match ($transactionStatus) {
        'settlement' => 'paid',
        'capture' => match ($fraudStatus) {
            'accept' => 'paid',
            'deny' => 'failed',
            default => 'pending',
        },
        'pending' => 'pending',
        'deny', 'failure' => 'failed',
        'cancel' => 'cancelled',
        'expire' => 'expired',
        default => null,
    };
}
