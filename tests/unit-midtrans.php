<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/midtrans.php';
require_once __DIR__ . '/support.php';

$failures = 0;

$notFound = midtrans_status_response_error([
    'status_code' => '404',
    'status_message' => "Transaction doesn't exist.",
]);
assert_true(
    'body 404 dikenali sebagai not_found',
    $notFound === 'not_found',
    $failures
);

$valid = midtrans_status_response_error([
    'status_code' => '200',
    'status_message' => 'Success',
    'order_id' => 'RLL-20261111-abcdef',
    'transaction_status' => 'settlement',
    'gross_amount' => '57000.00',
]);
assert_true('body status valid tidak dianggap error', $valid === null, $failures);

$missing = midtrans_status_response_error([
    'status_code' => '200',
    'status_message' => 'Success',
]);
assert_true(
    'transaction_status hilang dianggap respons tidak valid',
    $missing === 'invalid_response',
    $failures
);

echo $failures === 0
    ? 'UNIT MIDTRANS: OK' . PHP_EOL
    : 'UNIT MIDTRANS: ' . $failures . ' gagal' . PHP_EOL;

exit($failures === 0 ? 0 : 1);
