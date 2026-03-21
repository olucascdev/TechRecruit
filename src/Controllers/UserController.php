<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use InvalidArgumentException;
use TechRecruit\Models\UserModel;
use TechRecruit\Services\UserService;
use Throwable;

final class UserController extends Controller
{
    private UserModel $userModel;

    private UserService $userService;

    public function __construct(?UserModel $userModel = null, ?UserService $userService = null)
    {
        $this->requireAdmin();
        $this->userModel = $userModel ?? new UserModel();
        $this->userService = $userService ?? new UserService($this->userModel);
    }

    public function index(): void
    {
        $this->renderIndex();
    }

    public function store(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/management/users');
        }

        $formData = [
            'full_name' => trim((string) ($_POST['full_name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'role' => trim((string) ($_POST['role'] ?? UserModel::ROLE_MANAGER)),
        ];

        try {
            $currentUser = $this->currentUser();
            $createdBy = $currentUser['full_name'] ?? $currentUser['email'] ?? 'system';
            $this->userService->create($_POST, is_string($createdBy) ? $createdBy : 'system');
            $this->setFlash('success', 'Usuário interno criado com sucesso.');
            $this->redirect('/management/users');
        } catch (InvalidArgumentException $exception) {
            $this->renderIndex($formData, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash(
                'error',
                trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao criar o usuário interno.'
            );
            $this->redirect('/management/users');
        }
    }

    public function updateAccess(int $id): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/management/users');
        }

        try {
            $this->userService->updateAccess($id, $_POST, $this->currentUser());
            $this->setFlash('success', 'Acesso do usuário atualizado.');
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash(
                'error',
                trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao atualizar o acesso do usuário.'
            );
        }

        $this->redirect('/management/users');
    }

    /**
     * @param array<string, mixed> $formData
     */
    private function renderIndex(array $formData = [], ?string $errorMessage = null, int $statusCode = 200): void
    {
        http_response_code($statusCode);

        $this->render('users/index', [
            'users' => $this->userModel->findAll(),
            'roles' => UserModel::ROLE_LABELS,
            'statuses' => UserModel::STATUS_LABELS,
            'formData' => array_merge([
                'full_name' => '',
                'email' => '',
                'role' => UserModel::ROLE_MANAGER,
            ], $formData),
            'errorMessage' => $errorMessage,
        ], 'Usuários Internos');
    }
}
