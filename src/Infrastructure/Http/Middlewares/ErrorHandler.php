<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Logging\Logger;
use Throwable;

final class ErrorHandler
{
    public function __construct(private Logger $logger, private Config $config)
    {
    }

    public function register(): void
    {
        // Convierte notices/warnings en excepciones
        set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $e): void {
            $this->handleException($e);
        });
    }

    private function handleException(Throwable $e): void
    {
        // Log detallado
        $this->logger->error('Unhandled exception', [
            'type' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);

        $isLocal = ($this->config->get('APP_ENV', 'local') === 'local');

        $error = [
            'code' => 'INTERNAL_ERROR',
            'message' => $isLocal ? $e->getMessage() : 'Unexpected error',
        ];
        if ($isLocal) {
            $error['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 10);
        }

        Response::json(null, [], [$error], 500);
    }
}
