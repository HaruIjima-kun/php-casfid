<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;

/**
 * Middleware que fija un X-Request-Id por petición.
 * - Si viene en cabecera, lo respeta.
 * - Si no, genera uno.
 * El Response::json lo incluirá automáticamente en la respuesta leyendo $_SERVER['REQUEST_ID'].
 */
final class RequestIdMiddleware
{
    /**
     * Pipeline middleware: recibe la Request y el siguiente callable.
     *
     * @param callable(Request):void $next
     */
    public function handle(Request $req, callable $next): void
    {
        // Respetar si el cliente nos lo manda, si no, generamos uno.
        $rid = $req->header('X-Request-Id');
        if ($rid === null || $rid === '') {
            $rid = bin2hex(random_bytes(8));
        }

        // Lo dejamos accesible a toda la app (Response::json lo usará).
        $_SERVER['REQUEST_ID'] = $rid;

        // Continuar la cadena
        $next($req);
    }
}
