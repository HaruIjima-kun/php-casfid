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
        $cfg = new Config(dirname(__DIR__, 2));

        $redis = RedisClientFactory::make($cfg);
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }

        // Usa el límite configurado, pero no nos importa su valor exacto
        $limit  = (int)($cfg->get('CLIENT_RATE_LIMIT_PER_MINUTE', '5') ?? '5');
        $prefix = $cfg->get('REDIS_RATE_PREFIX', 'ratelimit') ?? 'ratelimit';

        $ip = '127.0.0.1';
        $_SERVER['REMOTE_ADDR'] = $ip;

        $win = (int)floor(time() / 60);
        $redis->del("{$prefix}:ip:{$ip}:{$win}");

        $mw = new RateLimitRedisMiddleware($cfg, $redis);
        $next = function (Request $r): void {
            // no-op
        };
        $req = new Request('GET', '/', [], [], []);

        // Consumimos (limit + 1) y validamos 429 + payload
        $hit429 = false;
        $gotPayload = false;

        for ($i = 0; $i < $limit + 1; $i++) {
            http_response_code(200);

            ob_start();
            $mw->handle($req, $next);
            $out = (string)ob_get_clean();

            if (http_response_code() === 429) {
                $hit429 = true;
                $decoded = json_decode($out, true);
                if (is_array($decoded) && isset($decoded['errors'][0]['code'])) {
                    $gotPayload = ($decoded['errors'][0]['code'] === 'RATE_LIMITED');
                }
                break;
            }
        }

        $this->assertTrue($hit429, 'Expected 429 after exceeding rate limit');
        $this->assertTrue($gotPayload, 'Expected RATE_LIMITED error payload on 429');
    }
}
