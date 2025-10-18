<?php
declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Request
{
    public function __construct(
        private string $method,
        private string $path,
        private array $headers,
        private array $query,
        private ?string $rawBody,
        private ?array $json
    ) {}

    public static function fromGlobals(): self
    {
        $method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri     = $_SERVER['REQUEST_URI'] ?? '/';
        $path    = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
        $query   = $_GET ?? [];

        $rawBody = null;
        $json    = null;

        if (in_array($method, ['POST','PUT','PATCH','DELETE'], true)) {
            $rawBody = file_get_contents('php://input') ?: null;
            $ct = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
            if (stripos($ct, 'application/json') !== false && $rawBody !== null && $rawBody !== '') {
                $parsed = json_decode($rawBody, true);
                $json = is_array($parsed) ? $parsed : null;
            }
        }

        // Normaliza llaves de headers a formato capitalizado
        $normHeaders = [];
        foreach ($headers as $k => $v) {
            $nk = ucwords(strtolower((string)$k), '-');
            $normHeaders[$nk] = $v;
        }

        return new self($method, $path, $normHeaders, $query, $rawBody, $json);
    }

    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }
    public function headers(): array { return $this->headers; }
    public function header(string $key, ?string $default = null): ?string {
        $k = ucwords(strtolower($key), '-');
        return $this->headers[$k] ?? $default;
    }
    public function query(): array { return $this->query; }
    public function queryParam(string $key, ?string $default = null): ?string {
        return isset($this->query[$key]) ? (string)$this->query[$key] : $default;
    }
    public function rawBody(): ?string { return $this->rawBody; }
    public function json(): ?array { return $this->json; }
}
