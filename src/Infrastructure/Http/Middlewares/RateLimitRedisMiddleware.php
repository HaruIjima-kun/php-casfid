<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use Redis;

final class RateLimitRedisMiddleware
{
    public function __construct(private Config $config, private ?Redis $redis)
    {
    }

    /**
     * @param callable(Request):void $next
     */
    public function handle(Request $req, callable $next): void
    {
        $limitPerMin = (int)($this->config->get('CLIENT_RATE_LIMIT_PER_MINUTE', '60') ?? '60');
        if ($limitPerMin <= 0 || $this->redis === null) {
            $next($req);
            return;
        }

        $prefix = $this->config->get('REDIS_RATE_PREFIX', 'ratelimit') ?? 'ratelimit';

        // Identidad
        $auth = $req->header('Authorization', '');
        $token = '';
        if (\str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $id = $token !== '' ? ('tok:' . $token) : ('ip:' . $ip);

        $now = time();
        $window = (int)floor($now / 60);
        $key = "{$prefix}:{$id}:{$window}";

        // INCR + EXPIRE 60s
        $count = (int)$this->redis->incr($key);
        if ($count === 1) {
            $this->redis->expire($key, 60);
        }

        // Headers de cuota
        header('X-RateLimit-Limit: ' . $limitPerMin);
        header('X-RateLimit-Remaining: ' . max(0, $limitPerMin - $count));
        header('X-RateLimit-Reset: ' . (($window + 1) * 60));

        if ($count > $limitPerMin) {
            Response::json(
                null,
                [],
                [[ 'code' => 'RATE_LIMITED', 'message' => 'Too many requests' ]],
                429
            );
            return;
        }

        $next($req);
    }
}
