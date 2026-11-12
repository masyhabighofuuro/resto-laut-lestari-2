<?php
declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = file_get_contents('php://input');

$logPath = getenv('RLL_FAKE_LOG');
if (is_string($logPath) && $logPath !== '') {
    file_put_contents(
        $logPath,
        json_encode([
            'method' => $method,
            'path' => $path,
            'body' => $body === false ? '' : $body,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

header('Content-Type: application/json; charset=utf-8');

if ($path === '/snap/v1/transactions') {
    http_response_code(201);
    echo json_encode([
        'token' => 'fake-snap-token',
        'redirect_url' => 'https://example.test/snap',
    ]);
    exit;
}

if (preg_match('#^/snap/v1/transactions/([^/]+)/cancel$#', $path, $matches) === 1) {
    $token = rawurldecode($matches[1]);

    if (str_contains($token, 'authfail')) {
        http_response_code(403);
        echo json_encode(['error_messages' => ['Access denied']]);
        exit;
    }

    if (str_contains($token, 'progress')) {
        echo json_encode(['error_messages' => ['Transaction is on progress']]);
        exit;
    }

    if (str_contains($token, 'notfound')) {
        echo json_encode(['error_messages' => ['token not found']]);
        exit;
    }

    echo json_encode(['canceled_at' => '2026-11-11T00:00:00.000Z']);
    exit;
}

if (preg_match('#^/v2/([^/]+)/status$#', $path, $matches) === 1) {
    $orderId = rawurldecode($matches[1]);

    if (str_contains($orderId, '000003')) {
        http_response_code(404);
        echo json_encode([
            'status_code' => '404',
            'status_message' => 'Transaction not found',
        ]);
        exit;
    }

    if (str_contains($orderId, '000014')) {
        http_response_code(200);
        echo json_encode([
            'status_code' => '404',
            'status_message' => "Transaction doesn't exist.",
        ]);
        exit;
    }

    if (str_contains($orderId, '000015')) {
        echo json_encode([
            'status_code' => '200',
            'status_message' => 'Success',
            'order_id' => $orderId,
            'transaction_status' => 'expire',
            'payment_type' => 'qris',
            'gross_amount' => '57000.00',
        ]);
        exit;
    }

    if (str_contains($orderId, '000016')) {
        echo json_encode([
            'status_code' => '200',
            'status_message' => 'Success',
            'order_id' => $orderId,
            'transaction_status' => 'pending',
            'payment_type' => 'qris',
            'gross_amount' => '1.00',
        ]);
        exit;
    }

    $paid = str_contains($orderId, '000002');
    $status = $paid ? 'settlement' : 'pending';

    echo json_encode([
        'status_code' => '200',
        'status_message' => 'Success',
        'order_id' => $orderId,
        'transaction_status' => $status,
        'fraud_status' => $paid ? 'accept' : 'accept',
        'payment_type' => 'qris',
        'gross_amount' => '57000.00',
        'expiry_time' => '2026-11-10 10:00:00',
    ]);
    exit;
}

if (preg_match('#^/v2/([^/]+)/cancel$#', $path, $matches) === 1) {
    $orderId = rawurldecode($matches[1]);

    if (str_contains($orderId, '000002') || str_contains($orderId, '000005')) {
        http_response_code(412);
        echo json_encode([
            'status_code' => '412',
            'status_message' => 'Transaction is already paid',
        ]);
        exit;
    }

    if (str_contains($orderId, '000003')) {
        http_response_code(404);
        echo json_encode([
            'status_code' => '404',
            'status_message' => 'Transaction not found',
        ]);
        exit;
    }

    if (
        str_contains($orderId, '000004')
        || str_contains($orderId, '000011')
        || str_contains($orderId, '000012')
        || str_contains($orderId, '000013')
    ) {
        http_response_code(403);
        echo json_encode([
            'status_code' => '403',
            'status_message' => 'Forbidden',
        ]);
        exit;
    }

    if (str_contains($orderId, '000006')) {
        http_response_code(500);
        echo json_encode([
            'status_code' => '500',
            'status_message' => 'Internal server error',
        ]);
        exit;
    }

    echo json_encode([
        'status_code' => '200',
        'status_message' => 'Success',
        'order_id' => $orderId,
        'transaction_status' => 'cancel',
    ]);
    exit;
}

http_response_code(404);
echo json_encode([
    'status_code' => '404',
    'status_message' => 'Not found',
]);
