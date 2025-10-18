<?php
declare(strict_types=1);

use App\Infrastructure\Config\Config;

require __DIR__ . '/../vendor/autoload.php';

// Carga configuración (usa .env y variables de entorno del contenedor)
$config = new Config(__DIR__ . '/../.env');

// Datos mínimos para responder
$appName = $config->get('APP_NAME', 'BooksAPI');
$env     = $config->get('APP_ENV', 'local');

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'data' => [
        'message' => "Hello from {$appName}",
        'env'     => $env,
    ],
    'meta'   => [
        'request_id' => bin2hex(random_bytes(8)), // temporal (luego lo hará el middleware)
    ],
    'errors' => null
], JSON_UNESCAPED_UNICODE);