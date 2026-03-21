<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use TechRecruit\Models\UserModel;
use TechRecruit\Security\Csrf;
use TechRecruit\Services\AuthService;
use TechRecruit\Support\AppUrl;

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
        $currentPath = AppUrl::routePath();
        $pageScripts = '';
        $pageStyles = '';
        $data['authUser'] = $this->currentUser();
        $data['csrfToken'] = $this->csrfToken();
        $data['basePath'] = AppUrl::basePath();
        $data['url'] = static fn (string $path = '/'): string => AppUrl::relative($path);
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
        header('Location: ' . AppUrl::relative($path));
        exit;
    }

    protected function redirectBack(string $fallback = '/candidates'): never
    {
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

        if ($referer !== '') {
            $path = AppUrl::routePath($referer);
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
        $path = AppUrl::routePath();
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
        $path = AppUrl::relative($path);
        $configuredAppUrl = $this->env('APP_URL');

        if ($configuredAppUrl !== null) {
            $normalizedAppUrl = $this->normalizeBaseUrl($configuredAppUrl);

            if ($normalizedAppUrl !== null) {
                $basePath = AppUrl::basePath();
                $routePath = $basePath !== '' && str_starts_with($path, $basePath)
                    ? substr($path, strlen($basePath)) ?: '/'
                    : $path;

                return $normalizedAppUrl . $routePath;
            }
        }

        return $this->requestScheme() . '://' . $this->requestHost() . $path;
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

    protected function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    private function requestScheme(): string
    {
        $forwardedProto = $this->forwardedHeaderValue('proto');

        if ($forwardedProto !== null) {
            return strtolower($forwardedProto) === 'https' ? 'https' : 'http';
        }

        $xForwardedProto = trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

        if ($xForwardedProto !== '') {
            $proto = strtolower(trim(explode(',', $xForwardedProto)[0]));

            return $proto === 'https' ? 'https' : 'http';
        }

        return isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off'
            ? 'https'
            : 'http';
    }

    private function requestHost(): string
    {
        $candidates = [
            $this->forwardedHeaderValue('host'),
            $this->firstForwardedHostValue((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? '')),
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
            (string) ($_SERVER['SERVER_NAME'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $host = $this->sanitizeHost($candidate);

            if ($host !== null) {
                return $host;
            }
        }

        return '127.0.0.1:8090';
    }

    private function normalizeBaseUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = isset($parts['host']) ? $this->sanitizeHost((string) $parts['host']) : null;

        if (!in_array($scheme, ['http', 'https'], true) || $host === null) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';

        return $scheme . '://' . $host . $port . $path;
    }

    private function forwardedHeaderValue(string $key): ?string
    {
        $forwarded = trim((string) ($_SERVER['HTTP_FORWARDED'] ?? ''));

        if ($forwarded === '') {
            return null;
        }

        $firstEntry = trim(explode(',', $forwarded)[0]);

        foreach (explode(';', $firstEntry) as $segment) {
            [$segmentKey, $segmentValue] = array_pad(explode('=', trim($segment), 2), 2, null);

            if (!is_string($segmentKey) || !is_string($segmentValue)) {
                continue;
            }

            if (strtolower(trim($segmentKey)) !== $key) {
                continue;
            }

            $segmentValue = trim($segmentValue, " \t\n\r\0\x0B\"");

            return $segmentValue !== '' ? $segmentValue : null;
        }

        return null;
    }

    private function firstForwardedHostValue(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        return trim(explode(',', $value)[0]);
    }

    private function sanitizeHost(?string $host): ?string
    {
        if (!is_string($host)) {
            return null;
        }

        $host = trim($host);

        if ($host === '' || str_contains($host, '/') || preg_match('/\s/', $host) === 1) {
            return null;
        }

        if (preg_match('/^\[[A-Fa-f0-9:]+\](?::\d{1,5})?$/', $host) === 1) {
            return $host;
        }

        if (preg_match('/^[A-Za-z0-9.-]+(?::\d{1,5})?$/', $host) !== 1) {
            return null;
        }

        return $host;
    }
}
