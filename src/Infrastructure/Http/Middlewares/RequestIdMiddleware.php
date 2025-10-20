<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;

final class RequestIdMiddleware implements Middleware
{
    public function handle(Request $req, callable $next): void
    {
        $id = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
        $_SERVER['HTTP_X_REQUEST_ID'] = $id;
        Response::header('X-Request-Id', $id);
        $next($req);
    }
}
