<?php
declare(strict_types=1);

/**
 * @param array<string, mixed> $body
 */
function api_respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(
        $body,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
    );
    exit;
}

function api_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        api_respond(405, ['error' => 'Metode tidak diizinkan.']);
    }
}

function api_require_csrf_header(): void
{
    $value = (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if (strcasecmp($value, 'XMLHttpRequest') !== 0) {
        api_respond(400, ['error' => 'Permintaan tidak valid.']);
    }
}

/**
 * @return array<string, mixed>
 */
function api_json_body(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== 0) {
        api_respond(415, ['error' => 'Content-Type harus application/json.']);
    }

    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody === false ? '' : $rawBody, true);

    if (!is_array($data)) {
        api_respond(400, ['error' => 'Body JSON tidak valid.']);
    }

    return $data;
}

/**
 * @return array<string, mixed>
 */
function api_require_json_post(): array
{
    api_require_method('POST');
    api_require_csrf_header();

    return api_json_body();
}

function api_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip !== '' ? $ip : 'unknown';
}

function api_log(string $file, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents(__DIR__ . '/' . $file, $line, FILE_APPEND | LOCK_EX);
}
