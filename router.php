<?php
declare(strict_types=1);

/*
 * Router untuk PHP built-in server:
 *   php -S 127.0.0.1:8000 router.php
 *
 * Menolak akses langsung ke file sensitif yang tidak dilindungi .htaccess
 * (php -S tidak membaca .htaccess).
 */

$requestPath = rawurldecode(
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'
);
$requestPath = str_replace('\\', '/', $requestPath);

if (str_contains($requestPath, "\0")) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    return true;
}

$segments = [];
foreach (explode('/', $requestPath) as $segment) {
    if ($segment === '' || $segment === '.') {
        continue;
    }
    if ($segment === '..') {
        array_pop($segments);
        continue;
    }
    $segments[] = $segment;
}
$requestPath = '/' . implode('/', $segments);

$blockedPatterns = [
    '#^/router\.php$#i',
    '#^/php/?$#i',
    '#^/php/(config|config\.example|orders|products|midtrans|config-loader|reconcile|device|api|receipt-lib)\.php$#i',
    '#^/php/storage(/|$)#i',
    '#^/tests(/|$)#i',
    '#\.(sqlite[^/]*|db|log)$#i',
    '#(^|/)\.(?!well-known(/|$))#i',
    '#^/(vendor|node_modules)(/|$)#i',
    '#^/composer\.(json|lock)$#i',
];

foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $requestPath) === 1) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        return true;
    }
}

return false;
