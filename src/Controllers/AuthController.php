<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use TechRecruit\Services\AuthService;

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

        $this->render('auth/login', [
            'hasAnyUser' => $this->authService->hasAnyUser(),
            'defaultEmail' => trim((string) ($_GET['email'] ?? '')),
        ], 'Login', 'layout/auth');
    }

    public function authenticate(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/login');
        }

        if (!$this->authService->hasAnyUser()) {
            $this->setFlash('error', 'Nenhum usuário interno foi criado ainda. Inicialize o primeiro admin via CLI.');
            $this->redirect('/login');
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->setFlash('error', 'Informe e-mail e senha para entrar.');
            $this->redirect('/login' . ($email !== '' ? '?email=' . urlencode($email) : ''));
        }

        $user = $this->authService->attempt($email, $password);

        if ($user === null) {
            $this->setFlash('error', 'Credenciais inválidas ou usuário inativo.');
            $this->redirect('/login?email=' . urlencode($email));
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
