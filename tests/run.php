<?php
declare(strict_types=1);

$tests = [
    'unit-signature.php',
    'unit-midtrans.php',
    'unit-orders.php',
    'unit-receipt.php',
    'http-webhook.php',
    'http-pending.php',
    'http-cancel.php',
    'http-lookup.php',
    'http-receipt.php',
    'cli-reconcile.php',
];

$environment = getenv();
if (!is_array($environment)) {
    $environment = [];
}
$environment['RLL_ENV'] = 'test';

$failures = 0;

foreach ($tests as $test) {
    $path = __DIR__ . '/' . $test;
    echo '=== ' . $test . ' ===' . PHP_EOL;

    $process = proc_open(
        [PHP_BINARY, $path],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__),
        $environment
    );

    if (!is_resource($process)) {
        echo 'FAIL tidak bisa menjalankan ' . $test . PHP_EOL;
        $failures++;
        continue;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($stdout !== '') {
        echo $stdout;
    }
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }

    if ($exitCode !== 0) {
        echo 'FAIL ' . $test . ' (exit ' . $exitCode . ')' . PHP_EOL;
        $failures++;
    }
}

echo $failures === 0
    ? 'SEMUA TEST LULUS' . PHP_EOL
    : 'TEST GAGAL: ' . $failures . PHP_EOL;

exit($failures === 0 ? 0 : 1);
