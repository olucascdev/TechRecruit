<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$envPath = __DIR__ . '/.env';

if (is_file($envPath) && is_readable($envPath)) {
    $envValues = parse_ini_file($envPath, false, INI_SCANNER_RAW);

    if (is_array($envValues)) {
        foreach ($envValues as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $stringValue = is_scalar($value) ? (string) $value : '';

            putenv(sprintf('%s=%s', $key, $stringValue));
            $_ENV[$key] = $stringValue;
            $_SERVER[$key] = $stringValue;
        }
    }
}
