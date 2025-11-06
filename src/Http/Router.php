<?php
declare(strict_types=1);

namespace App\Http;

use App\Support\Request;

final class Router
{
    /** @var array<int, array{methods: array<int, string>, regex: string, vars: array<int, string>, handler: callable}> */
    private array $routes = [];

    public function add(array|string $methods, string $pattern, callable $handler): void
    {
        $methods = (array)$methods;
        $regex = $this->compilePattern($pattern, $vars);
        $this->routes[] = [
            'methods' => array_map('strtoupper', $methods),
            'regex'   => $regex,
            'vars'    => $vars,
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add(['GET'], $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add(['POST'], $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add(['PATCH'], $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add(['DELETE'], $pattern, $handler);
    }

    public function dispatch(Request $request): mixed
    {
        $path   = rtrim($request->path(), '/') ?: '/';
        $method = $request->method();

        foreach ($this->routes as $route) {
            if (!in_array($method, $route['methods'], true)) {
                continue;
            }

            if (preg_match($route['regex'], $path, $matches)) {
                $vars = [];
                foreach ($route['vars'] as $index => $name) {
                    $vars[$name] = $matches[$index + 1] ?? null;
                }

                return ($route['handler'])($request, $vars);
            }
        }

        return null;
    }

    /** @param array<int, string> $vars */
    private function compilePattern(string $pattern, ?array &$vars = null): string
    {
        $vars = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_-]*)\}#', function (array $matches) use (&$vars) {
            $vars[] = $matches[1];
            return '([^/]+)';
        }, $pattern);

        return '#^' . rtrim($regex, '/') . '$#';
    }
}