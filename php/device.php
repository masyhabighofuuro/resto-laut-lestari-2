<?php
declare(strict_types=1);

const DEVICE_COOKIE_NAME = 'rll_device';
const DEVICE_COOKIE_DAYS = 90;
const DEVICE_TOKEN_PATTERN = '/^[a-f0-9]{64}$/';

function device_is_https(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    if (is_string($https) && $https !== '' && strcasecmp($https, 'off') !== 0) {
        return true;
    }

    return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
}

function generate_device_token(): string
{
    return bin2hex(random_bytes(32));
}

function device_hash(string $token): string
{
    return hash('sha256', $token);
}

function device_token_from_cookie(): ?string
{
    $token = $_COOKIE[DEVICE_COOKIE_NAME] ?? null;
    if (!is_string($token)) {
        return null;
    }

    $token = trim($token);
    return preg_match(DEVICE_TOKEN_PATTERN, $token) === 1 ? $token : null;
}

function device_hash_from_cookie(): ?string
{
    $token = device_token_from_cookie();
    return $token === null ? null : device_hash($token);
}

/**
 * @return array{expires: int, path: string, secure: bool, httponly: bool, samesite: string}
 */
function device_cookie_options(string $token, bool $secure): array
{
    return [
        'expires' => time() + DEVICE_COOKIE_DAYS * 86400,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function set_device_cookie(string $token): void
{
    if (headers_sent()) {
        return;
    }

    setcookie(
        DEVICE_COOKIE_NAME,
        $token,
        device_cookie_options($token, device_is_https())
    );
}

function get_or_create_device_token(bool $setCookie = true): string
{
    $existing = device_token_from_cookie();
    if ($existing !== null) {
        return $existing;
    }

    $token = generate_device_token();
    if ($setCookie) {
        set_device_cookie($token);
    }

    return $token;
}
