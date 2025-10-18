<?php
declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Redis;

final class RedisCache
{
    public function __construct(private Redis $redis) {}

    public function remember(string $key, int $ttlSeconds, callable $callback): mixed
    {
        $hit = $this->redis->get($key);
        if ($hit !== false) return json_decode($hit, true);

        $value = $callback();
        $this->redis->setex($key, $ttlSeconds, json_encode($value));
        return $value;
    }
}
