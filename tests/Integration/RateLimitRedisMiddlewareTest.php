<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Middlewares\RateLimitRedisMiddleware;
use App\Infrastructure\Cache\RedisClientFactory;
use App\Infrastructure\Config\Config;

final class RateLimitRedisMiddlewareTest extends TestCase
{
    public function test_limit_exceeded_returns_429(): void
    {
        // Pasa el path base del proyecto a Config (raíz del repo)
        $cfg = new Config(dirname(__DIR__, 2));

        $redis = RedisClientFactory::make($cfg);
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }

        $limit  = (int)($cfg->get('CLIENT_RATE_LIMIT_PER_MINUTE', '5') ?? '5');
        $prefix = $cfg->get('REDIS_RATE_PREFIX', 'ratelimit') ?? 'ratelimit';

        $ip = '127.0.0.1';
        $_SERVER['REMOTE_ADDR'] = $ip;

        // limpia ventana actual
        $win = (int)floor(time() / 60);
        $redis->del("{$prefix}:ip:{$ip}:{$win}");

        $mw = new RateLimitRedisMiddleware($cfg, $redis);

        $hit429 = false;
        $next = function (Request $r): void {
            // no-op
        };

        // request básico
        $req = new Request('GET', '/', [], [], []);

        // consumimos (limit + 1)
        ob_start();
        for ($i = 0; $i < $limit + 1; $i++) {
            http_response_code(200);
            $mw->handle($req, $next);
            if (http_response_code() === 429) {
                $hit429 = true;
                break;
            }
        }
        ob_end_clean();

        $this->assertTrue($hit429, 'Expected 429 after exceeding rate limit');
    }
}
