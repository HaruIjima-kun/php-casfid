<?php
declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Infrastructure\Http\{Request, Response};
use App\Infrastructure\Config\Config;

final class HealthController
{
    public function __construct(private Config $config) {}

    public function status(Request $req): void
    {
        $rid = $_SERVER['X_REQUEST_ID'] ?? null;

        Response::json(
            [
                'ok'   => true,
                'name' => $this->config->get('APP_NAME', 'BooksAPI'),
                'time' => gmdate('c'),
            ],
            $rid ? ['request_id' => $rid] : []
        );
    }
}
