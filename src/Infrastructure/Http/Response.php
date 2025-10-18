<?php
declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Response
{
    public static function json(mixed $data = null, array $meta = [], ?array $errors = null, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        // Si el middleware ya puso X-Request-Id, lo incluimos en meta automáticamente
        $rid = $_SERVER['X_REQUEST_ID'] ?? null;
        if ($rid && !isset($meta['request_id'])) {
            $meta['request_id'] = $rid;
        }

        echo json_encode([
            'data'   => $data,
            'meta'   => $meta,
            'errors' => $errors,
        ], JSON_UNESCAPED_UNICODE);
    }
}
