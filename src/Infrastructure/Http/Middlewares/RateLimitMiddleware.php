<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Config\Config;
use Redis;

final class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private Config $config,
        private ?Redis $redis = null
    ) {}

    public function handle(Request $req, callable $next): void
    {
        $limit  = (int)($this->config->get('RATE_LIMIT_MAX', '60') ?? '60');
        $window = (int)($this->config->get('RATE_LIMIT_WINDOW', '60') ?? '60');

        $token = $req->bearerToken() ?? 'ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'cli');
        $key   = "ratelimit:{$token}";

        $remaining = $limit - 1;
        $reset     = time() + $window;

        if ($this->redis) {
            $now = time();
            $this->redis->multi();
            $this->redis->incr($key);
            $this->redis->expire($key, $window);
            $res = $this->redis->exec();
            $count = (int)($res[0] ?? 1);

            $ttlVal = $this->redis->ttl($key); // int|false
            if (is_int($ttlVal) && $ttlVal > 0) {
                $reset = $now + $ttlVal;
            }

            $remaining = max(0, $limit - $count);

            Response::header('X-RateLimit-Limit', (string)$limit);
            Response::header('X-RateLimit-Remaining', (string)$remaining);
            Response::header('X-RateLimit-Reset', (string)$reset);

            if ($count > $limit) {
                http_response_code(429);
                $retryAfter = is_int($ttlVal) ? max(1, $ttlVal) : $window;
                Response::header('Retry-After', (string)$retryAfter);
                Response::json(null, [], [[
                    'code' => 'RATE_LIMITED',
                    'message' => 'Too Many Requests'
                ]], 429);
                return;
            }
        } else {
            Response::header('X-RateLimit-Limit', (string)$limit);
            Response::header('X-RateLimit-Remaining', (string)$remaining);
            Response::header('X-RateLimit-Reset', (string)$reset);
        }

        $next($req);
    }
}
