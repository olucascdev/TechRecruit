<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use TechRecruit\Security\RateLimiter;
use TechRecruit\Services\AuthService;
use Throwable;

final class AuthController extends Controller
{
    private const LOGIN_IP_MAX_ATTEMPTS = 20;
    private const LOGIN_IP_WINDOW_SECONDS = 300;
    private const LOGIN_IP_BLOCK_SECONDS = 900;

    private const LOGIN_IDENTITY_MAX_ATTEMPTS = 10;
    private const LOGIN_IDENTITY_WINDOW_SECONDS = 900;
    private const LOGIN_IDENTITY_BLOCK_SECONDS = 1800;

    private AuthService $authService;

    private RateLimiter $rateLimiter;

    public function __construct(?AuthService $authService = null, ?RateLimiter $rateLimiter = null)
    {
        $this->authService = $authService ?? new AuthService();
        $this->rateLimiter = $rateLimiter ?? new RateLimiter();
    }

    public function showLogin(): void
    {
        if ($this->authService->check()) {
            $this->redirect('/candidates');
        }

        try {
            $hasAnyUser = $this->authService->hasAnyUser();
        } catch (Throwable) {
            $hasAnyUser = false;
        }

        $this->render('auth/login', [
            'hasAnyUser' => $hasAnyUser,
            'defaultLogin' => trim((string) ($_GET['login'] ?? $_GET['email'] ?? '')),
            'authContext' => 'login',
        ], 'Login', 'layout/auth');
    }

    public function authenticate(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/login');
        }

        try {
            $hasAnyUser = $this->authService->hasAnyUser();
        } catch (Throwable) {
            $this->setFlash('error', 'A estrutura de usuários internos não está pronta. Rode as migrations 009 e 010 e abra /setup.');
            $this->redirect('/setup');
        }

        if (!$hasAnyUser) {
            $this->setFlash('error', 'Nenhum usuário interno foi criado ainda. Configure o primeiro administrador em /setup.');
            $this->redirect('/setup');
        }

        $login = trim((string) ($_POST['login'] ?? $_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $clientFingerprint = $this->resolveClientFingerprint();
        $loginKey = mb_strtolower($login);

        if ($this->isLoginBlocked('auth_login_ip', $clientFingerprint)) {
            $this->setFlash('error', $this->buildRateLimitMessage('Muitas tentativas de login deste dispositivo.'));
            $this->redirect('/login' . ($login !== '' ? '?login=' . urlencode($login) : ''));
        }

        if ($loginKey !== '' && $this->isLoginBlocked('auth_login_identity', $loginKey)) {
            $this->setFlash('error', $this->buildRateLimitMessage('Muitas tentativas para este usuário.'));
            $this->redirect('/login' . ($login !== '' ? '?login=' . urlencode($login) : ''));
        }

        if ($login === '' || $password === '') {
            $this->registerLoginFailure($clientFingerprint, $loginKey);
            $this->setFlash('error', 'Informe usuário ou e-mail e senha para entrar.');
            $this->redirect('/login' . ($login !== '' ? '?login=' . urlencode($login) : ''));
        }

        $user = $this->authService->attempt($login, $password);

        if ($user === null) {
            $this->registerLoginFailure($clientFingerprint, $loginKey);
            $this->setFlash('error', 'Credenciais inválidas ou usuário inativo.');
            $this->redirect('/login?login=' . urlencode($login));
        }

        $this->clearLoginRateLimits($clientFingerprint, $loginKey);

        $this->setFlash('success', 'Login realizado com sucesso.');
        $this->redirect($this->authService->consumeIntendedUrl() ?? '/candidates');
    }

    public function logout(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/candidates');
        }

        $this->authService->logout();
        $this->setFlash('success', 'Sessão encerrada.');
        $this->redirect('/login');
    }

    private function resolveClientFingerprint(): string
    {
        $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

        return $remoteAddress . '|' . substr(hash('sha256', $userAgent), 0, 16);
    }

    private function isLoginBlocked(string $scope, string $key): bool
    {
        if ($key === '') {
            return false;
        }

        try {
            $status = $this->rateLimiter->blocked($scope, $key);

            return (bool) ($status['blocked'] ?? false);
        } catch (Throwable $exception) {
            error_log('[AuthController] Rate limiter unavailable: ' . $exception->getMessage());

            return false;
        }
    }

    private function registerLoginFailure(string $clientFingerprint, string $loginKey): void
    {
        try {
            $this->rateLimiter->consume(
                'auth_login_ip',
                $clientFingerprint,
                self::LOGIN_IP_MAX_ATTEMPTS,
                self::LOGIN_IP_WINDOW_SECONDS,
                self::LOGIN_IP_BLOCK_SECONDS
            );

            if ($loginKey !== '') {
                $this->rateLimiter->consume(
                    'auth_login_identity',
                    $loginKey,
                    self::LOGIN_IDENTITY_MAX_ATTEMPTS,
                    self::LOGIN_IDENTITY_WINDOW_SECONDS,
                    self::LOGIN_IDENTITY_BLOCK_SECONDS
                );
            }
        } catch (Throwable $exception) {
            error_log('[AuthController] Failed to register login failure in limiter: ' . $exception->getMessage());
        }
    }

    private function clearLoginRateLimits(string $clientFingerprint, string $loginKey): void
    {
        try {
            if ($clientFingerprint !== '') {
                $this->rateLimiter->clear('auth_login_ip', $clientFingerprint);
            }

            if ($loginKey !== '') {
                $this->rateLimiter->clear('auth_login_identity', $loginKey);
            }
        } catch (Throwable $exception) {
            error_log('[AuthController] Failed to clear login limiter: ' . $exception->getMessage());
        }
    }

    private function buildRateLimitMessage(string $prefix): string
    {
        return $prefix . ' Aguarde alguns minutos e tente novamente.';
    }
}
