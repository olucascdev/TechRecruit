<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use InvalidArgumentException;
use TechRecruit\Services\AuthService;
use TechRecruit\Services\UserService;
use Throwable;

final class SetupController extends Controller
{
    private AuthService $authService;

    private UserService $userService;

    public function __construct(?AuthService $authService = null, ?UserService $userService = null)
    {
        $this->authService = $authService ?? new AuthService();
        $this->userService = $userService ?? new UserService();
    }

    public function show(): void
    {
        if ($this->authService->check()) {
            $this->redirect('/candidates');
        }

        $readinessError = $this->resolveReadinessError();

        if ($readinessError === null && $this->authService->hasAnyUser()) {
            $this->redirect('/login');
        }

        $this->renderForm([
            'full_name' => trim((string) ($_GET['full_name'] ?? '')),
            'email' => trim((string) ($_GET['email'] ?? '')),
            'username' => trim((string) ($_GET['username'] ?? '')),
        ], $readinessError, $readinessError === null);
    }

    public function store(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/setup');
        }

        $formData = [
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'username' => trim((string) ($_POST['username'] ?? '')),
        ];

        $readinessError = $this->resolveReadinessError();

        if ($readinessError !== null) {
            $this->renderForm($formData, $readinessError, false, 500);

            return;
        }

        if ($this->authService->hasAnyUser()) {
            $this->redirect('/login');
        }

        try {
            $this->userService->create([
                'full_name' => $formData['full_name'],
                'email' => $formData['email'],
                'username' => $formData['username'],
                'password' => (string) ($_POST['password'] ?? ''),
                'role' => 'admin',
            ], 'setup-bootstrap');

            $this->setFlash('success', 'Primeiro administrador criado. Faça login para acessar o backoffice.');
            $loginIdentifier = $formData['username'] !== '' ? $formData['username'] : $formData['email'];
            $this->redirect('/login?login=' . urlencode($loginIdentifier));
        } catch (InvalidArgumentException $exception) {
            $this->renderForm($formData, $exception->getMessage(), true, 422);
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->renderForm($formData, 'Não foi possível concluir o setup inicial.', true, 500);
        }
    }

    /**
     * @param array<string, mixed> $formData
     */
    private function renderForm(
        array $formData = [],
        ?string $errorMessage = null,
        bool $isReady = true,
        int $statusCode = 200
    ): void {
        http_response_code($statusCode);

        $this->render('auth/setup', [
            'formData' => array_merge([
                'full_name' => '',
                'email' => '',
                'username' => '',
            ], $formData),
            'errorMessage' => $errorMessage,
            'isReady' => $isReady,
            'authContext' => 'setup',
        ], 'Setup Inicial', 'layout/auth');
    }

    private function resolveReadinessError(): ?string
    {
        try {
            $this->authService->hasAnyUser();

            return null;
        } catch (Throwable) {
            return 'A tabela de usuários internos não está pronta. Rode as migrations 009 e 010 antes de abrir o setup.';
        }
    }
}
