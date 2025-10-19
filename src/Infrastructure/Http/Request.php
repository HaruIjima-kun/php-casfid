<?php
declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Request
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $json
     * @param array<string,string> $query
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $headers,
        private array $json,
        private array $query
    ) {}

    public static function fromGlobals(): self
    {
        $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri     = $_SERVER['REQUEST_URI'] ?? '/';
        $path    = parse_url($uri, PHP_URL_PATH) ?: '/';

        /** @var array<string,string> $headers */
        $headers = [];
        if (function_exists('getallheaders')) {
            $raw = getallheaders() ?: [];
            foreach ($raw as $k => $v) {
                $headers[(string)$k] = (string)$v;
            }
        }

        $rawBody = file_get_contents('php://input') ?: '';
        /** @var array<string,mixed> $json */
        $json = json_decode($rawBody, true) ?: [];

        // Normaliza QUERY_STRING → array<string,string>
        /** @var array<int|string, mixed> $parsed */
        $parsed = [];
        if (isset($_SERVER['QUERY_STRING'])) {
            parse_str((string)$_SERVER['QUERY_STRING'], $parsed);
        }
        /** @var array<string,string> $query */
        $query = [];
        foreach ($parsed as $k => $v) {
            if (is_array($v)) {
                $query[(string)$k] = isset($v[0]) ? (string)$v[0] : '';
            } elseif (is_scalar($v) || $v === null) {
                $query[(string)$k] = (string)$v;
            } else {
                $query[(string)$k] = '';
            }
        }

        return new self($method, $path, $headers, $json, $query);
    }

    public function method(): string { return $this->method; }
    public function path(): string   { return $this->path; }

    /** @return array<string,string> */
    public function headers(): array { return $this->headers; }

    public function header(string $key, ?string $default = null): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $key) === 0) {
                return $v;
            }
        }
        return $default;
    }

    /** @return array<string,string> */
    public function query(): array { return $this->query; }

    public function queryParam(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function json(): array { return $this->json; }
}
