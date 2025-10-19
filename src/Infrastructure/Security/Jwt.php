<?php
declare(strict_types=1);

namespace App\Infrastructure\Security;

final class Jwt
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function encode(array $payload, string $secret): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $h64 = self::b64(json_encode($header, JSON_THROW_ON_ERROR));
        $p64 = self::b64(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig = hash_hmac('sha256', $h64 . '.' . $p64, $secret, true);
        $s64 = self::b64($sig);
        return $h64 . '.' . $p64 . '.' . $s64;
    }

    /** @return array<string, mixed> */
    public static function decode(string $jwt, string $secret): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed token');
        }
        [$h, $p, $s] = $parts;
        $check = self::b64(hash_hmac('sha256', $h . '.' . $p, $secret, true));
        if (!hash_equals($check, $s)) {
            throw new \RuntimeException('Invalid signature');
        }
        $payload = json_decode(self::ub64($p), true) ?? [];
        if (isset($payload['exp']) && time() >= $payload['exp']) {
            throw new \RuntimeException('Token expired');
        }
        return $payload;
    }

    /** @param string $data */
    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function ub64(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
