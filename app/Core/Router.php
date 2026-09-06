<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:callable|array}> */
    private array $routes = [];

    public function get(string $pattern, callable|array $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable|array $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable|array $handler): void
    {
        $pattern = '/' . trim($pattern, '/');
        if ($pattern === '//') {
            $pattern = '/';
        }

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function dispatch(?string $method = null, ?string $uri = null): void
    {
        $method ??= strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri ??= (string)($_SERVER['REQUEST_URI'] ?? '/');

        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = $this->stripBasePath($path);
        $path = '/' . trim($path, '/');
        if ($path === '//') {
            $path = '/';
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $regex = preg_replace(
                '#\{([A-Za-z_][A-Za-z0-9_]*)\}#',
                '(?P<$1>[^/]+)',
                $route['pattern']
            );

            if ($regex === null) {
                throw new RuntimeException('Impossible de compiler la route.');
            }

            if (!preg_match('#^' . $regex . '/?$#', $path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode((string)$value);
                }
            }

            $this->invoke($route['handler'], $params);
            return;
        }

        http_response_code(404);
        echo '404 - Page introuvable';
    }

    private function stripBasePath(string $path): string
    {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim(dirname($scriptName), '/.');

        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        return $path === '' ? '/' : $path;
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    private function invoke(callable|array $handler, array $params): void
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
            $controller = new $handler[0]();
            $controller->{$handler[1]}(...array_values($params));
            return;
        }

        $handler(...array_values($params));
    }
}
