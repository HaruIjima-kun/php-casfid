<?php
declare(strict_types=1);

use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Http\Router;
use App\Infrastructure\Logging\Logger;

// Middlewares
use App\Infrastructure\Http\Middlewares\RequestIdMiddleware;
use App\Infrastructure\Http\Middlewares\AuthMiddleware;
use App\Infrastructure\Http\Middlewares\RateLimitMiddleware;        // fallback in-memory
use App\Infrastructure\Http\Middlewares\RateLimitRedisMiddleware;   // Redis real
use App\Infrastructure\Http\Middlewares\ResponseCacheRedisMiddleware; // Caché de respuestas

// Redis cache infra
use App\Infrastructure\Cache\RedisClientFactory;
use App\Infrastructure\Cache\RedisCache;

// Controllers
use App\Interfaces\Http\Controllers\BookController;
use App\Interfaces\Http\Controllers\AuthController;

require __DIR__ . '/../vendor/autoload.php';

$config = new Config(__DIR__ . '/..');
$router = new Router();
$logger = new Logger($config);

// Redis (si está disponible)
$redis = RedisClientFactory::make($config);
$cache = $redis ? new RedisCache($redis) : null;

// --- Middlewares globales ---

// 1) Rate limit (Redis si existe; si no, fallback a in-memory)
if ($redis !== null) {
    $router->middleware(new RateLimitRedisMiddleware($config, $redis));
} else {
    $router->middleware(new RateLimitMiddleware($config));
}

// 2) Request ID para trazabilidad
$router->middleware(new RequestIdMiddleware());

// 3) Caché de respuestas GET con Redis (X-Cache: MISS/HIT)
$router->middleware(new ResponseCacheRedisMiddleware($config, $redis));

// --- Rutas públicas ---
$router->get('/', function (Request $req) use ($config) {
    Response::json(['message' => 'Hello from BooksAPI', 'env' => $config->get('APP_ENV', 'local')]);
});

$router->get('/health', function (Request $req) {
    Response::json([
        'ok' => true,
        'name' => 'BooksAPI',
        'time' => (new DateTimeImmutable())->format(DATE_ATOM),
    ]);
});

// --- Auth ---
$authController = new AuthController($config);
$router->post('/auth/login', fn(Request $req) => $authController->login($req));

// --- Books ---
$bookController = new BookController($config);
$router->get('/api/v1/libros', fn(Request $req) => $bookController->index($req));
$router->get('/api/v1/libros/{id}', fn(Request $req) => $bookController->show($req));
$router->post('/api/v1/libros', fn(Request $req) => $bookController->store($req));
$router->put('/api/v1/libros/{id}', fn(Request $req) => $bookController->update($req));
$router->delete('/api/v1/libros/{id}', fn(Request $req) => $bookController->destroy($req));

// --- Manejo de errores de última instancia ---
try {
    $router->dispatch(Request::capture());
} catch (Throwable $e) {
    $logger->error($e->getMessage(), ['trace' => $e->getTraceAsString()]);
    Response::json(null, [], [[
        'code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage(),
    ]], 500);
}
