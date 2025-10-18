<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

final class Isbn
{
    private string $value;

    public function __construct(string $raw)
    {
        $v = strtoupper(preg_replace('/[^0-9X]/', '', $raw) ?? '');
        if ($this->isValid10($v) || $this->isValid13($v)) {
            $this->value = $v;
            return;
        }
        throw new InvalidArgumentException('ISBN inválido');
    }

    public function value(): string { return $this->value; }

    private function isValid10(string $v): bool
    {
        if (strlen($v) !== 10) return false;
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            if (!ctype_digit($v[$i])) return false;
            $sum += ($i + 1) * (int)$v[$i];
        }
        $check = $v[9] === 'X' ? 10 : (ctype_digit($v[9]) ? (int)$v[9] : -1);
        return ($sum + 10 * $check) % 11 === 0;
    }

    private function isValid13(string $v): bool
    {
        if (strlen($v) !== 13 || !ctype_digit($v)) return false;
        $sum = 0;
        for ($i = 0; $i < 12; $i++) $sum += (int)$v[$i] * ($i % 2 ? 3 : 1);
        $check = (10 - ($sum % 10)) % 10;
        return $check === (int)$v[12];
    }
}
