<?php
declare(strict_types=1);

namespace App\Infrastructure\Config;

/**
 * Cargador de configuración .env sin dependencias externas.
 * - Lee pares KEY=VALUE (ignora líneas vacías y comentarios con #)
 * - No pisa variables ya presentes en el entorno (útil en Docker)
 * - Ofrece getters con casting básico (string/bool/int/float)
 */
final class Config
{
    /** @var array<string,string> */
    private array $vars = [];

    public function __construct(string $envPath)
    {
        // Si nos pasan un archivo ".env" existente, lo parseamos
        if (is_file($envPath)) {
            $this->vars = $this->parseDotEnv($envPath);
        }

        // Variables de entorno del sistema (tienen prioridad)
        // getenv() puede devolver false si no existe -> filtramos
        foreach ($_ENV as $k => $v) {
            if (is_string($v)) {
                $this->vars[$k] = $v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (is_string($v)) {
                $this->vars[$k] = $v;
            }
        }
    }

    /** --------- Getters --------- */

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->resolve($key);
        return $v !== null ? $v : $default;
    }

    public function getInt(string $key, ?int $default = null): ?int
    {
        $v = $this->resolve($key);
        return $v !== null && is_numeric($v) ? (int)$v : $default;
    }

    public function getFloat(string $key, ?float $default = null): ?float
    {
        $v = $this->resolve($key);
        return $v !== null && is_numeric($v) ? (float)$v : $default;
    }

    public function getBool(string $key, ?bool $default = null): ?bool
    {
        $v = $this->resolve($key);
        if ($v === null) return $default;

        $map = [
            '1' => true, '0' => false,
            'true' => true, 'false' => false,
            'yes' => true, 'no' => false,
            'on' => true, 'off' => false,
        ];
        $norm = strtolower(trim($v));
        return $map[$norm] ?? $default;
    }

    /**
     * Obtiene una variable obligatoria o lanza RuntimeException con mensaje claro.
     */
    public function require(string $key): string
    {
        $v = $this->resolve($key);
        if ($v === null || $v === '') {
            throw new \RuntimeException("Missing required env var: {$key}");
        }
        return $v;
    }

    /** --------- Internos --------- */

    private function resolve(string $key): ?string
    {
        // Prioridad: variables del proceso > .env cargado
        $sys = getenv($key);
        if ($sys !== false) {
            return (string)$sys;
        }
        return $this->vars[$key] ?? null;
    }

    /**
     * @return array<string,string>
     */
    private function parseDotEnv(string $path): array
    {
        $out = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // Soporta KEY="VALUE con espacios" y KEY='value'
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/i', $line, $m)) {
                $key = $m[1];
                $val = $m[2];

                // Quita comillas alrededor si existen
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))
                ) {
                    $val = substr($val, 1, -1);
                }

                // Expande variables referenciadas: e.g., ${APP_URL}
                $val = preg_replace_callback('/\$\{([A-Z0-9_]+)\}/i', function ($mm) use ($out) {
                    $k = $mm[1];
                    $sys = getenv($k);
                    if ($sys !== false) return (string)$sys;
                    return $out[$k] ?? '';
                }, $val) ?? $val;

                $out[$key] = $val;
            }
        }
        return $out;
    }
}
