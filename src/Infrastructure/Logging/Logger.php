<?php
declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Infrastructure\Config\Config;

final class Logger
{
    private string $file;
    private string $app;
    private bool $toStdout;

    public function __construct(Config $config)
    {
        $this->file = $config->get('LOG_PATH', 'storage/logs/app.log') ?? 'storage/logs/app.log';
        $this->app = $config->get('APP_NAME', 'BooksAPI') ?? 'BooksAPI';
        $this->toStdout = (bool)filter_var($config->get('LOG_STDOUT', 'true'), FILTER_VALIDATE_BOOL);

        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function info(string $msg, array $ctx = []): void
    {
        $this->log('INFO', $msg, $ctx);
    }

    public function warning(string $msg, array $ctx = []): void
    {
        $this->log('WARN', $msg, $ctx);
    }

    public function error(string $msg, array $ctx = []): void
    {
        $this->log('ERROR', $msg, $ctx);
    }

    public function log(string $level, string $msg, array $ctx = []): void
    {
        $rid = $_SERVER['X_REQUEST_ID'] ?? null;
        if ($rid) $ctx['request_id'] = $rid;

        $line = sprintf(
            "%s [%s] %s: %s %s\n",
            gmdate('c'),
            $this->app,
            $level,
            $msg,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
        );

        // Archivo
        @file_put_contents($this->file, $line, FILE_APPEND);

        // stdout (Docker-friendly)
        if ($this->toStdout) {
            // error_log escribe a stderr/stdout según SAPI; suficiente para Docker logs
            error_log(rtrim($line));
        }
    }
}
