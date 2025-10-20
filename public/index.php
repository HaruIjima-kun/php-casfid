<?php
declare(strict_types=1);

use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Http\Router;
use App\Infrastructure\Http\Middlewares\RequestIdMiddleware;
use App\Infrastructure\Http\Middlewares\AuthMiddleware;
use App\Infrastructure\Http\Middlewares\RateLimitRedisMiddleware;
use App\Infrastructure\Http\Middlewares\ResponseCacheRedisMiddleware;
use App\Interfaces\Http\Controllers\AuthController;
use App\Interfaces\Http\Controllers\BookController;

require __DIR__ . '/../vendor/autoload.php';

try {
    // --- Bootstrap config/env ---
    $env = [];
    $envFile = __DIR__ . '/../.env';
    if (is_file($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) continue;
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $env[trim($k)] = trim($v);
        }
    }
    $config = new Config($env);

    // --- Services: PDO + Redis ---
    $dsn  = $config->require('DB_DSN');   // mysql:host=mysql;dbname=books;charset=utf8mb4
    $user = $config->require('DB_USER');  // root
    $pass = $config->require('DB_PASS');  // root
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $redis = null;
    if (filter_var((string)$config->get('REDIS_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN)) {
        $redis = new Redis();
        $redis->connect(
            (string)($config->get('REDIS_HOST', 'redis') ?? 'redis'),
            (int)($config->get('REDIS_PORT', '6379') ?? '6379'),
            2.0
        );
        $auth = $config->get('REDIS_PASSWORD', null);
        if (is_string($auth) && $auth !== '') {
            $redis->auth($auth);
        }
    }

    // --- Controllers ---
    $authCtrl  = new AuthController($config);
    $bookCtrl  = new BookController($config, $pdo);

    // --- Router + Middlewares ---
    $router = new Router();

    // 1) Identificador de request (útil para logs y para ver en respuestas)
    $router->middleware(new RequestIdMiddleware());

    // 2) Rate limit SIEMPRE antes que la caché para que cuente todas las peticiones
    $router->middleware(new RateLimitRedisMiddleware($config, $redis));

    // 3) Caché de respuestas GET
    $router->middleware(new ResponseCacheRedisMiddleware($config, $redis));

    // --- Rutas públicas ---
    $router->get('/', fn(Request $r) => Response::json(['message' => 'Hello from BooksAPI', 'env' => $config->get('APP_ENV', 'local')], ['request_id' => $r->id()]));
    $router->get('/health', fn(Request $r) => Response::json(['ok' => true, 'name' => 'BooksAPI', 'time' => date('c')], ['request_id' => $r->id()]));

    // Auth
    $router->post('/auth/login', fn(Request $r) => $authCtrl->login($r));

    // Libros (API v1)
    $router->get('/api/v1/libros', fn(Request $r) => $bookCtrl->index($r));
    $router->get('/api/v1/libros/{id}', fn(Request $r) => $bookCtrl->show($r));
    $router->post('/api/v1/libros', fn(Request $r) => $bookCtrl->store($r));
    $router->put('/api/v1/libros/{id}', fn(Request $r) => $bookCtrl->update($r));
    $router->delete('/api/v1/libros/{id}', fn(Request $r) => $bookCtrl->destroy($r));

    // --- Dispatch ---
    $request = Request::capture();
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; connect-src 'self' https:; style-src 'self' 'unsafe-inline'; script-src 'self'");
    $router->dispatch($request);

} catch (Throwable $e) {
    http_response_code(500);
    Response::json(null, [], [[
        'code'    => 'INTERNAL_ERROR',
        'message' => $e->getMessage(),
        'trace'   => (getenv('APP_ENV') === 'local') ? $e->getTraceAsString() : null,
    ]], 500);
}
