<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/config-loader.php';
require_once __DIR__ . '/device.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/midtrans.php';

$data = api_require_json_post();

$orderId = trim((string) ($data['order_id'] ?? ''));
$reasonCode = trim((string) ($data['reason_code'] ?? ''));
$reasonText = trim((string) ($data['reason_text'] ?? ''));

$allowedReasons = [
    'wrong_items',
    'change_order',
    'change_payment',
    'not_ordering',
    'payment_issue',
    'other',
];

if (!is_valid_order_id($orderId)) {
    api_respond(400, ['error' => 'order_id tidak valid.']);
}
if (!in_array($reasonCode, $allowedReasons, true)) {
    api_respond(400, ['error' => 'Alasan pembatalan tidak valid.']);
}
if ($reasonCode === 'other') {
    $length = mb_strlen($reasonText);
    if ($length < 5 || $length > 200) {
        api_respond(400, ['error' => 'Alasan "Lainnya" wajib diisi 5-200 karakter.']);
    }
} else {
    $reasonText = null;
}

$config = load_config();
$serverKey = trim((string) ($config['server_key'] ?? ''));
if ($serverKey === '') {
    api_log('cancel-order.log', 'Server Key Midtrans belum diisi.');
    api_respond(500, ['error' => 'Konfigurasi pembayaran belum lengkap.']);
}

$isProduction = (bool) ($config['is_production'] ?? false);
$statusTimeout = (int) ($config['status_api_timeout'] ?? 10);
$cancelTimeout = (int) ($config['cancel_api_timeout'] ?? 10);
$window = (int) ($config['rate_limit_window'] ?? 600);
$window = $window > 0 ? $window : 600;

try {
    $db = get_db();
} catch (Throwable $error) {
    api_log('cancel-order.log', 'Gagal membuka database: ' . $error->getMessage());
    api_respond(500, ['error' => 'Terjadi kesalahan pada server.']);
}

$ip = api_client_ip();
$ipLimit = max(1, (int) ($config['cancel_rate_limit_ip'] ?? 30));

try {
    $ipRate = consume_rate_limit($db, 'cancel_ip', $ip, $ipLimit, $window);
} catch (Throwable $error) {
    api_log('cancel-order.log', 'Gagal memeriksa rate limit IP.');
    api_respond(500, ['error' => 'Terjadi kesalahan pada server.']);
}

if (!$ipRate['allowed']) {
    header('Retry-After: ' . $ipRate['retry_after']);
    api_respond(429, ['error' => 'Terlalu banyak percobaan. Coba lagi nanti.']);
}

$deviceHash = device_hash_from_cookie();
if ($deviceHash === null) {
    api_respond(404, ['error' => 'Pesanan tidak ditemukan.']);
}

$deviceLimit = max(1, (int) ($config['cancel_rate_limit_device'] ?? 10));

try {
    $deviceRate = consume_rate_limit(
        $db,
        'cancel_device',
        $deviceHash,
        $deviceLimit,
        $window
    );
} catch (Throwable $error) {
    api_log('cancel-order.log', 'Gagal memeriksa rate limit perangkat.');
    api_respond(500, ['error' => 'Terjadi kesalahan pada server.']);
}

if (!$deviceRate['allowed']) {
    header('Retry-After: ' . $deviceRate['retry_after']);
    api_respond(429, ['error' => 'Terlalu banyak percobaan. Coba lagi nanti.']);
}

$order = find_order($db, $orderId);
$orderDeviceHash = $order === null ? '' : (string) ($order['device_hash'] ?? '');
if ($order === null || !hash_equals($orderDeviceHash, $deviceHash)) {
    api_respond(404, ['error' => 'Pesanan tidak ditemukan.']);
}

$localStatusError = static function (string $status): string {
    return match ($status) {
        'paid' => 'Pesanan sudah dibayar.',
        'expired' => 'Pesanan sudah kedaluwarsa.',
        'cancelled' => 'Pesanan sudah dibatalkan.',
        'failed' => 'Pesanan gagal; tidak ada pembayaran yang perlu dibatalkan.',
        default => 'Pesanan tidak dapat dibatalkan.',
    };
};

if ($order['status'] !== 'pending') {
    api_respond(409, ['error' => $localStatusError((string) $order['status'])]);
}

if (is_order_expired($order)) {
    update_order_status($db, $orderId, 'expired', null, [
        'cancel_reason_code' => 'expired',
        'cancel_reason_text' => 'Batas waktu pembayaran habis.',
    ]);
    api_respond(409, ['error' => 'Pesanan sudah kedaluwarsa.']);
}

$status = fetch_midtrans_status($orderId, $serverKey, $isProduction, $statusTimeout);

if ($status['ok']) {
    $evaluation = evaluate_midtrans_status($order, $status['data']);

    if ($evaluation['result'] === 'mismatch') {
        api_log(
            'cancel-order.log',
            'Status API ' . $orderId . ' HTTP ' . $status['http_status']
            . ' data tidak cocok (' . (string) ($evaluation['reason'] ?? 'unknown') . ')'
        );
        api_respond(409, ['error' => 'Data pesanan tidak cocok dengan Midtrans. Silakan hubungi penjual.']);
    }

    if ($evaluation['result'] === 'invalid') {
        api_log(
            'cancel-order.log',
            'Status API ' . $orderId . ' HTTP ' . $status['http_status']
            . ' respons tidak valid (' . (string) ($evaluation['reason'] ?? 'unknown') . ')'
        );
        api_respond(503, ['error' => 'Pembatalan sedang tidak tersedia. Coba lagi.']);
    }

    if ($evaluation['result'] === 'apply') {
        $remoteStatus = (string) ($evaluation['status'] ?? '');

        if ($remoteStatus === 'paid') {
            update_order_status(
                $db,
                $orderId,
                'paid',
                $evaluation['payment_type'] ?? null,
                $evaluation['meta'] ?? []
            );
            api_respond(409, ['error' => 'Pesanan sudah dibayar.']);
        }

        if (in_array($remoteStatus, ['cancelled', 'expired', 'failed'], true)) {
            update_order_status(
                $db,
                $orderId,
                $remoteStatus,
                $evaluation['payment_type'] ?? null,
                $evaluation['meta'] ?? []
            );
            $remoteMessage = match ($remoteStatus) {
                'cancelled' => 'Pesanan sudah dibatalkan di Midtrans.',
                'expired' => 'Pesanan sudah kedaluwarsa di Midtrans.',
                'failed' => 'Pesanan gagal di Midtrans.',
                default => 'Pesanan tidak dapat dibatalkan.',
            };
            api_respond(409, ['error' => $remoteMessage]);
        }
    }
} else {
    if ($status['error'] === 'not_found') {
        api_log(
            'cancel-order.log',
            'Status API ' . $orderId . ' HTTP ' . $status['http_status']
            . ' transaksi tidak ditemukan; mencoba Cancel API'
        );
    }

    if ($status['error'] === 'auth') {
        api_log(
            'cancel-order.log',
            'Status API menolak key untuk ' . $orderId . ' (izin Core API: Read?)'
        );
        api_respond(500, ['error' => 'Gagal membatalkan pesanan. Silakan hubungi penjual.']);
    }

    if (in_array($status['error'], ['network', 'server', 'http', 'invalid_json', 'invalid_response'], true)) {
        api_respond(503, ['error' => 'Pembatalan sedang tidak tersedia. Coba lagi.']);
    }
}

$cancel = cancel_midtrans_transaction($orderId, $serverKey, $isProduction, $cancelTimeout);

$markCancelled = static function () use ($db, $orderId, $reasonCode, $reasonText): bool {
    return update_order_status($db, $orderId, 'cancelled', null, [
        'cancel_reason_code' => $reasonCode,
        'cancel_reason_text' => $reasonText,
        'cancelled_by' => 'customer',
    ]);
};

$respondCancelled = static function () use (
    $db,
    $orderId,
    $markCancelled,
    $localStatusError
): void {
    if (!$markCancelled()) {
        $current = find_order($db, $orderId);
        $currentStatus = $current === null ? '' : (string) ($current['status'] ?? '');
        api_respond(409, ['error' => $localStatusError($currentStatus)]);
    }

    api_respond(200, ['status' => 'cancelled', 'order_id' => $orderId]);
};

if ($cancel['ok'] || $cancel['error'] === 'not_found') {
    if ($cancel['error'] === 'not_found') {
        api_log('cancel-order.log', 'Cancel API 404, ditandai cancelled lokal: ' . $orderId);
    }

    $respondCancelled();
}

if ($cancel['error'] === 'auth') {
    api_log(
        'cancel-order.log',
        'Cancel API menolak key untuk ' . $orderId
        . ' (HTTP ' . $cancel['http_status'] . '; izin Cancel transaction: Write?)'
    );
}

$snapToken = trim((string) ($order['snap_token'] ?? ''));

if (in_array($cancel['error'], ['auth', 'http'], true) && $snapToken !== '') {
    $snapCancel = cancel_snap_session($snapToken, $serverKey, $isProduction, $cancelTimeout);

    api_log(
        'cancel-order.log',
        'Cancel Snap session ' . $orderId . ' HTTP ' . $snapCancel['http_status']
        . ($snapCancel['error'] !== null ? ' (' . $snapCancel['error'] . ')' : '')
    );

    if ($snapCancel['ok'] || $snapCancel['error'] === 'not_found') {
        $respondCancelled();
    }

    if ($snapCancel['error'] === 'in_progress') {
        api_respond(409, ['error' => 'Pesanan tidak dapat dibatalkan karena pembayaran sedang diproses.']);
    }

    if ($snapCancel['error'] === 'auth') {
        api_log(
            'cancel-order.log',
            'Cancel Snap session menolak key untuk ' . $orderId
            . ' (HTTP ' . $snapCancel['http_status'] . ')'
        );
        api_respond(500, ['error' => 'Gagal membatalkan pesanan. Silakan hubungi penjual.']);
    }

    if (in_array($snapCancel['error'], ['network', 'server', 'invalid_json'], true)) {
        api_respond(503, ['error' => 'Pembatalan sedang tidak tersedia. Coba lagi.']);
    }
}

if ($cancel['error'] === 'auth') {
    api_respond(500, ['error' => 'Gagal membatalkan pesanan. Silakan hubungi penjual.']);
}

if ($cancel['error'] === 'conflict') {
    $recheck = fetch_midtrans_status($orderId, $serverKey, $isProduction, $statusTimeout);

    if ($recheck['ok']) {
        $evaluation = evaluate_midtrans_status($order, $recheck['data']);
        if ($evaluation['result'] === 'apply') {
            $remoteStatus = (string) ($evaluation['status'] ?? '');

            if ($remoteStatus === 'paid') {
                update_order_status(
                    $db,
                    $orderId,
                    'paid',
                    $evaluation['payment_type'] ?? null,
                    $evaluation['meta'] ?? []
                );
                api_respond(409, ['error' => 'Pesanan sudah dibayar.']);
            }

            if (in_array($remoteStatus, ['cancelled', 'expired', 'failed'], true)) {
                update_order_status(
                    $db,
                    $orderId,
                    $remoteStatus,
                    $evaluation['payment_type'] ?? null,
                    $evaluation['meta'] ?? []
                );
                api_respond(409, ['error' => $localStatusError($remoteStatus)]);
            }
        }
    }

    api_respond(409, ['error' => 'Status pesanan berubah. Muat ulang lalu coba lagi.']);
}

if (in_array($cancel['error'], ['network', 'server', 'http', 'invalid_json'], true)) {
    api_respond(503, ['error' => 'Pembatalan sedang tidak tersedia. Coba lagi.']);
}

api_log('cancel-order.log', 'Cancel API gagal untuk ' . $orderId);
api_respond(500, ['error' => 'Gagal membatalkan pesanan. Silakan coba lagi.']);
