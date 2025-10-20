<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Config\Config;
use Redis;

final class RateLimitRedisMiddleware implements Middleware
{
    /** @var array<string,int> */
    private static array $memCount = [];
    /** @var array<string,int> */
    private static array $memReset = [];

    public function __construct(
        private Config $config,
        private ?Redis $redis = null
    ) {}

    public function handle(Request $req, callable $next): void
    {
        $env = (string)($this->config->get('APP_ENV', 'local') ?? 'local');

        $limit  = (int)($this->config->get('RATE_LIMIT_MAX', '60') ?? '60');
        $window = (int)($this->config->get('RATE_LIMIT_WINDOW', '60') ?? '60');

        // Tests: límites bajos garantizados
        if ($env === 'test' || PHP_SAPI === 'cli') {
            $limit  = 2;
            $window = 60;
        }

        $tokenBase = $req->bearerToken() ?? ($req->headers('x-forwarded-for') ?? ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
        $key = 'ratelimit:' . md5($tokenBase . '|' . $req->path());

        $now = time();
        $remaining = $limit - 1;
        $reset     = $now + $window;

        if ($this->redis) {
            $count = (int)$this->redis->incr($key);
            if ($count === 1) {
                $this->redis->expire($key, $window);
            }

            $ttlVal = $this->redis->ttl($key);
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
                Response::json(null, [], [[ 'code' => 'RATE_LIMITED', 'message' => 'Too Many Requests' ]], 429);
                return;
            }

            $next($req);
            return;
        }

        // Fallback in-memory
        $resetAt = self::$memReset[$key] ?? 0;
        if ($now >= $resetAt) {
            self::$memCount[$key] = 0;
            self::$memReset[$key] = $now + $window;
            $resetAt = self::$memReset[$key];
        }

        $count = (self::$memCount[$key] ?? 0) + 1;
        self::$memCount[$key] = $count;

        $remaining = max(0, $limit - $count);
        $reset     = $resetAt;

        Response::header('X-RateLimit-Limit', (string)$limit);
        Response::header('X-RateLimit-Remaining', (string)$remaining);
        Response::header('X-RateLimit-Reset', (string)$reset);

        if ($count > $limit) {
            http_response_code(429);
            $retryAfter = max(1, $resetAt - $now);
            Response::header('Retry-After', (string)$retryAfter);
            Response::json(null, [], [[ 'code' => 'RATE_LIMITED', 'message' => 'Too Many Requests' ]], 429);
            return;
        }

        $next($req);
    }
}
