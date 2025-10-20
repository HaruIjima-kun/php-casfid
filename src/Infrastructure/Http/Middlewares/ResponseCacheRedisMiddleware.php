<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Config\Config;
use Redis;

final class ResponseCacheRedisMiddleware implements Middleware
{
    public function __construct(
        private Config $config,
        private ?Redis $redis = null
    ) {}

    public function handle(Request $req, callable $next): void
    {
        $enabled = filter_var((string)$this->config->get('CACHE_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled || strtoupper($req->method()) !== 'GET') {
            $next($req);
            return;
        }

        $ttl = (int)($this->config->get('API_CACHE_TTL_SECONDS', '30') ?? '30');
        $key = 'resp:' . md5($req->path() . '|' . http_build_query($req->query()));

        if ($this->redis) {
            $cached = $this->redis->get($key);
            if (is_string($cached)) {
                Response::header('X-Cache', 'HIT');
                echo $cached;
                return;
            }

            Response::header('X-Cache', 'MISS');
            $level = ob_get_level();
            ob_start();
            $next($req);
            $body = ob_get_clean();
            while (ob_get_level() > $level) { ob_end_clean(); }

            if (is_string($body) && $body !== '') {
                $this->redis->setex($key, $ttl, $body);
                echo $body;
            }
            return;
        }

        Response::header('X-Cache', 'MISS');
        $next($req);
    }
}
