<?php
declare(strict_types=1);

namespace App\Infrastructure\Security;

final class Jwt
{
    public static function encode(array $payload, string $secret): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [self::b64(json_encode($header)), self::b64(json_encode($payload))];
        $sig = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = self::b64($sig);
        return implode('.', $segments);
    }

    public static function decode(string $jwt, string $secret): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed token');
        }
        [$h, $p, $s] = $parts;
        $check = self::b64(hash_hmac('sha256', $h.'.'.$p, $secret, true));
        if (!hash_equals($check, $s)) {
            throw new \RuntimeException('Invalid signature');
        }
        $payload = json_decode(self::ub64($p), true) ?? [];
        if (isset($payload['exp']) && time() >= $payload['exp']) {
            throw new \RuntimeException('Token expired');
        }
        return $payload;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    private static function ub64(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
