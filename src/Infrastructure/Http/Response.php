<?php
declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Response
{
    /** @var array<string,string> */
    private static array $emitted = [];

    public static function header(string $name, string $value): void
    {
        // Emite para entornos web y guarda copia para CLI/tests
        @header($name . ': ' . $value, true);
        self::$emitted[strtolower($name)] = $value;
    }

    public static function getHeader(string $name): ?string
    {
        $k = strtolower($name);
        return self::$emitted[$k] ?? null;
    }

    /** Limpia cabeceras almacenadas (para tests entre “peticiones”) */
    public static function reset(): void
    {
        self::$emitted = [];
    }

    /**
     * @param mixed $data
     * @param array<string,mixed> $meta
     * @param array<int,array<string,mixed>>|null $errors
     */
    public static function json($data, array $meta = [], ?array $errors = null, int $status = 200): void
    {
        http_response_code($status);

        if (!isset($meta['request_id']) && isset($_SERVER['HTTP_X_REQUEST_ID'])) {
            $meta['request_id'] = (string)$_SERVER['HTTP_X_REQUEST_ID'];
        }

        self::header('Content-Type', 'application/json; charset=utf-8');

        echo json_encode([
            'data'   => $data,
            'meta'   => $meta,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
