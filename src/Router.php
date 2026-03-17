<?php

declare(strict_types=1);

namespace TechRecruit;

use Closure;

final class Router
{
    /** @var array<string, list<array{pattern:string,handler:callable|array{0:class-string,1:string}}>> */
    private array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    /**
     * @param callable|array{0:class-string,1:string} $handler
     */
    public function get(string $pattern, callable|array $handler): void
    {
        $this->register('GET', $pattern, $handler);
    }

    /**
     * @param callable|array{0:class-string,1:string} $handler
     */
    public function post(string $pattern, callable|array $handler): void
    {
        $this->register('POST', $pattern, $handler);
    }

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            $regex = $this->patternToRegex($route['pattern']);

            if (preg_match($regex, $path, $matches) !== 1) {
                continue;
            }

            $parameters = [];

            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $parameters[] = ctype_digit($value) ? (int) $value : $value;
                }
            }

            $handler = $route['handler'];

            if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
                $controller = new $handler[0]();
                $methodName = $handler[1];
                $controller->{$methodName}(...$parameters);

                return;
            }

            Closure::fromCallable($handler)(...$parameters);

            return;
        }

        http_response_code(404);
        echo 'Page not found.';
    }

    /**
     * @param callable|array{0:class-string,1:string} $handler
     */
    private function register(string $method, string $pattern, callable|array $handler): void
    {
        $normalizedPattern = $pattern !== '/' ? rtrim($pattern, '/') : '/';

        $this->routes[$method][] = [
            'pattern' => $normalizedPattern,
            'handler' => $handler,
        ];
    }

    private function patternToRegex(string $pattern): string
    {
        $regex = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $pattern);

        return '#^' . $regex . '$#';
    }
}
