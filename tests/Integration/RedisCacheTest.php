<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Infrastructure\Cache\RedisClientFactory;
use App\Infrastructure\Cache\RedisCache;
use App\Infrastructure\Config\Config;

final class RedisCacheTest extends TestCase
{
    public function test_set_get_ttl(): void
    {
        // Pasa el path base del proyecto a Config (raíz del repo)
        $cfg = new Config(dirname(__DIR__, 2));

        $redis = RedisClientFactory::make($cfg);
        if ($redis === null) {
            $this->markTestSkipped('Redis not available');
        }

        $cache = new RedisCache($redis);
        $key = 'test:cache:' . bin2hex(random_bytes(4));
        $data = ['a' => 1, 'b' => 'x'];

        $cache->set($key, $data, 2);
        $got = $cache->get($key);
        $this->assertSame($data, $got);

        sleep(3);
        $expired = $cache->get($key);
        $this->assertNull($expired);
    }
}
