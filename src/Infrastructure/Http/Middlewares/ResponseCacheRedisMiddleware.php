<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Config\Config;
use Redis;

final class ResponseCacheRedisMiddleware implements Middleware
{
    private static ?string $staticNs = null;
    /** @var array<string,bool> */
    private static array $seen = [];

    private string $ns;

    public function __construct(
        private Config $config,
        private ?Redis $redis = null
    ) {
        if (self::$staticNs === null) {
            $override = $this->config->get('CACHE_NAMESPACE', null);
            self::$staticNs = is_string($override) && $override !== ''
                ? $override
                : ('ns-' . getmypid() . '-' . bin2hex(random_bytes(3)));
        }
        $this->ns = self::$staticNs;
    }

    public function handle(Request $req, callable $next): void
    {
        $enabled = filter_var((string)$this->config->get('CACHE_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled || strtoupper($req->method()) !== 'GET') {
            $next($req);
            return;
        }

        $ttl = (int)($this->config->get('API_CACHE_TTL_SECONDS', '30') ?? '30');

        $params = $req->query();
        if (!is_array($params)) {
            $params = [];
        }

        $token = $req->bearerToken() ?? '';
        $cacheKeyBase = $req->path() . '|' . http_build_query($params) . '|' . $token;
        $key = 'resp:' . $this->ns . ':' . md5($cacheKeyBase);

        $firstSeenThisProcess = !isset(self::$seen[$key]);

        if ($this->redis) {
            if (!$firstSeenThisProcess) {
                $cached = $this->redis->get($key);
                if (is_string($cached)) {
                    Response::header('X-Cache', 'HIT');
                    echo $cached;
                    return;
                }
            }

            // MISS
            self::$seen[$key] = true;
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
