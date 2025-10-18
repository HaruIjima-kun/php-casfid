<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Domain\ValueObject\Isbn;

final class IsbnTest extends TestCase
{
    public function test_valid_isbn13(): void
    {
        $isbn = new Isbn('9788401352744');
        $this->assertSame('9788401352744', $isbn->value());
    }

    public function test_valid_isbn10_with_X(): void
    {
        $isbn = new Isbn('0306406152'); // clásico válido
        $this->assertSame('0306406152', $isbn->value());
    }

    public function test_invalid_isbn_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Isbn('1234567890123');
    }
}
