<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Config\Config;
use Redis;
use Throwable;

/**
 * Límite por IP o token: 60 req/min por defecto (configurable).
 * Clave: rl:{id}:{YYYYMMDDHHMM}  -> INCR + EXPIRE 70s
 */
final class RateLimitMiddleware
{
    private int $limit;
    private Redis $redis;

    public function __construct(private Config $config)
    {
        $this->limit = (int)($config->get('CLIENT_RATE_LIMIT_PER_MINUTE', '60') ?? '60');

        $host = $config->get('REDIS_HOST', 'redis') ?? 'redis';
        $port = (int)($config->get('REDIS_PORT', '6379') ?? '6379');

        $this->redis = new Redis();
        $this->redis->connect($host, $port, 1.0);
    }

    public function handle(Request $req): void
    {
        // Identidad: token Bearer si existe; si no, IP remota
        $auth = $req->header('Authorization');
        $bearer = null;
        if ($auth && stripos($auth, 'Bearer ') === 0) {
            $bearer = substr($auth, 7);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $id = $bearer ? ('tok:' . substr(hash('sha256', $bearer), 0, 16)) : ('ip:' . $ip);

        $bucket = gmdate('YmdHi'); // ventana por minuto en UTC
        $key = "rl:{$id}:{$bucket}";

        try {
            $count = (int)$this->redis->incr($key);
            if ($count === 1) {
                // vida un poco mayor que 60s por seguridad
                $this->redis->expire($key, 70);
            }
            if ($count > $this->limit) {
                header('Retry-After: 60');
                Response::json(null, [], [
                    ['code' => 'RATE_LIMITED', 'message' => 'Too many requests']
                ], 429);
                exit; // cortamos aquí
            }
        } catch (Throwable $e) {
            // Si Redis falla, preferimos no bloquear la petición
            // (se podría loggear a futuro)
        }
    }
}
