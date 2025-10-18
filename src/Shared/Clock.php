<?php
declare(strict_types=1);

namespace App\Shared;

final class Clock
{
    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
