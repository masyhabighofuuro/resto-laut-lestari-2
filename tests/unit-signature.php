<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/orders.php';

$failures = 0;

function check(string $name, bool $condition, int &$failures): void
{
    if ($condition) {
        echo 'PASS ' . $name . PHP_EOL;
        return;
    }

    echo 'FAIL ' . $name . PHP_EOL;
    $failures++;
}

$orderId = 'RLL-20261110-1e420c';
$statusCode = '200';
$grossAmount = '271000.00';
$signatureSecretKey = 'unit-test-signature-secret';
$serverKey = 'SB-Mid-server-unit-test-server-key';
$unsigned = $orderId . $statusCode . $grossAmount;

$payload = [
    'order_id' => $orderId,
    'status_code' => $statusCode,
    'gross_amount' => $grossAmount,
    'signature_key' => hash('sha512', $unsigned . $signatureSecretKey),
    'transaction_status' => 'settlement',
    'payment_type' => 'qris',
];

check(
    'signature valid memakai signature_secret_key',
    verify_midtrans_signature($payload, $signatureSecretKey),
    $failures
);

$serverKeyPayload = $payload;
$serverKeyPayload['signature_key'] = hash('sha512', $unsigned . $serverKey);
check(
    'signature yang dihitung dengan server_key ditolak',
    !verify_midtrans_signature($serverKeyPayload, $signatureSecretKey),
    $failures
);

$wrongAmountPayload = $payload;
$wrongAmountPayload['gross_amount'] = '271000';
check(
    'format gross_amount harus persis sama',
    !verify_midtrans_signature($wrongAmountPayload, $signatureSecretKey),
    $failures
);

$wrongStatusPayload = $payload;
$wrongStatusPayload['status_code'] = '201';
check(
    'status_code yang berbeda ditolak',
    !verify_midtrans_signature($wrongStatusPayload, $signatureSecretKey),
    $failures
);

$missingSignature = $payload;
unset($missingSignature['signature_key']);
check(
    'signature_key kosong ditolak',
    !verify_midtrans_signature($missingSignature, $signatureSecretKey),
    $failures
);

check('settlement dipetakan ke paid', map_transaction_status('settlement') === 'paid', $failures);
check('capture accept dipetakan ke paid', map_transaction_status('capture', 'accept') === 'paid', $failures);
check('capture deny dipetakan ke failed', map_transaction_status('capture', 'deny') === 'failed', $failures);
check('pending dipetakan ke pending', map_transaction_status('pending') === 'pending', $failures);
check('cancel dipetakan ke cancelled', map_transaction_status('cancel') === 'cancelled', $failures);
check('expire dipetakan ke expired', map_transaction_status('expire') === 'expired', $failures);
check('status tidak dikenal menjadi null', map_transaction_status('unknown') === null, $failures);
check('normalize 271000.00 menjadi 271000', normalize_gross_amount('271000.00') === 271000, $failures);

echo $failures === 0
    ? 'UNIT SIGNATURE: OK' . PHP_EOL
    : 'UNIT SIGNATURE: ' . $failures . ' gagal' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
