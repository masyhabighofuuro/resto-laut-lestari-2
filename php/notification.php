<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/midtrans.php';
require_once __DIR__ . '/config-loader.php';

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

function notification_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/notification.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * Verifikasi cadangan saat signature webhook tidak valid. Respons dibuat
 * seragam untuk order yang ada maupun tidak ada di database.
 *
 * @param array<string, mixed> $config
 */
function handle_status_fallback(array $config, string $orderId): void
{
    $serverKey = trim((string) ($config['server_key'] ?? ''));
    if ($serverKey === '') {
        notification_log('Fallback: Server Key Midtrans belum diisi.');
        respond(500, ['error' => 'Konfigurasi pembayaran belum lengkap.']);
    }

    try {
        $db = get_db();
    } catch (Throwable $error) {
        notification_log('Fallback: gagal membuka database.');
        respond(500, ['error' => 'Terjadi kesalahan pada server.']);
    }

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $window = (int) ($config['rate_limit_window'] ?? 600);
    $window = $window > 0 ? $window : 600;
    $orderLimit = (int) ($config['rate_limit_order'] ?? 5);
    $orderLimit = $orderLimit > 0 ? $orderLimit : 5;
    $ipLimit = (int) ($config['rate_limit_ip'] ?? 20);
    $ipLimit = $ipLimit > 0 ? $ipLimit : 20;

    try {
        $orderRate = consume_rate_limit($db, 'status_api_order', $orderId, $orderLimit, $window);
        if (!$orderRate['allowed']) {
            notification_log('Fallback: rate limit per order untuk ' . $orderId);
            header('Retry-After: ' . $orderRate['retry_after']);
            respond(429, ['error' => 'Terlalu banyak permintaan.']);
        }

        $ipRate = consume_rate_limit($db, 'status_api_ip', $ip, $ipLimit, $window);
        if (!$ipRate['allowed']) {
            notification_log('Fallback: rate limit per IP untuk ' . $orderId);
            header('Retry-After: ' . $ipRate['retry_after']);
            respond(429, ['error' => 'Terlalu banyak permintaan.']);
        }
    } catch (Throwable $error) {
        notification_log('Fallback: gagal memeriksa rate limit.');
        respond(500, ['error' => 'Terjadi kesalahan pada server.']);
    }

    $timeout = (int) ($config['status_api_timeout'] ?? 10);
    $api = fetch_midtrans_status(
        $orderId,
        $serverKey,
        (bool) ($config['is_production'] ?? false),
        $timeout > 0 ? $timeout : 10
    );

    notification_log(
        'Fallback status API ' . $orderId . ' -> HTTP ' . $api['http_status']
        . ($api['error'] !== null ? ' (' . $api['error'] . ')' : '')
    );

    if (!$api['ok']) {
        if ($api['error'] === 'not_found') {
            respond(200, ['status' => 'ok']);
        }
        if (in_array($api['error'], ['auth', 'http', 'invalid_json'], true)) {
            respond(500, ['error' => 'Terjadi kesalahan pada server.']);
        }
        respond(503, ['error' => 'Verifikasi pembayaran sedang tidak tersedia.']);
    }

    $order = find_order($db, $orderId);
    if ($order === null) {
        notification_log('Fallback: order tidak ditemukan di database ' . $orderId);
        respond(200, ['status' => 'ok']);
    }

    $result = apply_midtrans_status($db, $order, $api['data'], 'status_api');

    if ($result['result'] === 'mismatch') {
        notification_log(
            'Fallback: data tidak cocok untuk ' . $orderId
            . ' (' . (string) ($result['reason'] ?? 'unknown') . ')'
        );
    } elseif ($result['result'] === 'unmapped') {
        notification_log('Fallback: status tidak dipetakan untuk ' . $orderId);
    } elseif ($result['result'] === 'updated') {
        notification_log('Fallback: order ' . $orderId . ' -> ' . (string) $result['status']);
    } else {
        notification_log(
            'Fallback: order ' . $orderId . ' diabaikan karena status saat ini '
            . (string) ($order['status'] ?? 'unknown')
        );
    }

    respond(200, ['status' => 'ok']);
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
$payload = json_decode($rawBody === false ? '' : $rawBody, true);
if (!is_array($payload)) {
    respond(400, ['error' => 'Body JSON tidak valid.']);
}

if (!isset($payload['order_id']) || !is_string($payload['order_id'])) {
    respond(400, ['error' => 'Field order_id wajib diisi.']);
}

$orderId = $payload['order_id'];
if (!is_valid_order_id($orderId)) {
    respond(400, ['error' => 'order_id tidak valid.']);
}

$config = load_config();
$signatureSecretKey = trim((string) ($config['signature_secret_key'] ?? ''));

if ($signatureSecretKey === '') {
    notification_log('Signature Secret Key Midtrans belum diisi.');
    respond(500, ['error' => 'Konfigurasi pembayaran belum lengkap.']);
}

$signatureValid = verify_midtrans_signature($payload, $signatureSecretKey);
$fallbackEnabled = filter_var(
    $config['webhook_status_fallback'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);

if (!$signatureValid) {
    if ($fallbackEnabled) {
        handle_status_fallback($config, $orderId);
    }

    notification_log('Signature tidak valid untuk order ' . $orderId);
    respond(403, ['error' => 'Invalid signature.']);
}

try {
    $db = get_db();
} catch (Throwable $error) {
    notification_log('Gagal membuka database: ' . $error->getMessage());
    respond(500, ['error' => 'Terjadi kesalahan pada server.']);
}

$order = find_order($db, $orderId);
if ($order === null) {
    notification_log('Order tidak ditemukan: ' . $orderId);
    respond(200, ['status' => 'ignored', 'reason' => 'order_not_found']);
}

if ($order['status'] !== 'pending') {
    $previousStatus = (string) $order['status'];

    if (
        in_array($previousStatus, ['cancelled', 'expired'], true)
        && isset($payload['transaction_status'])
        && is_string($payload['transaction_status'])
        && $payload['transaction_status'] !== ''
    ) {
        $evaluation = evaluate_midtrans_status($order, $payload);

        if ($evaluation['result'] === 'mismatch') {
            notification_log(
                'Data transaksi tidak cocok untuk order ' . $orderId
                . ' (' . (string) ($evaluation['reason'] ?? 'unknown') . ')'
            );
            respond(400, ['error' => 'Gross amount mismatch.']);
        }

        if ($evaluation['result'] === 'apply' && ($evaluation['status'] ?? '') === 'paid') {
            $promoted = $previousStatus === 'cancelled'
                ? promote_cancelled_order_to_paid(
                    $db,
                    $orderId,
                    $evaluation['payment_type'] ?? null
                )
                : promote_expired_order_to_paid(
                    $db,
                    $orderId,
                    $evaluation['payment_type'] ?? null
                );

            if ($promoted) {
                notification_log(
                    'REVIEW: order ' . $orderId . ' berstatus ' . $previousStatus
                    . ' menerima settlement; status diubah menjadi paid untuk ditinjau.'
                );
                respond(200, ['status' => 'ok']);
            }
        }
    }

    notification_log(
        'Order ' . $orderId . ' diabaikan karena status saat ini ' . $order['status'] . '.'
    );
    respond(200, ['status' => 'ignored', 'reason' => 'not_pending']);
}

if (
    !isset($payload['transaction_status'])
    || !is_string($payload['transaction_status'])
    || $payload['transaction_status'] === ''
) {
    respond(400, ['error' => 'Field transaction_status wajib diisi.']);
}

$result = apply_midtrans_status($db, $order, $payload, 'signature');

if ($result['result'] === 'mismatch') {
    notification_log(
        'Data transaksi tidak cocok untuk order ' . $orderId
        . ' (' . (string) ($result['reason'] ?? 'unknown') . ')'
    );
    respond(400, ['error' => 'Gross amount mismatch.']);
}

if ($result['result'] === 'unmapped') {
    notification_log(
        'Status tidak dipetakan: ' . (string) $payload['transaction_status']
        . ' untuk order ' . $orderId
    );
    respond(200, ['status' => 'ignored', 'reason' => 'unmapped_status']);
}

if ($result['result'] === 'not_pending') {
    notification_log(
        'Order ' . $orderId . ' diabaikan karena status saat ini ' . $order['status'] . '.'
    );
    respond(200, ['status' => 'ignored', 'reason' => 'not_pending']);
}

notification_log(
    'Order ' . $orderId . ' -> ' . (string) $result['status']
    . ' (' . (string) ($payload['payment_type'] ?? '-') . ')'
);

respond(200, ['status' => 'ok']);
