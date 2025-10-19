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

        // Tomamos el límite real desde config
        $limit  = (int)($cfg->get('CLIENT_RATE_LIMIT_PER_MINUTE', '60') ?? '60');
        $prefix = $cfg->get('REDIS_RATE_PREFIX', 'ratelimit') ?? 'ratelimit';

        // Identidad de prueba por IP (sin token)
        $ip = '127.0.0.1';
        $_SERVER['REMOTE_ADDR'] = $ip;

        // Ventana actual de 60s
        $win = (int)floor(time() / 60);
        $key = "{$prefix}:ip:{$ip}:{$win}";

        // Dejamos el contador exactamente en el límite para que la siguiente llamada exceda
        // Usamos SET con EX=60 para asegurar expiración
        $redis->set($key, (string)$limit, ['ex' => 60]);

        // Middleware bajo prueba
        $mw = new RateLimitRedisMiddleware($cfg, $redis);
        $next = function (Request $r): void {
            // no-op
        };
        $req = new Request('GET', '/', [], [], []);

        // Disparo único que debe exceder → 429 + payload
        http_response_code(200);
        ob_start();
        $mw->handle($req, $next);
        $out = (string)ob_get_clean();

        $this->assertSame(429, http_response_code(), 'Expected HTTP 429 after exceeding rate limit');

        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'Response should be JSON object');
        $this->assertArrayHasKey('errors', $decoded);
        $this->assertIsArray($decoded['errors']);
        $this->assertSame('RATE_LIMITED', $decoded['errors'][0]['code'] ?? null);
    }
}
