<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/config-loader.php';
require_once __DIR__ . '/device.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/receipt-lib.php';

function receipt_respond(int $status, string $html): void
{
    http_response_code($status);
    receipt_headers();
    echo $html;
    exit;
}

function receipt_fail(): void
{
    receipt_respond(404, receipt_render_not_found());
}

function receipt_check_response(bool $eligible): void
{
    http_response_code(200);
    receipt_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['eligible' => $eligible], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param array<string, mixed> $source
 */
function receipt_request_value(array $source, string $key): string
{
    $value = $source[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
if ($method !== 'GET' && $method !== 'POST') {
    receipt_fail();
}

// Kontak tidak pernah diterima lewat query string, termasuk pada tautan struk.
if (array_key_exists('contact', $_GET)
    || array_key_exists('email', $_GET)
    || array_key_exists('phone', $_GET)
) {
    receipt_fail();
}

$isCheck = $method === 'GET' && receipt_request_value($_GET, 'check') === '1';
$orderId = $method === 'GET'
    ? receipt_request_value($_GET, 'order_id')
    : receipt_request_value($_POST, 'order_id');
$contact = $method === 'POST'
    ? receipt_request_value($_POST, 'contact')
    : '';

$config = load_config();
$window = max(1, (int) ($config['rate_limit_window'] ?? 600));
$actorLimit = max(1, (int) ($config['receipt_rate_limit_actor'] ?? 30));
$orderLimit = max(1, (int) ($config['receipt_rate_limit_order'] ?? 60));

try {
    $db = get_db();
} catch (Throwable $error) {
    api_log('receipt.log', 'Gagal membuka database.');
    receipt_respond(500, receipt_render_error());
}

$token = device_token_from_cookie();
$cookieHash = $token === null ? '' : device_hash($token);
$actorKey = ($cookieHash !== '' ? $cookieHash : 'anon') . '|' . api_client_ip();
$orderKey = substr($orderId !== '' ? $orderId : 'missing', 0, 64);

try {
    // Dijalankan sebelum lookup: percobaan untuk order yang tidak ada tetap
    // memakai jalur dan biaya yang sama. Bucket per order memakai aktor agar
    // pihak lain tidak bisa mengunci bucket order tersebut.
    $actorRate = consume_rate_limit($db, 'receipt_actor', $actorKey, $actorLimit, $window);
    $orderRate = consume_rate_limit(
        $db,
        'receipt_order',
        $orderKey . '|' . $actorKey,
        $orderLimit,
        $window
    );
} catch (Throwable $error) {
    api_log('receipt.log', 'Gagal memeriksa batas laju.');
    receipt_respond(500, receipt_render_error());
}

if (!$actorRate['allowed'] || !$orderRate['allowed']) {
    receipt_fail();
}

if (mb_strlen($orderId) > 64 || mb_strlen($contact) > 100) {
    receipt_fail();
}

$validOrderId = is_valid_order_id($orderId);
$order = find_order($db, $orderId);

// Order yang tidak ada tetap menjalani perbandingan kontak dengan nilai palsu
// agar tidak ada jalur balasan yang lebih cepat.
$comparisonOrder = $order ?? [
    'customer_email' => 'receipt-missing@invalid.test',
    'customer_phone' => '000000000000',
];
$contactMatches = contact_matches_order($comparisonOrder, $contact);

$storedDeviceHash = (string) ($order['device_hash'] ?? '');
$dummyHash = str_repeat('0', 64);
$ownerHashEqual = hash_equals(
    $storedDeviceHash !== '' ? $storedDeviceHash : $dummyHash,
    $cookieHash !== '' ? $cookieHash : $dummyHash
);
$ownerMatches = $cookieHash !== '' && $storedDeviceHash !== '' && $ownerHashEqual;

$paid = $order !== null && (string) ($order['status'] ?? '') === 'paid';
$authorized = $method === 'GET'
    ? ($paid && $ownerMatches)
    : ($paid && $contactMatches);
$eligible = $validOrderId && $authorized;

if ($isCheck) {
    receipt_check_response($eligible);
}

if (!$eligible || $order === null) {
    receipt_fail();
}

$items = find_order_items($db, $orderId);

receipt_respond(200, receipt_render_page($order, $items, $config));
