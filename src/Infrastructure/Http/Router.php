<?php
declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Router
{
    /** @var array<string, callable> */
    private array $get = [];
    /** @var array<string, callable> */
    private array $post = [];
    /** @var array<string, callable> */
    private array $put = [];
    /** @var array<string, callable> */
    private array $delete = [];

    public function get(string $path, callable $handler): void    { $this->get[$path]    = $handler; }
    public function post(string $path, callable $handler): void   { $this->post[$path]   = $handler; }
    public function put(string $path, callable $handler): void    { $this->put[$path]    = $handler; }
    public function delete(string $path, callable $handler): void { $this->delete[$path] = $handler; }

    public function dispatch(Request $req): void
    {
        $method = $req->method();
        $path   = $req->path();

        $table = match ($method) {
            'GET'    => $this->get,
            'POST'   => $this->post,
            'PUT'    => $this->put,
            'DELETE' => $this->delete,
            default  => []
        };

        $handler = $table[$path] ?? null;

        if (!$handler) {
            // No devolver valor en una función void
            Response::json(null, [], [
                ['code' => 'NOT_FOUND', 'message' => 'Route not found']
            ], 404);
            return;
        }

        // Firma simple: fn(Request $req): void
        $handler($req);
    }
}
