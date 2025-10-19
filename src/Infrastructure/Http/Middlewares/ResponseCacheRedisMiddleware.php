<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Request;
use Redis;

final class ResponseCacheRedisMiddleware
{
    public function __construct(
        private Config $config,
        private ?Redis $redis
    ) {}

    /**
     * @param callable(Request):void $next
     */
    public function handle(Request $req, callable $next): void
    {
        // Solo caché para GET y cuando hay Redis
        if ($this->redis === null || strtoupper($req->method()) !== 'GET') {
            $next($req);
            return;
        }

        $enabled = strtolower($this->config->get('CACHE_ENABLED', 'true') ?? 'true') === 'true';
        if (!$enabled) {
            $next($req);
            return;
        }

        $ttl = (int)($this->config->get('API_CACHE_TTL_SECONDS', '30') ?? '30');
        if ($ttl <= 0) {
            $next($req);
            return;
        }

        // Clave: método + path + query normalizada
        $key = $this->cacheKey($req);

        // Intento de HIT
        $hit = $this->redis->get($key);
        if ($hit !== false && $hit !== null) {
            $payload = json_decode((string)$hit, true);
            if (is_array($payload) && isset($payload['status'], $payload['headers'], $payload['body'])) {
                // Reponer headers cacheados
                foreach ($payload['headers'] as $h) {
                    header($h, true);
                }
                header('X-Cache: HIT');

                http_response_code((int)$payload['status']);
                echo (string)$payload['body'];
                return;
            }
        }

        // MISS: capturamos salida
        header('X-Cache: MISS');
        $statusBefore = http_response_code();

        $headersBefore = headers_list();
        ob_start();
        $next($req);
        $body = ob_get_clean();

        // Si durante la ejecución se cambió el status, lo recogemos
        $status = http_response_code();
        if ($status === false) {
            $status = is_int($statusBefore) ? $statusBefore : 200;
        }

        // Headers resultantes
        $headersAfter = headers_list();
        // Serializamos headers para rehidratarlos luego
        $storeHeaders = $this->diffHeaders($headersBefore, $headersAfter);

        // Guardar en Redis
        $value = json_encode([
            'status'  => $status,
            'headers' => $storeHeaders,
            'body'    => $body,
        ], JSON_THROW_ON_ERROR);

        $this->redis->setex($key, $ttl, $value);

        // Emitimos body (por si acaso ob_get_clean ya lo imprimió, aseguramos)
        echo $body;
    }

    private function cacheKey(Request $req): string
    {
        $path = $req->path();
        $query = $req->query();

        // Normaliza el orden de parámetros para que ?a=1&b=2 == ?b=2&a=1
        ksort($query);
        $qs = http_build_query($query);

        return 'resp:' . md5('GET|' . $path . '|' . $qs);
    }

    /**
     * Genera solo los headers nuevos/cambiados para almacenar.
     *
     * @param string[] $before
     * @param string[] $after
     * @return string[]
     */
    private function diffHeaders(array $before, array $after): array
    {
        // Evita duplicados y deja fuera el propio X-Cache
        $map = [];
        foreach ($after as $h) {
            if (stripos($h, 'X-Cache:') === 0) {
                continue;
            }
            $map[strtolower($h)] = $h;
        }
        foreach ($before as $h) {
            unset($map[strtolower($h)]);
        }
        return array_values($map);
    }
}
