<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/config-loader.php';
require_once __DIR__ . '/device.php';
require_once __DIR__ . '/orders.php';

$data = api_require_json_post();

$orderId = trim((string) ($data['order_id'] ?? ''));
$contact = trim((string) ($data['contact'] ?? ''));

if (!is_valid_order_id($orderId)) {
    api_respond(400, ['error' => 'order_id tidak valid.']);
}
if ($contact === '' || mb_strlen($contact) > 100) {
    api_respond(400, ['error' => 'Kontak tidak valid.']);
}

$config = load_config();
$window = (int) ($config['rate_limit_window'] ?? 600);
$window = $window > 0 ? $window : 600;
$ipLimit = max(1, (int) ($config['lookup_rate_limit_ip'] ?? 10));
$deviceLimit = max(1, (int) ($config['lookup_rate_limit_device'] ?? 5));

try {
    $db = get_db();
} catch (Throwable $error) {
    api_log('order-lookup.log', 'Gagal membuka database: ' . $error->getMessage());
    api_respond(500, ['error' => 'Terjadi kesalahan pada server.']);
}

$ip = api_client_ip();

try {
    $ipRate = consume_rate_limit($db, 'lookup_ip', $ip, $ipLimit, $window);
} catch (Throwable $error) {
    api_log('order-lookup.log', 'Gagal memeriksa rate limit IP.');
    api_respond(500, ['error' => 'Terjadi kesalahan pada server.']);
}

if (!$ipRate['allowed']) {
    header('Retry-After: ' . $ipRate['retry_after']);
    api_respond(429, ['error' => 'Terlalu banyak percobaan. Coba lagi nanti.']);
}

$existingToken = device_token_from_cookie();
$deviceKey = $existingToken === null ? 'ip:' . $ip : device_hash($existingToken);

try {
    $deviceRate = consume_rate_limit(
        $db,
        'lookup_device',
        $deviceKey,
        $deviceLimit,
        $window
    );
} catch (Throwable $error) {
    api_log('order-lookup.log', 'Gagal memeriksa rate limit perangkat.');
    api_respond(500, ['error' => 'Terjadi kesalahan pada server.']);
}

if (!$deviceRate['allowed']) {
    header('Retry-After: ' . $deviceRate['retry_after']);
    api_respond(429, ['error' => 'Terlalu banyak percobaan. Coba lagi nanti.']);
}

$order = find_order($db, $orderId);

if ($order === null || $order['status'] !== 'pending') {
    api_respond(404, ['error' => 'Pesanan tidak ditemukan.']);
}

if (is_order_expired($order)) {
    update_order_status($db, $orderId, 'expired', null, [
        'cancel_reason_code' => 'expired',
        'cancel_reason_text' => 'Batas waktu pembayaran habis.',
    ]);
    api_respond(404, ['error' => 'Pesanan tidak ditemukan.']);
}

if (!contact_matches_order($order, $contact)) {
    api_respond(404, ['error' => 'Pesanan tidak ditemukan.']);
}

$token = $existingToken ?? generate_device_token();
$deviceHash = device_hash($token);

try {
    $linked = link_order_to_device($db, $orderId, $deviceHash);
} catch (Throwable $error) {
    api_log('order-lookup.log', 'Gagal menautkan pesanan: ' . $error->getMessage());
    api_respond(500, ['error' => 'Terjadi kesalahan pada server.']);
}

if (!$linked) {
    api_respond(404, ['error' => 'Pesanan tidak ditemukan.']);
}

if ($existingToken === null) {
    set_device_cookie($token);
}

api_respond(200, ['status' => 'linked']);
