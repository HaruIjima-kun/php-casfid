<?php
declare(strict_types=1);

namespace App\Infrastructure\Http;

final class Router
{
    /**
     * @var array<string, array<int, array{pattern:string,vars:string[],handler:callable}>>
     */
    private array $routes = [
        'GET' => [],
        'POST' => [],
        'PUT' => [],
        'DELETE' => [],
        'PATCH' => [],
    ];

    /**
     * @var array<int, object> Middlewares que implementan handle(Request $req, callable $next): void
     */
    private array $middlewares = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    /**
     * Registra un middleware.
     * El objeto debe exponer: handle(Request $req, callable $next): void
     */
    public function middleware(object $mw): void
    {
        $this->middlewares[] = $mw;
    }

    /**
     * Despacha la Request construyendo una pipeline de middlewares.
     */
    public function dispatch(Request $request): void
    {
        $method = strtoupper($request->method());
        $path = $request->path();

        // Match de ruta
        [$handler, $vars] = $this->match($method, $path);

        if ($handler === null) {
            // 405 si existe misma ruta con otro método; si no, 404
            if ($this->methodNotAllowed($path, $method)) {
                http_response_code(405);
                Response::json(null, [], [[
                    'code' => 'METHOD_NOT_ALLOWED',
                    'message' => 'Method not allowed',
                ]], 405);
                return;
            }

            http_response_code(404);
            Response::json(null, [], [[
                'code' => 'NOT_FOUND',
                'message' => 'Route not found',
            ]], 404);
            return;
        }

        // Exponemos variables de ruta a los handlers existentes
        $_SERVER['ROUTE_PARAMS'] = $vars;

        // Construimos la pipeline: middlewares + handler final
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            /**
             * @param callable(Request): void $next
             * @return callable(Request): void
             */
            function (callable $next, object $mw): callable {
                return function (Request $r) use ($mw, $next): void {
                    // Invoca $mw->handle($r, $next)
                    $mw->handle($r, $next);
                };
            },
            /**
             * Handler final de la ruta
             * @return callable(Request): void
             */
            function (Request $r) use ($handler): void {
                $handler($r);
            }
        );

        // Ejecutamos la pipeline
        $pipeline($request);
    }

    /**
     * @param string $method
     * @param string $path
     * @return array{0: (callable|null), 1: array<string,string>}
     */
    private function match(string $method, string $path): array
    {
        $path = '/' . ltrim($path, '/');

        // Intento directo exacto primero
        foreach ($this->routes[$method] ?? [] as $r) {
            if ($r['pattern'] === $path && $r['vars'] === []) {
                return [$r['handler'], []];
            }
        }

        // Intento con parámetros {var}
        foreach ($this->routes[$method] ?? [] as $r) {
            $regex = $this->toRegex($r['pattern'], $r['vars']);
            if (preg_match($regex, $path, $m)) {
                $vars = [];
                foreach ($r['vars'] as $name) {
                    if (isset($m[$name])) {
                        $vars[$name] = $m[$name];
                    }
                }
                return [$r['handler'], $vars];
            }
        }

        return [null, []];
    }

    /**
     * ¿Existe misma ruta con otro método? Para responder 405.
     */
    private function methodNotAllowed(string $path, string $method): bool
    {
        $path = '/' . ltrim($path, '/');
        foreach ($this->routes as $verb => $list) {
            if ($verb === $method) continue;
            foreach ($list as $r) {
                if ($r['pattern'] === $path) {
                    return true;
                }
                $regex = $this->toRegex($r['pattern'], $r['vars']);
                if (preg_match($regex, $path)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $path = '/' . ltrim($path, '/');

        // Extrae nombres {var}
        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $path, $m);
        $vars = $m[1] ?? [];

        $this->routes[$method][] = [
            'pattern' => $path,
            'vars' => $vars,
            'handler' => $handler,
        ];
    }

    /**
     * Convierte /api/v1/libros/{id} en regex con grupos con nombre.
     * @param string $pattern
     * @param string[] $vars
     */
    private function toRegex(string $pattern, array $vars): string
    {
        // Escapa slashes
        $regex = preg_quote($pattern, '#');

        // Reemplaza los {var} escapados por grupos con nombre
        foreach ($vars as $v) {
            // Sustituimos \{v\} por (?P<v>[^/]+)
            $regex = preg_replace(
                '#\\\\\{' . preg_quote($v, '#') . '\\\\\}#',
                '(?P<' . $v . '>[^/]+)',
                (string)$regex,
                1
            );
        }

        return '#^' . $regex . '$#u';
    }
}
