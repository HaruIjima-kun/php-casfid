<?php
declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Response
{
    /** @var array<string,string> */
    private static array $headers = [];

    public static function reset(): void
    {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        self::$headers = [];
    }

    public static function header(string $name, string $value): void
    {
        self::$headers[strtolower($name)] = $value;
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header($name . ': ' . $value, true);
        }
    }

    /** @return array<string,string> */
    public static function headers(): array
    {
        return self::$headers;
    }

    public static function getHeader(string $name): ?string
    {
        return self::$headers[strtolower($name)] ?? null;
    }

    /**
     * @param array<int|string, mixed>|null $data
     * @param array<string, mixed>|null $meta
     * @param array<int, array{code:string, message:string, details?:mixed}>|null $errors
     */
    public static function json(?array $data, ?array $meta = [], ?array $errors = null, int $status = 200): void
    {
        http_response_code($status);
        self::header('Content-Type', 'application/json; charset=utf-8');

        $payload = [
            'data'   => $data,
            'meta'   => $meta ?? [],
            'errors' => $errors,
        ];

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
