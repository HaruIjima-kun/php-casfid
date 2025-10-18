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
$router->post('/api/v1/libros', function(Request $req) use ($config, $books) {
    (new AuthMiddleware($config))->requireAuth($req, ['admin','usuario']);
    $books->store($req);
});
$router->get('/api/v1/libros/{id}', function(\App\Infrastructure\Http\Request $req) use ($books) {
    // Reutilizamos findById
    $params = $_SERVER['ROUTE_PARAMS'] ?? [];
    $id = $params['id'] ?? null;
    if (!$id) { \App\Infrastructure\Http\Response::json(null, [], [['code'=>'BAD_REQUEST','message'=>'Missing id']], 400); return; }
    $row = (new \App\Infrastructure\Persistence\PdoConnection(new \App\Infrastructure\Config\Config(__DIR__ . '/../.env')))->pdo()
        ->prepare("SELECT * FROM libros WHERE id = :id");
    $pdo = (new \App\Infrastructure\Persistence\PdoConnection(new \App\Infrastructure\Config\Config(__DIR__ . '/../.env')))->pdo();
    $stmt = $pdo->prepare("SELECT * FROM libros WHERE id = :id AND deleted_at IS NULL");
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) { \App\Infrastructure\Http\Response::json(null, [], [['code'=>'NOT_FOUND','message'=>'Libro no encontrado']], 404); return; }
    \App\Infrastructure\Http\Response::json($row);
});


/**
 * Búsquedas
 */
// Buscar por título
$router->get('/api/v1/libros/buscar/titulo', function(Request $req) use ($books) {
    // Reutilizamos index con query 'titulo'
    $_GET['titulo'] = $req->queryParam('q','');
    $books->index($req);
});

// Buscar por autor
$router->get('/api/v1/libros/buscar/autor', function(Request $req) use ($books) {
    $_GET['autor'] = $req->queryParam('q','');
    $books->index($req);
});



/**
 * Rutas protegidas
 */
// PUT /api/v1/libros/{id}
$router->put('/api/v1/libros/{id}', function(\App\Infrastructure\Http\Request $req) use ($config, $books) {
    (new \App\Infrastructure\Http\Middlewares\AuthMiddleware($config))->requireAuth($req, ['admin','usuario']);
    $books->update($req);
});

// DELETE /api/v1/libros/{id}
$router->delete('/api/v1/libros/{id}', function(\App\Infrastructure\Http\Request $req) use ($config, $books) {
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

