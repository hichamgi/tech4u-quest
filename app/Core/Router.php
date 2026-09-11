<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:callable|array}> */
    private array $routes = [];
    private Container $container;

    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? new Container();
    }

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

        $allowedMethods = [];

        foreach ($this->routes as $route) {
            $regex = $this->compilePattern($route['pattern']);

            if (!preg_match('#^' . $regex . '/?$#', $path, $matches)) {
                continue;
            }

            $allowedMethods[] = $route['method'];
            if ($route['method'] !== $method) {
                continue;
            }

            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = rawurldecode((string)$value);
                }
            }

            try {
                $this->invoke($route['handler'], $params);
            } catch (Throwable $e) {
                Logger::exception($e, [
                    'method' => $method,
                    'path' => $path,
                ]);
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: text/plain; charset=UTF-8');
                }
                echo 'Une erreur interne est survenue. Réessaie dans quelques instants.';
            }
            return;
        }

        if ($allowedMethods !== []) {
            $allowedMethods = array_values(array_unique($allowedMethods));
            http_response_code(405);
            header('Allow: ' . implode(', ', $allowedMethods));
            echo '405 - Méthode non autorisée';
            return;
        }

        http_response_code(404);
        echo '404 - Page introuvable';
    }

    private function compilePattern(string $pattern): string
    {
        if (!preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $pattern, $matches, PREG_OFFSET_CAPTURE)) {
            return preg_quote($pattern, '#');
        }

        $regex = '';
        $offset = 0;

        foreach ($matches[0] as $index => $match) {
            [$placeholder, $position] = $match;
            $regex .= preg_quote(substr($pattern, $offset, $position - $offset), '#');
            $name = (string)$matches[1][$index][0];
            $regex .= '(?P<' . $name . '>[^/]+)';
            $offset = $position + strlen($placeholder);
        }

        $regex .= preg_quote(substr($pattern, $offset), '#');
        return $regex;
    }

    private function stripBasePath(string $path): string
    {
        $candidates = [];

        $contextPrefix = trim((string)($_SERVER['CONTEXT_PREFIX'] ?? ''));
        if ($contextPrefix !== '') {
            $candidates[] = '/' . trim($contextPrefix, '/');
        }

        foreach (['SCRIPT_NAME', 'PHP_SELF'] as $key) {
            $scriptName = str_replace('\\', '/', (string)($_SERVER[$key] ?? ''));
            if ($scriptName === '') {
                continue;
            }

            $basePath = rtrim(dirname($scriptName), '/.');
            if ($basePath !== '' && $basePath !== '/') {
                $candidates[] = $basePath;
            }
        }

        $candidates[] = '/tech4u-quest';

        $candidates = array_values(array_unique($candidates));
        usort($candidates, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($candidates as $basePath) {
            if ($path === $basePath) {
                return '/';
            }

            if (str_starts_with($path, $basePath . '/')) {
                $stripped = substr($path, strlen($basePath));
                return $stripped === '' ? '/' : $stripped;
            }
        }

        return $path === '' ? '/' : $path;
    }

    /** @param callable|array{0:class-string,1:string} $handler */
    private function invoke(callable|array $handler, array $params): void
    {
        if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
            $controller = $this->container->get($handler[0]);
            $controller->{$handler[1]}(...array_values($params));
            return;
        }

        $handler(...array_values($params));
    }
}
