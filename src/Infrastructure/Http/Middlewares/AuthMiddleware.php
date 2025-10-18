<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Security\Jwt;

final class AuthMiddleware
{
    public function __construct(private Config $config) {}

    /**
     * Valida JWT del header Authorization.
     * Si $roles no es null, exige que el claim 'role' esté dentro de $roles.
     */
    public function requireAuth(Request $req, ?array $roles = null): void
    {
        $auth = $req->header('Authorization');
        if (!$auth || stripos($auth, 'Bearer ') !== 0) {
            Response::json(null, [], [
                ['code' => 'UNAUTHORIZED', 'message' => 'Missing Bearer token']
            ], 401);
            exit;
        }

        $jwt = trim(substr($auth, 7));
        try {
            $payload = Jwt::decode($jwt, $this->config->require('JWT_SECRET'));
        } catch (\Throwable $e) {
            Response::json(null, [], [
                ['code' => 'UNAUTHORIZED', 'message' => 'Invalid token']
            ], 401);
            exit;
        }

        if ($roles !== null && isset($payload['role']) && !in_array($payload['role'], $roles, true)) {
            Response::json(null, [], [
                ['code' => 'FORBIDDEN', 'message' => 'Insufficient role']
            ], 403);
            exit;
        }

        // Expone el usuario en el contexto de la request
        $_SERVER['AUTH_USER_ID'] = (string)($payload['sub'] ?? '');
        $_SERVER['AUTH_ROLE']    = (string)($payload['role'] ?? '');
    }
}
