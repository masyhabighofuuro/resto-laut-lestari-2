<?php
declare(strict_types=1);

/**
 * Memuat konfigurasi aplikasi.
 *
 * RLL_CONFIG_PATH hanya dihormati saat RLL_ENV=test. Di luar mode test,
 * konfigurasi selalu dibaca dari php/config.php.
 *
 * @return array<string, mixed>
 */
function load_config(?string $path = null): array
{
    if ($path === null) {
        $path = __DIR__ . '/config.php';

        if (getenv('RLL_ENV') === 'test') {
            $override = getenv('RLL_CONFIG_PATH');
            if (is_string($override) && trim($override) !== '') {
                $path = $override;
            }
        }
    }

    if (!is_file($path)) {
        return [];
    }

    $config = require $path;

    return is_array($config) ? $config : [];
}
