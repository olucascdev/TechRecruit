<?php

declare(strict_types=1);

namespace TechRecruit\Support;

final class AppUrl
{
    private static ?string $basePath = null;

    private function __construct()
    {
    }

    public static function basePath(): string
    {
        if (self::$basePath !== null) {
            return self::$basePath;
        }

        $configuredAppUrl = self::env('APP_URL');

        if (is_string($configuredAppUrl) && $configuredAppUrl !== '') {
            $configuredPath = parse_url($configuredAppUrl, PHP_URL_PATH);

            if (is_string($configuredPath)) {
                self::$basePath = self::normalizePath($configuredPath);

                return self::$basePath;
            }
        }

        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptDirectory = $scriptName !== '' ? dirname($scriptName) : '/';
        self::$basePath = self::normalizePath($scriptDirectory);

        return self::$basePath;
    }

    public static function routePath(?string $requestUri = null): string
    {
        $path = parse_url($requestUri ?? ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $basePath = self::basePath();

        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }

        return $path !== '/' ? (rtrim($path, '/') ?: '/') : '/';
    }

    public static function relative(string $path = '/'): string
    {
        if ($path === '' || $path === '/') {
            return self::basePath() !== '' ? self::basePath() : '/';
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 || str_starts_with($path, '//')) {
            return $path;
        }

        $normalizedPath = '/' . ltrim($path, '/');
        $basePath = self::basePath();

        if ($basePath !== '' && ($normalizedPath === $basePath || str_starts_with($normalizedPath, $basePath . '/'))) {
            return $normalizedPath;
        }

        return ($basePath !== '' ? $basePath : '') . $normalizedPath;
    }

    private static function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '' || $path === '.' || $path === '/') {
            return '';
        }

        $normalized = '/' . trim($path, '/');

        return $normalized === '/' ? '' : $normalized;
    }

    private static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
