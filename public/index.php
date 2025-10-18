<?php
declare(strict_types=1);

use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Response;

require __DIR__ . '/../vendor/autoload.php';

$config = new Config(__DIR__ . '/../.env');

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

// Header temporal de request_id (luego será middleware)
$requestId = bin2hex(random_bytes(8));
header('X-Request-Id: ' . $requestId);

// Rutas temporales
if ($path === '/health') {
    return Response::json(
        ['ok' => true, 'name' => $config->get('APP_NAME', 'BooksAPI')],
        ['request_id' => $requestId],
        null,
        200
    );
}

// Default hello
$appName = $config->get('APP_NAME', 'BooksAPI');
$env     = $config->get('APP_ENV', 'local');

Response::json(
    ['message' => "Hello from {$appName}", 'env' => $env],
    ['request_id' => $requestId],
    null,
    200
);
