<?php
declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Infrastructure\Config\Config;
use Redis;
use RedisException;

final class RedisClientFactory
{
    public static function make(Config $config): ?Redis
    {
        $enabled = strtolower($config->get('REDIS_ENABLED', 'true') ?? 'true') === 'true';
        if (!$enabled) {
            return null;
        }

        $host = $config->get('REDIS_HOST', 'redis') ?? 'redis';
        $port = (int)($config->get('REDIS_PORT', '6379') ?? '6379');
        $db   = (int)($config->get('REDIS_DB', '0') ?? '0');
        $pass = $config->get('REDIS_PASSWORD', '') ?? '';

        try {
            $r = new Redis();
            $r->connect($host, $port, 2.0);
            if ($pass !== '') {
                $r->auth($pass);
            }
            if ($db > 0) {
                $r->select($db);
            }
            return $r;
        } catch (RedisException) {
            return null;
        }
    }
}
