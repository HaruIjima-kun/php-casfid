<?php
declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Response
{
    public static function json(mixed $data = null, array $meta = [], ?array $errors = null, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        // Asegura campos data/meta/errors en el sobre estándar
        echo json_encode([
            'data'   => $data,
            'meta'   => $meta,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE);
    }
}
