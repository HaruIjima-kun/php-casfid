<?php
declare(strict_types=1);

namespace App\Infrastructure\Config;

use InvalidArgumentException;

final class Config
{
    /** @var array<string,mixed> */
    private array $env = [];

    public function __construct(mixed $env = null)
    {
        if (is_array($env)) {
            $this->env = $env;
            return;
        }
        // Fallback: entorno del proceso
        $this->env = is_array($_ENV) ? $_ENV : [];
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->env[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function require(string $key): string
    {
        $v = $this->get($key, null);
        if ($v === null || $v === '') {
            throw new InvalidArgumentException("Missing required config key: {$key}");
        }
        return $v;
    }
}
