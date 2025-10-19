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

    /** Request ID para trazabilidad (lo puede fijar un middleware) */
    private string $id;

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $query
     * @param array<string,mixed> $json
     */
    public function __construct(
        string $method,
        string $path,
        array  $headers = [],
        array  $query = [],
        array  $json = []
    )
    {
        $this->method = strtoupper($method);
        $this->path = '/' . ltrim($path, '/');
        $this->headers = $headers;
        $this->query = $query;
        $this->json = $json;

        // ID por defecto; un middleware puede reemplazarlo con setId()
        $this->id = bin2hex(random_bytes(8));
    }

    /**
     * Construye una Request a partir de los superglobales.
     */
    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string)parse_url($uri, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }

        $headers = self::serverHeaders();
        $query = [];
        // Aseguramos array<string,string>
        foreach ($_GET ?? [] as $k => $v) {
            if (is_array($v)) {
                // Conservador: nos quedamos con la primera ocurrencia si viene array
                $query[(string)$k] = (string)reset($v);
            } else {
                $query[(string)$k] = (string)$v;
            }
        }

        $json = [];
        $ct = strtolower($headers['content-type'] ?? $headers['Content-Type'] ?? '');
        if (str_starts_with($ct, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    /** @var array<string,mixed> $decoded */
                    $json = $decoded;
                }
            }
        }

        return new self($method, $path, $headers, $query, $json);
    }

    /**
     * Devuelve todos los headers normalizados tal cual fueron capturados.
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Busca un header (case-insensitive).
     */
    public function header(string $name, ?string $default = null): ?string
    {
        // Búsqueda case-insensitive
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return $default;
    }

    /**
     * @return array<string,string>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function json(): array
    {
        return $this->json;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        if ($id !== '') {
            $this->id = $id;
        }
    }

    /**
     * Extrae el bearer token del Authorization header, si existe.
     */
    public function bearerToken(): ?string
    {
        $h = $this->header('Authorization') ?? $this->header('authorization');
        if (!$h) {
            return null;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $h, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Captura headers de la SAPI de forma portable.
     * @return array<string,string>
     */
    private static function serverHeaders(): array
    {
        // Preferimos getallheaders() si existe
        if (function_exists('getallheaders')) {
            $all = getallheaders();
            $out = [];
            foreach ($all as $k => $v) {
                $out[(string)$k] = is_array($v) ? (string)reset($v) : (string)$v;
            }
            return $out;
        }

        // Fallback: extrae de $_SERVER
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string)$value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string)$_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = (string)$_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }
}
