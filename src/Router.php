<?php

declare(strict_types=1);

namespace TechRecruit;

use Closure;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use TechRecruit\Support\AppUrl;

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
        $path = AppUrl::routePath();

        foreach ($this->routes[$method] ?? [] as $route) {
            $regex = $this->patternToRegex($route['pattern']);

            if (preg_match($regex, $path, $matches) !== 1) {
                continue;
            }

            $parameters = $this->extractRouteParameters($matches);

            $handler = $route['handler'];

            if (is_array($handler) && isset($handler[0], $handler[1]) && is_string($handler[0])) {
                $controllerClass = $handler[0];
                $methodName = $handler[1];
                $normalizedParameters = $this->normalizeMethodParameters($controllerClass, $methodName, $parameters);

                if ($normalizedParameters === null) {
                    continue;
                }

                $controller = new $controllerClass();

                $controller->{$methodName}(...$normalizedParameters);

                return;
            }

            $callable = Closure::fromCallable($handler);
            $normalizedParameters = $this->normalizeFunctionParameters($callable, $parameters);

            if ($normalizedParameters === null) {
                continue;
            }

            $callable(...$normalizedParameters);

            return;
        }

        http_response_code(404);
        echo 'Página não encontrada.';
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

    /**
     * @param array<int|string, string> $matches
     * @return list<string|int>
     */
    private function extractRouteParameters(array $matches): array
    {
        $parameters = [];

        foreach ($matches as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $parameters[] = ctype_digit($value) ? (int) $value : $value;
        }

        return $parameters;
    }

    /**
     * @param list<string|int> $parameters
     * @return list<mixed>|null
     */
    private function normalizeMethodParameters(string $controllerClass, string $methodName, array $parameters): ?array
    {
        return $this->normalizeParameters(new ReflectionMethod($controllerClass, $methodName), $parameters);
    }

    /**
     * @param list<string|int> $parameters
     * @return list<mixed>|null
     */
    private function normalizeFunctionParameters(Closure $callable, array $parameters): ?array
    {
        return $this->normalizeParameters(new ReflectionFunction($callable), $parameters);
    }

    /**
     * @param list<string|int> $parameters
     * @return list<mixed>|null
     */
    private function normalizeParameters(ReflectionFunctionAbstract $reflection, array $parameters): ?array
    {
        $reflectionParameters = $reflection->getParameters();
        $requiredCount = $reflection->getNumberOfRequiredParameters();

        if (count($parameters) < $requiredCount) {
            return null;
        }

        $allowsVariadic = false;

        foreach ($reflectionParameters as $reflectionParameter) {
            if ($reflectionParameter->isVariadic()) {
                $allowsVariadic = true;
                break;
            }
        }

        if (!$allowsVariadic && count($parameters) > count($reflectionParameters)) {
            return null;
        }

        $normalized = [];
        $parameterIndex = 0;

        foreach ($reflectionParameters as $reflectionParameter) {
            if ($reflectionParameter->isVariadic()) {
                while (array_key_exists($parameterIndex, $parameters)) {
                    $normalized[] = $parameters[$parameterIndex];
                    $parameterIndex++;
                }

                break;
            }

            if (!array_key_exists($parameterIndex, $parameters)) {
                if ($reflectionParameter->isDefaultValueAvailable()) {
                    continue;
                }

                return null;
            }

            $coercion = $this->coerceRouteParameterValue($reflectionParameter->getType(), $parameters[$parameterIndex]);

            if (!($coercion['accepted'] ?? false)) {
                return null;
            }

            $normalized[] = $coercion['value'] ?? null;
            $parameterIndex++;
        }

        return $normalized;
    }

    /**
     * @return array{accepted:bool,value:mixed}
     */
    private function coerceRouteParameterValue(?ReflectionType $type, mixed $value): array
    {
        if ($type === null) {
            return [
                'accepted' => true,
                'value' => $value,
            ];
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                $coercion = $this->coerceNamedRouteType($unionType, $value);

                if ($coercion['accepted']) {
                    return $coercion;
                }
            }

            return [
                'accepted' => false,
                'value' => null,
            ];
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->coerceNamedRouteType($type, $value);
        }

        return [
            'accepted' => true,
            'value' => $value,
        ];
    }

    /**
     * @return array{accepted:bool,value:mixed}
     */
    private function coerceNamedRouteType(ReflectionNamedType $type, mixed $value): array
    {
        if ($value === null) {
            return [
                'accepted' => $type->allowsNull(),
                'value' => null,
            ];
        }

        if (!$type->isBuiltin()) {
            return [
                'accepted' => is_object($value) && is_a($value, $type->getName()),
                'value' => $value,
            ];
        }

        return match ($type->getName()) {
            'int' => $this->coerceIntRouteType($value),
            'string' => [
                'accepted' => is_string($value) || is_int($value) || is_float($value) || is_bool($value),
                'value' => (string) $value,
            ],
            'float' => $this->coerceFloatRouteType($value),
            'bool' => $this->coerceBoolRouteType($value),
            default => [
                'accepted' => true,
                'value' => $value,
            ],
        };
    }

    /**
     * @return array{accepted:bool,value:mixed}
     */
    private function coerceIntRouteType(mixed $value): array
    {
        if (is_int($value)) {
            return [
                'accepted' => true,
                'value' => $value,
            ];
        }

        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return [
                'accepted' => true,
                'value' => (int) $value,
            ];
        }

        return [
            'accepted' => false,
            'value' => null,
        ];
    }

    /**
     * @return array{accepted:bool,value:mixed}
     */
    private function coerceFloatRouteType(mixed $value): array
    {
        if (is_float($value) || is_int($value)) {
            return [
                'accepted' => true,
                'value' => (float) $value,
            ];
        }

        if (is_string($value) && is_numeric($value)) {
            return [
                'accepted' => true,
                'value' => (float) $value,
            ];
        }

        return [
            'accepted' => false,
            'value' => null,
        ];
    }

    /**
     * @return array{accepted:bool,value:mixed}
     */
    private function coerceBoolRouteType(mixed $value): array
    {
        if (is_bool($value)) {
            return [
                'accepted' => true,
                'value' => $value,
            ];
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return [
                'accepted' => true,
                'value' => $value === 1,
            ];
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return [
                    'accepted' => true,
                    'value' => true,
                ];
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return [
                    'accepted' => true,
                    'value' => false,
                ];
            }
        }

        return [
            'accepted' => false,
            'value' => null,
        ];
    }
}
