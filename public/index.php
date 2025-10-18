<?php
declare(strict_types=1);


use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\{Request, Response, Router};
use App\Infrastructure\Http\Middlewares\RequestIdMiddleware;
use App\Interfaces\Http\Controllers\AuthController;
use App\Interfaces\Http\Controllers\BookController;
use App\Interfaces\Http\Controllers\HealthController;


require __DIR__ . '/../vendor/autoload.php';

$config = new Config(__DIR__ . '/../.env');

$router = new Router();

// Autenticación
$auth = new AuthController($config);
$router->post('/auth/login', [$auth, 'login']);

// Gestión de libros
$books = new BookController($config);
$router->get('/api/v1/libros', [$books, 'index']);

// Middleware (temporalmente invocado aquí antes del dispatch)
$request = Request::fromGlobals();
$reqIdMw = new RequestIdMiddleware();
$reqIdMw->handle($request);

// Controlador de salud
$health = new HealthController($config);
$router->get('/health', [$health, 'status']);

// Ruta raíz (temporal)
$router->get('/', function(Request $req) use ($config) {
    $appName = $config->get('APP_NAME', 'BooksAPI');
    $env     = $config->get('APP_ENV', 'local');
    $rid     = $_SERVER['X_REQUEST_ID'] ?? null;

    Response::json(
        ['message' => "Hello from {$appName}", 'env' => $env],
        $rid ? ['request_id' => $rid] : []
    );
});

// Dispatch
$router->dispatch($request);
