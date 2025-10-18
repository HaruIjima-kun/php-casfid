<?php
declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Router
{
    /** @var array<string, array<int, array{path:string,regex:?string,vars:array<int,string>,handler:callable}>> */
    private array $routes = [
        'GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []
    ];

    public function get(string $path, callable $handler): void    { $this->add('GET', $path, $handler); }
    public function post(string $path, callable $handler): void   { $this->add('POST', $path, $handler); }
    public function put(string $path, callable $handler): void    { $this->add('PUT', $path, $handler); }
    public function delete(string $path, callable $handler): void { $this->add('DELETE', $path, $handler); }

    private function add(string $method, string $path, callable $handler): void
    {
        // Soportar placeholders {param}
        $vars = [];
        $regex = null;

        if (str_contains($path, '{')) {
            $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($m) use (&$vars) {
                $vars[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            }, $path);
            $regex = '#^' . $regex . '$#';
        }

        $this->routes[$method][] = [
            'path' => $path,
            'regex' => $regex,
            'vars' => $vars,
            'handler' => $handler
        ];
    }

    public function dispatch(Request $req): void
    {
        $method = $req->method();
        $path   = $req->path();
        $cands  = $this->routes[$method] ?? [];

        // 1) Intento exacto primero
        foreach ($cands as $r) {
            if ($r['regex'] === null && $r['path'] === $path) {
                $r['handler']($req);
                return;
            }
        }
        // 2) Intento con placeholders
        foreach ($cands as $r) {
            if ($r['regex'] !== null && preg_match($r['regex'], $path, $m)) {
                // Exponer params nombrados en superglobal (simple por ahora)
                $params = [];
                foreach ($r['vars'] as $v) {
                    if (isset($m[$v])) $params[$v] = $m[$v];
                }
                $_SERVER['ROUTE_PARAMS'] = $params;
                $r['handler']($req);
                return;
            }
        }

        Response::json(null, [], [
            ['code' => 'NOT_FOUND', 'message' => 'Route not found']
        ], 404);
    }
}
