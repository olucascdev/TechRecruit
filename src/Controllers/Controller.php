<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use TechRecruit\Models\UserModel;
use TechRecruit\Security\Csrf;
use TechRecruit\Services\AuthService;

abstract class Controller
{
    private ?AuthService $authService = null;

    /**
     * @param array<string, mixed> $data
     */
    protected function render(
        string $view,
        array $data = [],
        string $title = 'TechRecruit',
        string $layout = 'layout/base'
    ): void
    {
        $viewPath = dirname(__DIR__) . '/Views/' . $view . '.php';
        $layoutPath = dirname(__DIR__) . '/Views/' . $layout . '.php';

        if (!is_file($viewPath) || !is_file($layoutPath)) {
            http_response_code(500);
            echo 'View não encontrada.';

            return;
        }

        $pageTitle = $title;
        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $pageScripts = '';
        $pageStyles = '';
        $data['authUser'] = $this->currentUser();
        $data['csrfToken'] = $this->csrfToken();
        $data['csrfField'] = sprintf(
            '<input type="hidden" name="_token" value="%s">',
            htmlspecialchars($this->csrfToken(), ENT_QUOTES, 'UTF-8')
        );

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewPath;
        $content = (string) ob_get_clean();

        require $layoutPath;
    }

    protected function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    protected function redirectBack(string $fallback = '/candidates'): never
    {
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

        if ($referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            $query = parse_url($referer, PHP_URL_QUERY);

            if (is_string($path) && $path !== '' && str_starts_with($path, '/') && !str_starts_with($path, '//')) {
                $this->redirect($path . (is_string($query) && $query !== '' ? '?' . $query : ''));
            }
        }

        $this->redirect($fallback);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function currentUser(): ?array
    {
        return $this->authService()->user();
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireAuth(): array
    {
        try {
            $hasAnyUser = $this->authService()->hasAnyUser();
        } catch (\Throwable) {
            $this->setFlash('error', 'A estrutura de usuários internos não está pronta. Rode as migrations de acesso e abra /setup.');
            $this->redirect('/setup');
        }

        if (!$hasAnyUser) {
            $this->setFlash('error', 'Configure o primeiro administrador antes de acessar o backoffice.');
            $this->redirect('/setup');
        }

        $user = $this->currentUser();

        if ($user !== null) {
            return $user;
        }

        $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $query = trim((string) ($_SERVER['QUERY_STRING'] ?? ''));

        if ($requestMethod === 'GET') {
            $this->authService()->rememberIntendedUrl($path . ($query !== '' ? '?' . $query : ''));
        }

        $this->setFlash('error', 'Faça login para acessar o backoffice.');
        $this->redirect('/login');
    }

    /**
     * @param list<string> $roles
     * @return array<string, mixed>
     */
    protected function requireRole(array $roles): array
    {
        $user = $this->requireAuth();

        if (in_array((string) ($user['role'] ?? ''), $roles, true)) {
            return $user;
        }

        $this->denyAccess('Seu usuário não tem permissão para acessar esta área.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireAdmin(): array
    {
        return $this->requireRole([UserModel::ROLE_ADMIN]);
    }

    protected function setFlash(string $type, string $message): void
    {
        if (!in_array($type, ['success', 'error'], true)) {
            return;
        }

        $_SESSION[$type] = $message;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function json(array $payload, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo $json === false ? '{"success":false,"message":"Erro ao codificar JSON."}' : $json;
        exit;
    }

    protected function absoluteUrl(string $path): string
    {
        $isHttps = isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8090');

        return $scheme . '://' . $host . $path;
    }

    protected function resolveOperator(): string
    {
        $currentUser = $this->currentUser();

        if ($currentUser !== null) {
            $displayName = trim((string) ($currentUser['full_name'] ?? ''));

            if ($displayName !== '') {
                return $displayName;
            }

            $email = trim((string) ($currentUser['email'] ?? ''));

            if ($email !== '') {
                return $email;
            }
        }

        if (isset($_SESSION['operator']) && is_string($_SESSION['operator']) && $_SESSION['operator'] !== '') {
            return $_SESSION['operator'];
        }

        if (isset($_SERVER['REMOTE_USER']) && is_string($_SERVER['REMOTE_USER']) && $_SERVER['REMOTE_USER'] !== '') {
            return $_SERVER['REMOTE_USER'];
        }

        return 'system';
    }

    protected function authService(): AuthService
    {
        if ($this->authService instanceof AuthService) {
            return $this->authService;
        }

        $this->authService = new AuthService();

        return $this->authService;
    }

    protected function denyAccess(string $message): never
    {
        if ($this->expectsJson()) {
            $this->json([
                'success' => false,
                'message' => $message,
            ], 403);
        }

        $this->setFlash('error', $message);
        $this->redirect('/candidates');
    }

    protected function expectsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return str_contains($accept, 'application/json')
            || $requestedWith === 'xmlhttprequest'
            || str_contains($requestUri, '/run');
    }

    protected function csrfToken(): string
    {
        return Csrf::token();
    }
}
