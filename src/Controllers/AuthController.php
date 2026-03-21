<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use TechRecruit\Services\AuthService;
use Throwable;

final class AuthController extends Controller
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
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

        if ($login === '' || $password === '') {
            $this->setFlash('error', 'Informe usuário ou e-mail e senha para entrar.');
            $this->redirect('/login' . ($login !== '' ? '?login=' . urlencode($login) : ''));
        }

        $user = $this->authService->attempt($login, $password);

        if ($user === null) {
            $this->setFlash('error', 'Credenciais inválidas ou usuário inativo.');
            $this->redirect('/login?login=' . urlencode($login));
        }

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
}
