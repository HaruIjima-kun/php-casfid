<?php
declare(strict_types=1);

use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\{Request, Response, Router};

require __DIR__ . '/../vendor/autoload.php';

$config = new Config(__DIR__ . '/../.env');
$requestId = bin2hex(random_bytes(8));
header('X-Request-Id: ' . $requestId);

// Instancia Router
$router = new Router();

// /health
$router->get('/health', function(Request $req) use ($config, $requestId) {
    Response::json(
        ['ok' => true, 'name' => $config->get('APP_NAME', 'BooksAPI')],
        ['request_id' => $requestId]
    );
});

// /
$router->get('/', function(Request $req) use ($config, $requestId) {
    $appName = $config->get('APP_NAME', 'BooksAPI');
    $env     = $config->get('APP_ENV', 'local');
    Response::json(
        ['message' => "Hello from {$appName}", 'env' => $env],
        ['request_id' => $requestId]
    );
});

// Dispatch
$router->dispatch(Request::fromGlobals());
