<?php
declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Infrastructure\Http\{Request, Response};
use App\Infrastructure\Config\Config;
use App\Infrastructure\Security\Jwt;

final class AuthController
{
    public function __construct(private Config $config) {}

    public function login(Request $req): void
    {
        $body = $req->json() ?? [];
        $username = (string)($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');

        if ($username === '' || $password === '') {
            Response::json(null, [], [
                ['code' => 'VALIDATION_ERROR', 'message' => 'username/password required']
            ], 400);
            return;
        }

        // Demo users (más adelante: repositorio de usuarios)
        $users = [
            // password: admin123
            'admin' => [
                'id' => '00000000-0000-0000-0000-000000000001',
                'role' => 'admin',
                'hash' => '$2y$10$e0iR3bVvE2kB0qN1rWeFqO1oQO7iG1GUR9w5dyu2H7hE3w5mCIE9a'
            ],
            // password: user1234
            'user' => [
                'id' => '00000000-0000-0000-0000-000000000002',
                'role' => 'usuario',
                'hash' => '$2y$10$w5pV2E2zJ0t7tF7qf3qvUuKxJ9y0v3bG4cd2QeOQ7sQd0r2Zk9d7K'
            ],
        ];

        $u = $users[$username] ?? null;
        if (!$u || !password_verify($password, $u['hash'])) {
            Response::json(null, [], [
                ['code' => 'UNAUTHORIZED', 'message' => 'Invalid credentials']
            ], 401);
            return;
        }

        $now = time();
        $ttl = (int)$this->config->get('JWT_TTL_SECONDS', '3600'); // 1h
        $secret = $this->config->require('JWT_SECRET');

        $payload = [
            'sub' => $u['id'],
            'role' => $u['role'],
            'iat' => $now,
            'exp' => $now + $ttl,
            'iss' => $this->config->get('APP_NAME', 'BooksAPI'),
        ];
        $token = Jwt::encode($payload, $secret);

        // refresh_token demo: en real, persistir y tener endpoint de refresh
        $refreshTtl = (int)$this->config->get('JWT_REFRESH_TTL_SECONDS', '1209600'); // 14d
        $refresh = bin2hex(random_bytes(24));
        // TODO: persistir refresh si se quiere validar/rotar

        Response::json([
            'access_token'  => $token,
            'token_type'    => 'Bearer',
            'expires_in'    => $ttl,
            'refresh_token' => $refresh,
            'user' => [
                'id'   => $u['id'],
                'name' => $username,
                'role' => $u['role'],
            ],
        ]);
    }
}
