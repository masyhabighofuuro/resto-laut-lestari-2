<?php
declare(strict_types=1);

function assert_true(string $name, bool $condition, int &$failures): void
{
    if ($condition) {
        echo 'PASS ' . $name . PHP_EOL;
        return;
    }

    echo 'FAIL ' . $name . PHP_EOL;
    $failures++;
}

/**
 * @param array{0: int, 1: string, 2: array<int, string>, 3: string} $response
 */
function assert_http_status(
    string $name,
    int $expectedStatus,
    array $response,
    int &$failures
): void {
    [$status, , , $error] = $response;
    if ($status === $expectedStatus) {
        echo 'PASS ' . $name . ' (HTTP ' . $status . ')' . PHP_EOL;
        return;
    }

    echo 'FAIL ' . $name . ' (HTTP ' . $status . ', expected ' . $expectedStatus
        . ', error ' . $error . ')' . PHP_EOL;
    $failures++;
}

function free_port(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        return 8123;
    }

    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    $position = strrpos($name, ':');
    return $position === false ? 8123 : (int) substr($name, $position + 1);
}

/**
 * @return array{0: resource, 1: int}
 */
function start_php_server(
    string $root,
    string $routerPath,
    string $logPath,
    array $environment
): array {
    $port = free_port();
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    foreach ($environment as $key => $value) {
        $env[$key] = (string) $value;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];

    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, $routerPath],
        $descriptors,
        $pipes,
        $root,
        $env,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Gagal menjalankan server test.');
    }

    fclose($pipes[0]);

    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($socket)) {
            fclose($socket);
            return [$process, $port];
        }
        usleep(50000);
    }

    stop_server($process);
    throw new RuntimeException('Server test tidak siap.');
}

function stop_server($process): void
{
    if (!is_resource($process)) {
        return;
    }

    $status = proc_get_status($process);
    if ($status['running'] ?? false) {
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
        } else {
            proc_terminate($process, 9);
        }
    }

    @proc_close($process);
}

/**
 * @return array{0: int, 1: string, 2: array<int, string>, 3: string}
 */
function http_request(
    string $method,
    string $url,
    ?array $payload = null,
    array $headers = [],
    array $cookies = [],
    bool $formEncoded = false
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return [0, '', [], 'curl_init'];
    }

    $responseHeaders = [];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$responseHeaders): int {
            $trimmed = trim($header);
            if ($trimmed !== '') {
                $responseHeaders[] = $trimmed;
            }
            return strlen($header);
        },
    ];

    if ($payload !== null) {
        $headers[] = $formEncoded
            ? 'Content-Type: application/x-www-form-urlencoded'
            : 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = $formEncoded
            ? http_build_query($payload)
            : json_encode($payload);
    }
    if ($headers !== []) {
        $options[CURLOPT_HTTPHEADER] = $headers;
    }
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $options[CURLOPT_COOKIE] = implode('; ', $pairs);
    }

    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [$status, $body === false ? '' : (string) $body, $responseHeaders, $error];
}

function header_values(array $headers, string $name): array
{
    $values = [];
    foreach ($headers as $header) {
        $position = strpos($header, ':');
        if ($position === false) {
            continue;
        }
        if (strcasecmp(trim(substr($header, 0, $position)), $name) === 0) {
            $values[] = trim(substr($header, $position + 1));
        }
    }

    return $values;
}

function cookie_value(?string $setCookie, string $name): ?string
{
    if ($setCookie === null) {
        return null;
    }

    if (preg_match('/^\s*' . preg_quote($name, '/') . '=([^;]+)/', $setCookie, $matches) !== 1) {
        return null;
    }

    return $matches[1];
}

function json_decode_body(string $body): array
{
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

function write_config(string $path, array $config): void
{
    file_put_contents(
        $path,
        "<?php\ndeclare(strict_types=1);\nreturn " . var_export($config, true) . ";\n"
    );
}

function remove_tree(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($directory);
}

function open_test_db(string $path): PDO
{
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    migrate_db($db);

    return $db;
}

function seed_order(
    PDO $db,
    string $orderId,
    string $token,
    string $status = 'pending',
    ?string $expiresAt = null,
    string $email = 'customer@example.test',
    string $phone = '081234567890',
    ?string $snapToken = 'fake-snap-token',
    ?string $paymentType = null
): void {
    save_order($db, [
        'order_id' => $orderId,
        'customer_name' => 'Test Customer',
        'customer_email' => $email,
        'customer_phone' => $phone,
        'gross_amount' => 57000,
        'device_hash' => device_hash($token),
        'expires_at' => $expiresAt,
    ], [
        [
            'id' => '1',
            'name' => 'Paket Hemat Duo',
            'price' => 57000,
            'quantity' => 1,
        ],
    ]);

    if ($snapToken !== null) {
        set_snap_token($db, $orderId, $snapToken);
    }

    if ($status !== 'pending') {
        update_order_status($db, $orderId, $status, $paymentType);
    }
}
