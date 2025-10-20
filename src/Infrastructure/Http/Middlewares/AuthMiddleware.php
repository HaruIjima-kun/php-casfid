<?php
declare(strict_types=1);

namespace App\Infrastructure\Http\Middlewares;

use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Security\Jwt;

final class AuthMiddleware implements Middleware
{
    /** @var array<int,string> */
    private array $roles;

    /**
     * @param array<int,string> $roles
     */
    public function __construct(private Config $config, array $roles = [])
    {
        $this->roles = $roles;
    }

    public function handle(Request $req, callable $next): void
    {
        $token = $req->bearerToken();
        if (!$token) {
            http_response_code(401);
            Response::json(null, [], [[ 'code'=>'UNAUTHORIZED','message'=>'Missing token' ]], 401);
            return;
        }

        $secret  = $this->config->require('JWT_SECRET');
        $payload = Jwt::decode($token, $secret);
        if (!is_array($payload)) {
            http_response_code(401);
            Response::json(null, [], [[ 'code'=>'UNAUTHORIZED','message'=>'Invalid token' ]], 401);
            return;
        }

        if ($this->roles !== []) {
            $role = $payload['role'] ?? null;
            if (!is_string($role) || !in_array($role, $this->roles, true)) {
                http_response_code(403);
                Response::json(null, [], [[ 'code'=>'FORBIDDEN','message'=>'Insufficient role' ]], 403);
                return;
            }
        }

        $next($req);
    }
}
