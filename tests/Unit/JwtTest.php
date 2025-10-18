<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Infrastructure\Security\Jwt;

final class JwtTest extends TestCase
{
    public function test_encode_decode_ok(): void
    {
        $secret = 'test-secret';
        $payload = ['sub' => 'u1', 'role' => 'admin', 'exp' => time() + 60];
        $token = Jwt::encode($payload, $secret);
        $decoded = Jwt::decode($token, $secret);
        $this->assertSame('u1', $decoded['sub']);
        $this->assertSame('admin', $decoded['role']);
    }

    public function test_decode_invalid_signature(): void
    {
        $this->expectException(RuntimeException::class);
        $token = Jwt::encode(['exp' => time() + 60], 'a');
        Jwt::decode($token, 'b');
    }

    public function test_decode_expired(): void
    {
        $this->expectException(RuntimeException::class);
        $token = Jwt::encode(['exp' => time() - 10], 's');
        Jwt::decode($token, 's');
    }
}
