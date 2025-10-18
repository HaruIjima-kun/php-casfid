<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;

/**
 * Genera/propaga un X-Request-Id para trazabilidad.
 * - Si viene en la petición, lo reusa.
 * - Si no, genera uno y lo añade a la respuesta.
 * - Lo expone en $_SERVER['X_REQUEST_ID'] para que controladores puedan leerlo.
 */
final class RequestIdMiddleware
{
    public function handle(Request $req): string
    {
        $incoming = $req->header('X-Request-Id');
        $id = $incoming && preg_match('/^[a-f0-9-]{8,64}$/i', $incoming)
            ? $incoming
            : bin2hex(random_bytes(8));

        // Exponer en cabecera respuesta y en superglobal para fácil acceso
        header('X-Request-Id: ' . $id);
        $_SERVER['X_REQUEST_ID'] = $id;

        return $id;
    }
}
