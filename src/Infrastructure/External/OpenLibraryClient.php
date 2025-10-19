<?php
declare(strict_types=1);

namespace App\Infrastructure\External;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Cache\CacheInterface;
use GuzzleHttp\Client;

final class OpenLibraryClient
{
    private Client $http;
    private ?CacheInterface $cache;
    private int $ttl;

    public function __construct(Config $config, ?CacheInterface $cache = null)
    {
        $this->http = new Client([
            'timeout' => 5.0,
            'headers' => ['Accept' => 'application/json'],
        ]);
        $this->cache = $cache;
        $this->ttl   = (int)($config->get('REDIS_TTL_SECONDS', '86400') ?? '86400');
    }

    /** @return array<string,mixed> */
    public function getByIsbn(string $isbn): array
    {
        $ckey = "ol:isbn:{$isbn}";
        if ($this->cache) {
            $hit = $this->cache->get($ckey);
            if ($hit !== null) {
                return (array)$hit;
            }
        }

        $resp = $this->http->request('GET', 'https://openlibrary.org/api/books', [
            'query' => [
                'bibkeys' => 'ISBN:' . $isbn,
                'format'  => 'json',
                'jscmd'   => 'data',
            ],
        ]);
        $data = json_decode((string)$resp->getBody(), true) ?? [];
        $out  = $data['ISBN:'.$isbn] ?? [];

        if ($this->cache) {
            $this->cache->set($ckey, $out, $this->ttl);
        }
        return $out;
    }

    /** @return array<string,mixed> */
    public function searchByTitleAuthor(string $title = '', string $author = ''): array
    {
        $ckey = 'ol:search:' . md5(strtolower($title) . '|' . strtolower($author));
        if ($this->cache) {
            $hit = $this->cache->get($ckey);
            if ($hit !== null) {
                return (array)$hit;
            }
        }

        $resp = $this->http->request('GET', 'https://openlibrary.org/search.json', [
            'query' => array_filter([
                'title'  => $title ?: null,
                'author' => $author ?: null,
                'limit'  => 1,
            ]),
        ]);

        $data = json_decode((string)$resp->getBody(), true) ?? [];
        if ($this->cache) {
            $this->cache->set($ckey, $data, $this->ttl);
        }
        return $data;
    }
}
