<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Middlewares\RateLimitRedisMiddleware;
use App\Infrastructure\Cache\RedisClientFactory;
use App\Infrastructure\Config\Config;

final class RateLimitHeadersTest extends TestCase
{
    public function test_headers_are_present_in_response(): void
    {
        $cfg = new Config(dirname(__DIR__, 2));
        $redis = RedisClientFactory::make($cfg);
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }

        $mw = new RateLimitRedisMiddleware($cfg, $redis);
        $req = new Request('GET', '/', [], [], []);

        ob_start();
        $mw->handle($req, fn($r) => header('Content-Type: application/json'));
        ob_end_clean();

        $headers = xdebug_get_headers();

        $this->assertTrue(
            $this->hasHeader($headers, 'X-RateLimit-Limit'),
            'Missing X-RateLimit-Limit header'
        );
        $this->assertTrue(
            $this->hasHeader($headers, 'X-RateLimit-Remaining'),
            'Missing X-RateLimit-Remaining header'
        );
        $this->assertTrue(
            $this->hasHeader($headers, 'X-RateLimit-Reset'),
            'Missing X-RateLimit-Reset header'
        );
    }

    private function hasHeader(array $headers, string $needle): bool
    {
        foreach ($headers as $h) {
            if (stripos($h, $needle) === 0) {
                return true;
            }
        }
        return false;
    }
}
