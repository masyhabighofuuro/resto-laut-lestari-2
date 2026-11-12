<?php
declare(strict_types=1);

/**
 * Menulis detail error ke file log. Jangan pernah mengirim detail ini ke client.
 */
function midtrans_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/midtrans.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * @return array<int, mixed>
 */
function midtrans_tls_options(): array
{
    $options = [
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    // Gunakan CA bundle yang dikonfigurasi php.ini / environment bila tersedia.
    $caBundle = ini_get('curl.cainfo') ?: getenv('CURL_CA_BUNDLE');
    if (is_string($caBundle) && $caBundle !== '' && is_file($caBundle)) {
        $options[CURLOPT_CAINFO] = $caBundle;
    }

    return $options;
}

function midtrans_snap_base_url(bool $isProduction): string
{
    if (getenv('RLL_ENV') === 'test') {
        $override = getenv('RLL_MIDTRANS_SNAP_BASE_URL');
        if (is_string($override) && trim($override) !== '') {
            return rtrim(trim($override), '/');
        }
    }

    return $isProduction
        ? 'https://app.midtrans.com'
        : 'https://app.sandbox.midtrans.com';
}

function midtrans_api_base_url(bool $isProduction): string
{
    if (getenv('RLL_ENV') === 'test') {
        $override = getenv('RLL_MIDTRANS_API_BASE_URL');
        if (is_string($override) && trim($override) !== '') {
            return rtrim(trim($override), '/');
        }
    }

    return $isProduction
        ? 'https://api.midtrans.com'
        : 'https://api.sandbox.midtrans.com';
}

/**
 * Membuat transaksi Snap dan mengembalikan token.
 *
 * @param array<string, mixed> $payload
 * @return array{token: string}
 * @throws RuntimeException
 */
function create_snap_transaction(array $payload, string $serverKey, bool $isProduction): array
{
    $baseUrl = midtrans_snap_base_url($isProduction);

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('Gagal meng-encode payload transaksi.');
    }

    $ch = curl_init($baseUrl . '/snap/v1/transactions');
    if ($ch === false) {
        throw new RuntimeException('Gagal menginisialisasi cURL.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_USERPWD => $serverKey . ':',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ] + midtrans_tls_options();

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Koneksi ke Midtrans gagal: ' . $curlError);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Respons Midtrans tidak valid (HTTP ' . $httpStatus . ').');
    }

    if ($httpStatus < 200 || $httpStatus >= 300 || empty($decoded['token'])) {
        $detail = $decoded['error_messages'] ?? $decoded['status_message'] ?? 'tanpa detail';
        if (is_array($detail)) {
            $detail = implode('; ', $detail);
        }
        throw new RuntimeException(
            'Midtrans menolak transaksi (HTTP ' . $httpStatus . '): ' . $detail
        );
    }

    return ['token' => (string) $decoded['token']];
}

/**
 * Midtrans dapat mengirim error di body dengan HTTP 200 (mis. status_code 404).
 *
 * @param array<string, mixed> $data
 */
function midtrans_status_response_error(array $data): ?string
{
    $bodyStatus = isset($data['status_code']) && is_scalar($data['status_code'])
        ? trim((string) $data['status_code'])
        : '';
    $message = isset($data['status_message']) && is_string($data['status_message'])
        ? trim($data['status_message'])
        : '';
    $transactionStatus = isset($data['transaction_status']) && is_string($data['transaction_status'])
        ? trim($data['transaction_status'])
        : '';

    if (
        $bodyStatus === '404'
        || stripos($message, "doesn't exist") !== false
        || stripos($message, 'not found') !== false
    ) {
        return 'not_found';
    }

    if ($bodyStatus !== '' && !in_array($bodyStatus, ['200', '201'], true)) {
        if ($bodyStatus === '401' || $bodyStatus === '403') {
            return 'auth';
        }
        if ((int) $bodyStatus >= 500) {
            return 'server';
        }

        return 'http';
    }

    if ($transactionStatus === '') {
        return 'invalid_response';
    }

    return null;
}

/**
 * Mengambil status transaksi langsung dari API Midtrans.
 *
 * @return array{ok: bool, http_status: int, data: ?array, error: ?string}
 */
function fetch_midtrans_status(
    string $orderId,
    string $serverKey,
    bool $isProduction,
    int $timeoutSeconds = 10
): array {
    $baseUrl = midtrans_api_base_url($isProduction);

    $ch = curl_init($baseUrl . '/v2/' . rawurlencode($orderId) . '/status');
    if ($ch === false) {
        return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'curl_init'];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_USERPWD => $serverKey . ':',
        CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeoutSeconds)),
        CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'resto-laut-lestari/1.0',
    ] + midtrans_tls_options();

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'network'];
    }

    if ($httpStatus === 404) {
        return ['ok' => false, 'http_status' => 404, 'data' => null, 'error' => 'not_found'];
    }

    if ($httpStatus === 401 || $httpStatus === 403) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => null, 'error' => 'auth'];
    }

    if ($httpStatus >= 500) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => null, 'error' => 'server'];
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => null, 'error' => 'http'];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => null, 'error' => 'invalid_json'];
    }

    $bodyError = midtrans_status_response_error($decoded);
    if ($bodyError !== null) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => null, 'error' => $bodyError];
    }

    return ['ok' => true, 'http_status' => $httpStatus, 'data' => $decoded, 'error' => null];
}

/**
 * Membatalkan transaksi Midtrans yang masih pending.
 *
 * @return array{ok: bool, http_status: int, data: ?array, error: ?string}
 */
function cancel_midtrans_transaction(
    string $orderId,
    string $serverKey,
    bool $isProduction,
    int $timeoutSeconds = 10
): array {
    $baseUrl = midtrans_api_base_url($isProduction);

    $ch = curl_init($baseUrl . '/v2/' . rawurlencode($orderId) . '/cancel');
    if ($ch === false) {
        return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'curl_init'];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_USERPWD => $serverKey . ':',
        CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeoutSeconds)),
        CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_USERAGENT => 'resto-laut-lestari/1.0',
    ] + midtrans_tls_options();

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'network'];
    }

    $decoded = json_decode($response, true);
    $data = is_array($decoded) ? $decoded : null;

    if ($httpStatus === 404) {
        return ['ok' => false, 'http_status' => 404, 'data' => $data, 'error' => 'not_found'];
    }

    if ($httpStatus === 401 || $httpStatus === 403) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => $data, 'error' => 'auth'];
    }

    if ($httpStatus === 412) {
        return ['ok' => false, 'http_status' => 412, 'data' => $data, 'error' => 'conflict'];
    }

    if ($httpStatus >= 500) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => $data, 'error' => 'server'];
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => $data, 'error' => 'http'];
    }

    if ($data === null) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => null, 'error' => 'invalid_json'];
    }

    return ['ok' => true, 'http_status' => $httpStatus, 'data' => $data, 'error' => null];
}

/**
 * Membatalkan sesi Snap. Mengikuti dokumentasi Midtrans "Cancel a Snap Session":
 * POST {snap}/snap/v1/transactions/{token}/cancel dengan header
 * "Authorization: {merchant_server_key}" (bukan Basic). Respons sukses berisi
 * canceled_at; error_messages dapat berisi "token already canceled",
 * "Transaction is on progress", atau "token not found".
 *
 * @return array{ok: bool, http_status: int, data: ?array, error: ?string}
 */
function cancel_snap_session(
    string $snapToken,
    string $serverKey,
    bool $isProduction,
    int $timeoutSeconds = 10
): array {
    $snapToken = trim($snapToken);
    if ($snapToken === '') {
        return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'not_found'];
    }

    $baseUrl = midtrans_snap_base_url($isProduction);
    $ch = curl_init(
        $baseUrl . '/snap/v1/transactions/' . rawurlencode($snapToken) . '/cancel'
    );
    if ($ch === false) {
        return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'curl_init'];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_CONNECTTIMEOUT => min(5, max(1, $timeoutSeconds)),
        CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: ' . $serverKey,
        ],
        CURLOPT_USERAGENT => 'resto-laut-lestari/1.0',
    ] + midtrans_tls_options();

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'network'];
    }

    $decoded = json_decode($response, true);
    $data = is_array($decoded) ? $decoded : null;

    $messages = [];
    if ($data !== null && isset($data['error_messages']) && is_array($data['error_messages'])) {
        foreach ($data['error_messages'] as $message) {
            if (is_string($message) && $message !== '') {
                $messages[] = strtolower($message);
            }
        }
    }
    $messageText = implode(' ', $messages);

    if (str_contains($messageText, 'already canceled')) {
        return ['ok' => true, 'http_status' => $httpStatus, 'data' => $data, 'error' => null];
    }

    if (str_contains($messageText, 'token not found')) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => $data, 'error' => 'not_found'];
    }

    if (str_contains($messageText, 'on progress') || str_contains($messageText, 'in progress')) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => $data, 'error' => 'in_progress'];
    }

    if ($httpStatus === 401 || $httpStatus === 403) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => $data, 'error' => 'auth'];
    }

    if ($httpStatus === 404) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => $data, 'error' => 'not_found'];
    }

    if ($httpStatus >= 500) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => $data, 'error' => 'server'];
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => $data, 'error' => 'http'];
    }

    if ($data === null) {
        return ['ok' => false, 'http_status' => $httpStatus, 'data' => null, 'error' => 'invalid_json'];
    }

    if (isset($data['canceled_at']) && is_string($data['canceled_at']) && $data['canceled_at'] !== '') {
        return ['ok' => true, 'http_status' => $httpStatus, 'data' => $data, 'error' => null];
    }

    return ['ok' => false, 'http_status' => $httpStatus, 'data' => $data, 'error' => 'invalid_json'];
}
