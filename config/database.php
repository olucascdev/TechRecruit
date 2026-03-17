<?php

declare(strict_types=1);

$envPath = dirname(__DIR__) . '/.env';

if (is_file($envPath) && is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $line, 2));

            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            $value = trim($value, "\"'");

            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

$env = static function (string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
};

return [
    'host' => $env('DB_HOST', 'localhost'),
    'database' => $env('DB_NAME', ''),
    'username' => $env('DB_USER', 'root'),
    'password' => $env('DB_PASS', ''),
    'charset' => 'utf8mb4',
];
