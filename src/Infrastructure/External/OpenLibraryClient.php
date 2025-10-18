<?php
declare(strict_types=1);

namespace App\Infrastructure\External;

use App\Infrastructure\Cache\RedisCache;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Logging\Logger;
use GuzzleHttp\Client;
use Redis;

final class OpenLibraryClient
{
    private Client $http;
    private Logger $logger;
    private RedisCache $cache;
    private Redis $redis;
    private int $timeout;
    private int $rps; // requests per second
    private int $ttl; // cache TTL

    public function __construct(Config $config)
    {
        $this->http = new Client(['http_errors' => false]);
        $this->timeout = (int)$config->get('EXTERNAL_TIMEOUT_SECONDS', '5');
        $this->rps = (int)$config->get('EXTERNAL_RATE_LIMIT_PER_SECOND', $config->get('EXTERNAL_RATE_LIMIT_PER_SECOND') ?? '5');
        $this->ttl = (int)($config->get('REDIS_TTL_SECONDS', '86400') ?? '86400');

        $redis = new Redis();
        $redis->connect($config->get('REDIS_HOST', 'redis') ?? 'redis', (int)($config->get('REDIS_PORT', '6379') ?? '6379'), 1.0);
        $this->redis = $redis;
        $this->cache = new RedisCache($redis);
        $this->logger = new Logger($config);
    }

    private function throttle(): void
    {
        // token bucket 1s
        $bucket = 'ext:ol:' . gmdate('YmdHis');
        $count = (int)$this->redis->incr($bucket);
        if ($count === 1) $this->redis->expire($bucket, 2);
        if ($count > $this->rps) usleep(200_000); // 0.2s backoff simple
    }

    public function getByIsbn(string $isbn): array
    {
        $key = 'ol:isbn:' . $isbn;
        return $this->cache->remember($key, $this->ttl, function () use ($isbn) {
            try {
                $this->throttle();
                $resp = $this->http->get('https://openlibrary.org/api/books', [
                    'query' => ['bibkeys' => 'ISBN:' . $isbn, 'format' => 'json', 'jscmd' => 'data'],
                    'timeout' => $this->timeout,
                ]);
                $json = json_decode((string)$resp->getBody(), true) ?? [];
                return $json['ISBN:' . $isbn] ?? [];
            } catch (\Throwable $e) {
                $this->logger->warning('openlibrary.isbn.failed', ['isbn' => $isbn, 'error' => $e->getMessage()]);
                return [];
            }
        });
    }

    public function searchByTitleAuthor(string $title = '', string $author = ''): array
    {
        $key = 'ol:search:' . md5($title . '|' . $author);
        return $this->cache->remember($key, $this->ttl, function () use ($title, $author) {
            try {
                $this->throttle();
                $resp = $this->http->get('https://openlibrary.org/search.json', [
                    'query' => ['title' => $title, 'author' => $author, 'limit' => 1],
                    'timeout' => $this->timeout,
                ]);
                return json_decode((string)$resp->getBody(), true) ?? [];
            } catch (\Throwable $e) {
                $this->logger->warning('openlibrary.search.failed', ['title' => $title, 'author' => $author, 'error' => $e->getMessage()]);
                return [];
            }
        });
    }
}
