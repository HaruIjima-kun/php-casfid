<?php
declare(strict_types=1);

use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\{Request, Response, Router};
use App\Infrastructure\Http\Middlewares\{RequestIdMiddleware, RateLimitMiddleware, AuthMiddleware};
use App\Interfaces\Http\Controllers\{HealthController, AuthController, BookController};

require __DIR__ . '/../vendor/autoload.php';

/**
 * Bootstrap de configuración
 */
$config  = new Config(__DIR__ . '/../.env');
$request = Request::fromGlobals();
$router  = new Router();

/**
 * Middlewares globales
 * - X-Request-Id (trazabilidad)
 * - Rate limit 60 req/min por IP o token (Redis)
 */
(new RequestIdMiddleware())->handle($request);
(new RateLimitMiddleware($config))->handle($request);

/**
 * Controladores
 */
$health = new HealthController($config);
$auth   = new AuthController($config);
$books  = new BookController($config);

/**
 * Rutas públicas
 */
$router->get('/health',       [$health, 'status']);
$router->post('/auth/login',  [$auth, 'login']);
$router->get('/api/v1/libros',[$books, 'index']);
$router->post('/api/v1/libros', function(\App\Infrastructure\Http\Request $req) use ($config, $books) {
    (new \App\Infrastructure\Http\Middlewares\AuthMiddleware($config))->requireAuth($req, ['admin','usuario']);
    $books->store($req);
});

/**
 * Rutas protegidas
 */
// PUT /api/v1/libros/{id}
$router->put('/api/v1/libros', function(\App\Infrastructure\Http\Request $req) use ($config, $books) {
    (new \App\Infrastructure\Http\Middlewares\AuthMiddleware($config))->requireAuth($req, ['admin','usuario']);
    $books->update($req);
});

// DELETE /api/v1/libros/{id}
$router->delete('/api/v1/libros', function(\App\Infrastructure\Http\Request $req) use ($config, $books) {
    (new \App\Infrastructure\Http\Middlewares\AuthMiddleware($config))->requireAuth($req, ['admin','usuario']);
    $books->destroy($req);
});



/**
 * Ejemplo de ruta protegida (cuando implementemos el método):
 * $router->post('/api/v1/libros', function(Request $req) use ($config, $books) {
 *     (new AuthMiddleware($config))->requireAuth($req, ['admin','usuario']);
 *     $books->store($req);
 * });
 */

/**
 * Dispatch
 */
$router->dispatch($request);

