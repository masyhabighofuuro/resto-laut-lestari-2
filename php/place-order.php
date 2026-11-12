<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/midtrans.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/config-loader.php';
require_once __DIR__ . '/device.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

/**
 * @param array<string, mixed> $body
 */
function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Metode tidak diizinkan.']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== 0) {
    respond(415, ['error' => 'Content-Type harus application/json.']);
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody === false ? '' : $rawBody, true);
if (!is_array($data)) {
    respond(400, ['error' => 'Body JSON tidak valid.']);
}

$catalog = require __DIR__ . '/products.php';
$config = load_config();

$orderExpiryMinutes = (int) ($config['order_expiry_minutes'] ?? 60);
if ($orderExpiryMinutes < 1 || $orderExpiryMinutes > 10080) {
    $orderExpiryMinutes = 60;
}

$maxPendingPerDevice = (int) ($config['max_pending_per_device'] ?? 3);
if ($maxPendingPerDevice < 1 || $maxPendingPerDevice > 20) {
    $maxPendingPerDevice = 3;
}

$customer = $data['customer'] ?? null;
if (!is_array($customer)) {
    respond(400, ['error' => 'Data customer tidak valid.']);
}

$name = trim((string) ($customer['name'] ?? ''));
$email = trim((string) ($customer['email'] ?? ''));
$phone = trim((string) ($customer['phone'] ?? ''));

if ($name === '' || mb_strlen($name) > 50) {
    respond(400, ['error' => 'Nama tidak valid (wajib diisi, maksimal 50 karakter).']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {
    respond(400, ['error' => 'Email tidak valid.']);
}
if (!preg_match('/^[0-9+\-\s()]{8,20}$/', $phone)) {
    respond(400, ['error' => 'Nomor telepon tidak valid.']);
}

$items = $data['items'] ?? null;
if (!is_array($items) || $items === []) {
    respond(400, ['error' => 'Keranjang tidak boleh kosong.']);
}
if (count($items) > 50) {
    respond(400, ['error' => 'Terlalu banyak item dalam satu pesanan.']);
}

$quantities = [];
foreach ($items as $item) {
    if (!is_array($item)) {
        respond(400, ['error' => 'Format item tidak valid.']);
    }

    $id = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
    $qty = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);

    if ($id === false || !isset($catalog[$id])) {
        respond(400, ['error' => 'Item tidak dikenal.']);
    }
    if ($qty === false || $qty < 1 || $qty > 50) {
        respond(400, ['error' => 'Jumlah item tidak valid (1-50).']);
    }

    $quantities[$id] = ($quantities[$id] ?? 0) + $qty;
    if ($quantities[$id] > 50) {
        respond(400, ['error' => 'Jumlah item tidak valid (1-50).']);
    }
}

$itemDetails = [];
$grossAmount = 0;

foreach ($quantities as $id => $qty) {
    $product = $catalog[$id];
    $price = (int) $product['price'];
    $grossAmount += $price * $qty;

    $itemDetails[] = [
        'id' => (string) $id,
        'price' => $price,
        'quantity' => $qty,
        'name' => mb_substr((string) $product['name'], 0, 50),
    ];
}

if ($grossAmount <= 0) {
    respond(400, ['error' => 'Total pembayaran tidak valid.']);
}

$serverKey = trim((string) ($config['server_key'] ?? ''));
if ($serverKey === '') {
    midtrans_log('Server Key Midtrans belum diisi di php/config.php.');
    respond(500, ['error' => 'Pembayaran belum dikonfigurasi. Silakan hubungi penjual.']);
}

$orderId = generate_order_id();
$deviceToken = get_or_create_device_token();
$deviceHash = device_hash($deviceToken);

$startTime = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
$snapStartTime = $startTime->format('Y-m-d H:i:s O');
$expiresAt = $startTime
    ->modify('+' . $orderExpiryMinutes . ' minutes')
    ->setTimezone(new DateTimeZone('UTC'))
    ->format('Y-m-d\TH:i:s\Z');

$payload = [
    'transaction_details' => [
        'order_id' => $orderId,
        'gross_amount' => $grossAmount,
    ],
    'customer_details' => [
        'first_name' => $name,
        'email' => $email,
        'phone' => $phone,
    ],
    'item_details' => $itemDetails,
    'expiry' => [
        'start_time' => $snapStartTime,
        'unit' => 'minute',
        'duration' => $orderExpiryMinutes,
    ],
];

try {
    $db = get_db();
    expire_pending_orders($db, $deviceHash);

    if (count_pending_orders_for_device($db, $deviceHash) >= $maxPendingPerDevice) {
        respond(409, [
            'error' => 'Terlalu banyak pesanan menunggu pembayaran. Selesaikan atau batalkan salah satu pesanan terlebih dahulu.',
        ]);
    }

    save_order($db, [
        'order_id' => $orderId,
        'customer_name' => $name,
        'customer_email' => $email,
        'customer_phone' => $phone,
        'gross_amount' => $grossAmount,
        'device_hash' => $deviceHash,
        'expires_at' => $expiresAt,
    ], $itemDetails);
} catch (Throwable $error) {
    midtrans_log('place-order gagal menyimpan pesanan: ' . $error->getMessage());
    respond(500, ['error' => 'Gagal menyimpan pesanan. Silakan coba lagi.']);
}

try {
    $result = create_snap_transaction(
        $payload,
        $serverKey,
        (bool) ($config['is_production'] ?? false)
    );
} catch (Throwable $error) {
    try {
        update_order_status($db, $orderId, 'failed', null, [
            'cancel_reason_code' => 'snap_error',
            'cancel_reason_text' => 'Gagal membuat token Snap.',
        ]);
    } catch (Throwable $updateError) {
        midtrans_log(
            'place-order gagal menandai pesanan failed: ' . $updateError->getMessage()
        );
    }

    midtrans_log('place-order gagal: ' . $error->getMessage());
    respond(500, ['error' => 'Gagal membuat transaksi pembayaran. Silakan coba lagi.']);
}

try {
    set_snap_token($db, $orderId, $result['token']);
} catch (Throwable $error) {
    midtrans_log('place-order gagal menyimpan snap_token: ' . $error->getMessage());
}

respond(200, [
    'token' => $result['token'],
    'order_id' => $orderId,
    'expires_at' => $expiresAt,
]);
