<?php

declare(strict_types=1);

namespace TechRecruit\Security;

final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    private function __construct()
    {
    }

    public static function token(): string
    {
        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function requestToken(): ?string
    {
        $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);

        return $token !== '' ? $token : null;
    }

    public static function isValid(?string $token): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($token)
            && $token !== ''
            && is_string($sessionToken)
            && hash_equals($sessionToken, $token);
    }
}
