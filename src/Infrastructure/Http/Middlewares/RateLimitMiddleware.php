<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;

/**
 * Rate limiting muy simple (token/IP) a 60 req/min por defecto.
 * Lee el límite desde la Config para que PHPStan no marque la propiedad como "never read".
 *
 * NOTA: Implementación minimalista en memoria de proceso (no persistente).
 * En producción deberíamos usar Redis (clave por token/IP + ventana deslizante).
 */
final class RateLimitMiddleware
{
    public function __construct(private Config $config)
    {
    }

    /**
     * @param callable(Request):void $next
     */
    public function handle(Request $req, callable $next): void
    {
        // Leemos el límite de la config → así la propiedad $config SÍ se usa.
        $limitPerMin = (int)($this->config->get('CLIENT_RATE_LIMIT_PER_MINUTE', '60') ?? '60');
        if ($limitPerMin <= 0) {
            $next($req);
            return;
        }

        // Identidad del cliente: token Bearer si existe, si no IP remota.
        $auth = $req->header('Authorization', '');
        $token = '';
        if (\str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $identity = $token !== '' ? ('tok:' . $token) : ('ip:' . $ip);

        // Ventana de 60s muy simple en memoria (por proceso).
        // Para demo/tests basta; en real -> Redis.
        static $bucket = []; // array<string, array{window:int,count:int}>
        $now = time();
        $win = (int)floor($now / 60);

        if (!isset($bucket[$identity]) || $bucket[$identity]['window'] !== $win) {
            $bucket[$identity] = ['window' => $win, 'count' => 0];
        }

        if ($bucket[$identity]['count'] >= $limitPerMin) {
            // 429 Too Many Requests
            Response::json(
                null,
                [],
                [
                    [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many requests',
                    ],
                ],
                429
            );
            return;
        }

        // Consumimos un "token"
        $bucket[$identity]['count']++;

        $next($req);
    }
}
