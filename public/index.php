<?php
declare(strict_types=1);

/**
 * Front Controller
 */
use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Http\Router;
use App\Infrastructure\Http\Middlewares\RequestIdMiddleware;
use App\Infrastructure\Http\Middlewares\RateLimitMiddleware;
use App\Infrastructure\Http\Middlewares\ResponseCacheRedisMiddleware;
use App\Interfaces\Http\Controllers\AuthController;
use App\Interfaces\Http\Controllers\BookController;

require __DIR__ . '/../vendor/autoload.php';

ini_set('display_errors', getenv('APP_DEBUG') ? '1' : '0');
error_reporting(E_ALL);

// -----------------------------------------------------
// Config
// -----------------------------------------------------
$config = new Config($_ENV);

// -----------------------------------------------------
// Cabeceras seguridad
// -----------------------------------------------------
@header('X-Content-Type-Options: nosniff');
@header('X-Frame-Options: DENY');
@header('X-XSS-Protection: 1; mode=block');
@header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; connect-src 'self' https:; style-src 'self' 'unsafe-inline'; script-src 'self'");

// -----------------------------------------------------
// PDO (MySQL)
// -----------------------------------------------------
$pdo = null;
try {
    $dbHost = (string)$config->get('DB_HOST', 'mysql');
    $dbPort = (string)$config->get('DB_PORT', '3306');
    $dbName = (string)$config->get('DB_DATABASE', 'books');
    $dbUser = (string)$config->get('DB_USERNAME', 'root');
    $dbPass = (string)$config->get('DB_PASSWORD', 'root');
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    Response::json(null, [], [[
        'code' => 'DB_CONNECTION_ERROR',
        'message' => 'Cannot connect to database: ' . $e->getMessage(),
    ]], 500);
    exit;
}

// -----------------------------------------------------
// Redis (opcional)
// -----------------------------------------------------
$redis = null;
try {
    if (class_exists(\Redis::class)) {
        $redis = new Redis();
        $redis->connect(
            (string)$config->get('REDIS_HOST', 'redis'),
            (int)((string)$config->get('REDIS_PORT', '6379'))
        );
    }
} catch (Throwable $e) {
    $redis = null; // degrade gracefully
}

// -----------------------------------------------------
// Controladores
// -----------------------------------------------------
$authController = new AuthController($config);
$bookController = new BookController($config, $pdo, $redis); // <-- orden: Config, PDO, Redis

// -----------------------------------------------------
// Router + Middlewares globales (antes de rutas)
// -----------------------------------------------------
$router = new Router();
$router->middleware(new RequestIdMiddleware());
$router->middleware(new RateLimitMiddleware($config, $redis));
$router->middleware(new ResponseCacheRedisMiddleware($config, $redis));

// -----------------------------------------------------
// Rutas públicas
// -----------------------------------------------------
$router->get('/', function (Request $req) {
    Response::json([
        'message' => 'Hello from BooksAPI',
        'env' => (string)(getenv('APP_ENV') ?: 'local'),
    ], ['request_id' => $req->id()]);
});

$router->get('/health', function (Request $req) {
    Response::json([
        'ok'   => true,
        'name' => 'BooksAPI',
        'time' => date('c'),
    ], ['request_id' => $req->id()]);
});

// -----------------------------------------------------
// Auth
// -----------------------------------------------------
$router->post('/auth/login', function (Request $req) use ($authController) {
    $authController->login($req);
});

// -----------------------------------------------------
// Libros (API v1)
// -----------------------------------------------------
$router->get('/api/v1/libros', function (Request $req) use ($bookController) {
    $bookController->index($req);
});
$router->get('/api/v1/libros/{id}', function (Request $req) use ($bookController) {
    $bookController->show($req);
});
$router->post('/api/v1/libros', function (Request $req) use ($bookController) {
    $bookController->store($req);
});
$router->put('/api/v1/libros/{id}', function (Request $req) use ($bookController) {
    $bookController->update($req);
});
$router->delete('/api/v1/libros/{id}', function (Request $req) use ($bookController) {
    $bookController->destroy($req);
});

// -----------------------------------------------------
// Despacho
// -----------------------------------------------------
$router->dispatch(Request::capture());
