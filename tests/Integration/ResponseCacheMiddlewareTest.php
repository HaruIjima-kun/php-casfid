<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Middlewares\ResponseCacheRedisMiddleware;
use App\Infrastructure\Cache\RedisClientFactory;
use App\Infrastructure\Config\Config;

final class ResponseCacheMiddlewareTest extends TestCase
{
    public function test_miss_then_hit_same_get(): void
    {
        $cfg = new Config(dirname(__DIR__, 2));
        $redis = RedisClientFactory::make($cfg);
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }

        // Forzamos un TTL suficientemente alto para la prueba
        putenv('API_CACHE_TTL_SECONDS=10');

        $mw = new ResponseCacheRedisMiddleware($cfg, $redis);

        $path = '/health?x=1&y=2'; // el orden no importa por la normalización
        $req = new Request('GET', $path, [], [], ['x' => '1', 'y' => '2']);

        // Limpia cualquier resto del mismo key (mejorando determinismo)
        // No conocemos la clave exacta (está hasheada). Saltamos y confiamos en TTL corto.

        // MISS
        ob_start();
        $mw->handle($req, function (Request $r): void {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'when' => time()]);
        });
        $out1 = (string)ob_get_clean();

        $headers1 = headers_list();
        $xCache1 = $this->findHeader($headers1, 'X-Cache');
        $this->assertNotNull($xCache1);
        $this->assertStringContainsString('MISS', $xCache1);

        // HIT
        ob_start();
        $mw->handle($req, function (Request $r): void {
            // No debería ejecutarse si es HIT
            echo json_encode(['ok' => false]);
        });
        $out2 = (string)ob_get_clean();

        $headers2 = headers_list();
        $xCache2 = $this->findHeader($headers2, 'X-Cache');
        $this->assertNotNull($xCache2);
        $this->assertStringContainsString('HIT', $xCache2);

        // Mismo body (se cacheó)
        $this->assertSame($out1, $out2);
    }

    private function findHeader(array $headers, string $name): ?string
    {
        foreach ($headers as $h) {
            if (stripos($h, $name . ':') === 0) {
                return $h;
            }
        }
        return null;
    }
}
