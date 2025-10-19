<?php
declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Redis;

final class RedisCache implements CacheInterface
{
    public function __construct(private Redis $redis) {}

    public function get(string $key): mixed
    {
        $raw = $this->redis->get($key);
        if ($raw === false || $raw === null) {
            return null;
        }
        $val = json_decode((string)$raw, true);
        return $val === null && $raw !== 'null' ? null : $val;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $payload = json_encode($value, JSON_THROW_ON_ERROR);
        $this->redis->setex($key, $ttlSeconds, $payload);
    }

    public function delete(string $key): void
    {
        $this->redis->del($key);
    }
}
