<?php
declare(strict_types=1);

namespace App\Infrastructure\Config;

use InvalidArgumentException;

final class Config
{
    /** @var array<string,mixed> */
    private array $env;

    public function __construct(mixed $env = null)
    {
        // Para PHPStan, evitamos checks redundantes: asumimos $_ENV es array
        $this->env = is_array($env) ? $env : $_ENV;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $this->env)) {
            $val = $this->env[$key];
            return is_scalar($val) ? (string)$val : $default;
        }

        $g = getenv($key); // string|false
        if ($g === false) {
            return $default;
        }
        return $g;
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
