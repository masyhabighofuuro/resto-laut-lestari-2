<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit(1);
}

require_once __DIR__ . '/midtrans.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/config-loader.php';

function describe_status_api_error(int $httpStatus, ?string $error): string
{
    if ($httpStatus === 403) {
        return 'izin key kurang (Core API: Read?)';
    }
    if ($httpStatus === 401) {
        return 'key tidak valid atau tidak cocok environment';
    }
    if ($httpStatus === 404 || $error === 'not_found') {
        return 'order tidak ditemukan di Midtrans';
    }
    if ($httpStatus >= 500 || $error === 'server') {
        return 'Midtrans server error';
    }
    if ($error === 'network') {
        return 'koneksi ke Midtrans gagal';
    }
    if ($error === 'invalid_json' || $error === 'invalid_response') {
        return 'respons Midtrans tidak valid';
    }
    if ($error !== null && $error !== '') {
        return $error;
    }

    return 'ok';
}

function reconcile_usage(): void
{
    echo "Penggunaan: php php/reconcile.php [opsi]\n";
    echo "  --order=ORDER_ID   Periksa satu order tertentu\n";
    echo "  --limit=50         Maksimum order pending (default 50)\n";
    echo "  --min-age=300      Umur minimum order dalam detik (default 300)\n";
    echo "  --sleep=250        Jeda antar panggilan API dalam milidetik\n";
    echo "  --include-expired  Ikut periksa order expired (promosi paid perlu REVIEW)\n";
    echo "  --dry-run          Panggil status API tanpa menulis database\n";
    echo "  --help             Tampilkan bantuan\n";
}

$options = getopt('', [
    'order::',
    'limit::',
    'min-age::',
    'sleep::',
    'include-expired',
    'dry-run',
    'help',
]);

if (isset($options['help'])) {
    reconcile_usage();
    exit(0);
}

$limit = max(1, min(500, isset($options['limit']) ? (int) $options['limit'] : 50));
$minAge = max(0, isset($options['min-age']) ? (int) $options['min-age'] : 300);
$sleepMs = max(0, isset($options['sleep']) ? (int) $options['sleep'] : 250);
$dryRun = isset($options['dry-run']);
$includeExpired = isset($options['include-expired']);
$targetOrderId = isset($options['order']) ? (string) $options['order'] : null;

$config = load_config();
$serverKey = trim((string) ($config['server_key'] ?? ''));

if ($serverKey === '') {
    fwrite(STDERR, "Server Key Midtrans belum diisi.\n");
    exit(1);
}

$isProduction = (bool) ($config['is_production'] ?? false);
$timeout = (int) ($config['status_api_timeout'] ?? 10);
$timeout = $timeout > 0 ? $timeout : 10;

try {
    $db = get_db();
} catch (Throwable $error) {
    fwrite(STDERR, "Gagal membuka database.\n");
    exit(1);
}

if ($targetOrderId !== null) {
    if (!is_valid_order_id($targetOrderId)) {
        fwrite(STDERR, "Format order_id tidak valid.\n");
        exit(1);
    }

    $order = find_order($db, $targetOrderId);
    if ($order === null) {
        fwrite(STDERR, "Order tidak ditemukan.\n");
        exit(1);
    }

    $orders = [$order];
} else {
    $orders = find_pending_orders($db, $minAge, $limit);

    if ($includeExpired) {
        $remaining = max(0, $limit - count($orders));
        if ($remaining > 0) {
            $orders = array_merge($orders, find_expired_orders($db, $minAge, $remaining));
        }
    }
}

if ($orders === []) {
    echo $includeExpired
        ? "Tidak ada order pending/expired yang perlu diperiksa.\n"
        : "Tidak ada order pending yang perlu diperiksa.\n";
    exit(0);
}

echo 'Reconcile' . ($dryRun ? ' (dry-run)' : '') . ' untuk ' . count($orders) . " order.\n";

$counts = [
    'checked' => 0,
    'updated' => 0,
    'promoted' => 0,
    'unchanged' => 0,
    'not_found' => 0,
    'mismatch' => 0,
    'unmapped' => 0,
    'invalid' => 0,
    'errors' => 0,
];
$apiCalls = 0;

foreach ($orders as $order) {
    $orderId = (string) $order['order_id'];
    $localStatus = (string) ($order['status'] ?? '');
    $counts['checked']++;

    echo '- ' . $orderId . "\n";

    if (
        !in_array($localStatus, ['pending', 'expired'], true)
        || ($localStatus === 'expired' && !$includeExpired)
    ) {
        echo "  dilewati: bukan pending/expired yang diminta\n";
        $counts['unchanged']++;
        continue;
    }

    if ($apiCalls > 0 && $sleepMs > 0) {
        usleep($sleepMs * 1000);
    }

    $api = fetch_midtrans_status($orderId, $serverKey, $isProduction, $timeout);
    $apiCalls++;

    $errorLabel = describe_status_api_error($api['http_status'], $api['error']);

    echo '  status_api HTTP ' . $api['http_status']
        . ($api['error'] !== null ? ' (' . $errorLabel . ')' : '') . "\n";

    if (!$api['ok']) {
        midtrans_log(
            'reconcile ' . $orderId . ' HTTP ' . $api['http_status']
            . ' - ' . $errorLabel
        );

        if ($api['error'] === 'not_found') {
            $counts['not_found']++;
            echo "  status: tidak ditemukan di Midtrans\n";
        } else {
            $counts['errors']++;
            echo "  status: gagal verifikasi\n";
        }
        continue;
    }

    $evaluation = evaluate_midtrans_status($order, $api['data']);

    if ($evaluation['result'] === 'mismatch') {
        $counts['mismatch']++;
        echo '  status: data tidak cocok (' . (string) ($evaluation['reason'] ?? 'unknown') . ")\n";
        midtrans_log(
            'reconcile ' . $orderId . ' mismatch '
            . (string) ($evaluation['reason'] ?? 'unknown')
        );
        continue;
    }

    if ($evaluation['result'] === 'invalid') {
        $counts['invalid']++;
        echo '  status: respons tidak valid (' . (string) ($evaluation['reason'] ?? 'unknown') . ")\n";
        continue;
    }

    if ($evaluation['result'] === 'unmapped') {
        $counts['unmapped']++;
        echo "  status: tidak dipetakan\n";
        continue;
    }

    $targetStatus = (string) $evaluation['status'];
    echo '  status: ' . (string) ($api['data']['transaction_status'] ?? '-')
        . ' -> ' . $targetStatus . "\n";

    if ($localStatus === 'expired') {
        if ($targetStatus !== 'paid') {
            $counts['unchanged']++;
            echo "  hasil: status lokal expired dipertahankan\n";
            continue;
        }

        if ($dryRun) {
            $counts['unchanged']++;
            echo "  dry-run: akan dipromosikan ke paid (REVIEW), database tidak diubah\n";
            continue;
        }

        $promoted = promote_expired_order_to_paid(
            $db,
            $orderId,
            $evaluation['payment_type'] ?? null
        );

        if ($promoted) {
            $counts['promoted']++;
            echo "  hasil: dipromosikan ke paid (REVIEW)\n";
            midtrans_log('reconcile REVIEW ' . $orderId . ' expired -> paid');
        } else {
            $counts['errors']++;
            echo "  hasil: gagal mempromosikan (status berubah?)\n";
        }
        continue;
    }

    if ($dryRun) {
        $counts['unchanged']++;
        echo "  dry-run: database tidak diubah\n";
        continue;
    }

    $applied = apply_midtrans_status($db, $order, $api['data'], 'reconcile');

    if ($applied['result'] === 'updated') {
        $counts['updated']++;
        echo '  hasil: diperbarui ke ' . $targetStatus . "\n";
    } elseif ($applied['result'] === 'not_pending') {
        $counts['unchanged']++;
        echo "  hasil: diabaikan (bukan pending)\n";
    } else {
        $counts['errors']++;
        echo '  hasil: ' . $applied['result'] . "\n";
    }
}

echo 'Ringkasan: checked=' . $counts['checked']
    . ' updated=' . $counts['updated']
    . ' promoted=' . $counts['promoted']
    . ' unchanged=' . $counts['unchanged']
    . ' not_found=' . $counts['not_found']
    . ' mismatch=' . $counts['mismatch']
    . ' unmapped=' . $counts['unmapped']
    . ' invalid=' . $counts['invalid']
    . ' errors=' . $counts['errors'] . "\n";

exit(
    $counts['errors'] > 0
    || $counts['mismatch'] > 0
    || $counts['invalid'] > 0
        ? 1
        : 0
);
