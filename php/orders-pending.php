<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/config-loader.php';
require_once __DIR__ . '/device.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/midtrans.php';

/**
 * Menyegarkan status pesanan pending lewat Core API, dibatasi per order agar
 * pembukaan panel berulang tidak memanggil Midtrans terus-menerus.
 *
 * @param array<string, mixed> $config
 */
function refresh_pending_orders(PDO $db, string $deviceHash, array $config): void
{
    $serverKey = trim((string) ($config['server_key'] ?? ''));
    if ($serverKey === '') {
        return;
    }

    $limit = (int) ($config['pending_refresh_limit'] ?? 3);
    $limit = max(0, min(10, $limit));
    if ($limit === 0) {
        return;
    }

    $minInterval = (int) ($config['pending_refresh_min_interval'] ?? 30);
    $minInterval = max(30, $minInterval);

    $timeout = (int) ($config['status_api_timeout'] ?? 10);
    $timeout = max(1, min(15, $timeout));

    $isProduction = (bool) ($config['is_production'] ?? false);

    foreach (find_pending_orders_for_device($db, $deviceHash, $limit) as $order) {
        $orderId = (string) $order['order_id'];

        if (status_check_is_fresh($db, $orderId, $minInterval)) {
            continue;
        }

        mark_status_checked($db, $orderId);

        $api = fetch_midtrans_status($orderId, $serverKey, $isProduction, $timeout);
        if (!$api['ok']) {
            api_log(
                'orders-pending.log',
                'Refresh status ' . $orderId . ' HTTP ' . $api['http_status']
                . ' (' . (string) $api['error'] . ')'
            );
            continue;
        }

        $evaluation = evaluate_midtrans_status($order, $api['data']);

        if ($evaluation['result'] !== 'apply') {
            api_log(
                'orders-pending.log',
                'Refresh status ' . $orderId . ' ' . $evaluation['result']
                . (isset($evaluation['reason']) ? ' (' . (string) $evaluation['reason'] . ')' : '')
            );
            continue;
        }

        if (($evaluation['status'] ?? '') === 'pending') {
            continue;
        }

        $result = apply_midtrans_status($db, $order, $api['data'], 'orders_pending_refresh');

        if ($result['result'] === 'updated') {
            api_log(
                'orders-pending.log',
                'Refresh status ' . $orderId . ' -> ' . (string) $result['status']
            );
        }
    }
}

api_require_method('GET');

$deviceHash = device_hash_from_cookie();
if ($deviceHash === null) {
    api_respond(200, ['orders' => [], 'count' => 0]);
}

try {
    $db = get_db();
    expire_pending_orders($db, $deviceHash);

    if (($_GET['refresh'] ?? '') === '1') {
        try {
            refresh_pending_orders($db, $deviceHash, load_config());
        } catch (Throwable $error) {
            api_log('orders-pending.log', 'Refresh status gagal: ' . $error->getMessage());
        }
    }

    $orders = find_pending_orders_for_device($db, $deviceHash);
} catch (Throwable $error) {
    api_log('orders-pending.log', 'Gagal membaca pesanan: ' . $error->getMessage());
    api_respond(500, ['error' => 'Terjadi kesalahan pada server.']);
}

$result = [];
foreach ($orders as $order) {
    $items = find_order_items($db, (string) $order['order_id']);
    $itemDetails = [];
    $itemCount = 0;

    foreach ($items as $item) {
        $qty = (int) $item['qty'];
        $itemCount += $qty;
        $itemDetails[] = [
            'name' => (string) $item['name'],
            'qty' => $qty,
            'price' => (int) $item['price'],
        ];
    }

    $snapToken = isset($order['snap_token']) && is_string($order['snap_token'])
        && $order['snap_token'] !== ''
        ? $order['snap_token']
        : null;

    $result[] = [
        'order_id' => (string) $order['order_id'],
        'created_at' => utc_db_to_iso((string) $order['created_at']),
        'expires_at' => utc_db_to_iso((string) ($order['expires_at'] ?? '')),
        'gross_amount' => (int) $order['gross_amount'],
        'item_count' => $itemCount,
        'items' => $itemDetails,
        'snap_token' => $snapToken,
    ];
}

api_respond(200, ['orders' => $result, 'count' => count($result)]);
