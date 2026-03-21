<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use TechRecruit\Models\UserModel;

final class AuthService
{
    private const SESSION_USER_ID_KEY = 'auth_user_id';
    private const SESSION_INTENDED_URL_KEY = 'auth_intended_url';

    private UserModel $userModel;

    /** @var array<string, mixed>|null */
    private ?array $resolvedUser = null;

    private bool $hasResolvedUser = false;

    public function __construct(?UserModel $userModel = null)
    {
        $this->userModel = $userModel ?? new UserModel();
    }

    public function hasAnyUser(): bool
    {
        return $this->userModel->hasAnyUser();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function attempt(string $login, string $password): ?array
    {
        $normalizedLogin = $this->normalizeLogin($login);

        if ($normalizedLogin === '' || $password === '') {
            return null;
        }

        $user = $this->userModel->findByLogin($normalizedLogin);

        if ($user === null) {
            return null;
        }

        if (($user['status'] ?? null) !== UserModel::STATUS_ACTIVE) {
            return null;
        }

        if (!password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            return null;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_ID_KEY] = (int) $user['id'];
        $this->userModel->updateLastLoginAt((int) $user['id']);
        $this->hasResolvedUser = false;
        $this->resolvedUser = null;

        return $this->user();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->hasResolvedUser) {
            return $this->resolvedUser;
        }

        $this->hasResolvedUser = true;
        $userId = (int) ($_SESSION[self::SESSION_USER_ID_KEY] ?? 0);

        if ($userId < 1) {
            $this->resolvedUser = null;

            return null;
        }

        $user = $this->userModel->findById($userId);

        if ($user === null || ($user['status'] ?? null) !== UserModel::STATUS_ACTIVE) {
            $this->logout();

            return null;
        }

        $this->resolvedUser = $user;

        return $this->resolvedUser;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function isAdmin(): bool
    {
        $user = $this->user();

        return $user !== null && ($user['role'] ?? null) === UserModel::ROLE_ADMIN;
    }

    public function rememberIntendedUrl(string $path): void
    {
        if (!$this->isSafeInternalPath($path)) {
            return;
        }

        $_SESSION[self::SESSION_INTENDED_URL_KEY] = $path;
    }

    public function consumeIntendedUrl(): ?string
    {
        $path = $_SESSION[self::SESSION_INTENDED_URL_KEY] ?? null;
        unset($_SESSION[self::SESSION_INTENDED_URL_KEY]);

        if (!is_string($path) || !$this->isSafeInternalPath($path)) {
            return null;
        }

        return $path;
    }

    public function logout(): void
    {
        unset(
            $_SESSION[self::SESSION_USER_ID_KEY],
            $_SESSION[self::SESSION_INTENDED_URL_KEY]
        );

        $this->resolvedUser = null;
        $this->hasResolvedUser = true;
        session_regenerate_id(true);
    }

    private function normalizeLogin(string $login): string
    {
        return mb_strtolower(trim($login));
    }

    private function isSafeInternalPath(string $path): bool
    {
        if ($path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return false;
        }

        return !str_starts_with($path, '/login')
            && !str_starts_with($path, '/logout')
            && !str_starts_with($path, '/setup');
    }
}
