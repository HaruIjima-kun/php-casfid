<?php
declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Request
{
    /** @var array<string,string> */
    private array $headers;
    /** @var array<string,string> */
    private array $query;
    /** @var array<string,mixed> */
    private array $json;
    private string $method;
    private string $path;
    private string $id;

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     * @param array<string,mixed>  $json
     */
    public function __construct(
        string $method,
        string $path,
        array $headers = [],
        array $query = [],
        array $json = []
    ) {
        $this->method  = strtoupper($method);
        $this->path    = $path;
        $this->headers = $headers;
        $this->query   = $query;
        $this->json    = $json;
        $this->id      = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8));
    }

    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        $path   = parse_url($uri, PHP_URL_PATH) ?: '/';

        // headers
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($k, 5)));
                $headers[$name] = (string)$v;
            }
        }

        // query
        $q = [];
        foreach ($_GET as $k => $v) {
            if (is_scalar($v)) $q[(string)$k] = (string)$v;
        }

        // json
        $raw = file_get_contents('php://input') ?: '';
        $json = [];
        if ($raw !== '') {
            $tmp = json_decode($raw, true);
            if (is_array($tmp)) {
                /** @var array<string,mixed> $tmp */
                $json = $tmp;
            }
        }

        return new self($method, $path, $headers, $q, $json);
    }

    public function id(): string { return $this->id; }
    public function method(): string { return $this->method; }
    public function path(): string { return $this->path; }

    /**
     * @return array<string,string>|string|null
     */
    public function headers(?string $key = null)
    {
        if ($key === null) return $this->headers;
        $key = strtolower($key);
        return $this->headers[$key] ?? null;
    }

    /**
     * @return array<string,string>|string|null
     */
    public function query(?string $key = null)
    {
        if ($key === null) return $this->query;
        return $this->query[$key] ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    public function json(): array
    {
        return $this->json;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->headers('authorization');
        if (!is_string($auth)) {
            return null;
        }
        if (stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return null;
    }

    public function route(string $key): ?string
    {
        $params = $_SERVER['ROUTE_PARAMS'] ?? [];
        if (is_array($params) && array_key_exists($key, $params)) {
            $v = $params[$key];
            return is_scalar($v) ? (string)$v : null;
        }
        return null;
    }
}
