<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use InvalidArgumentException;
use TechRecruit\Models\UserModel;

final class UserService
{
    private UserModel $userModel;

    public function __construct(?UserModel $userModel = null)
    {
        $this->userModel = $userModel ?? new UserModel();
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $input, ?string $createdBy = null): int
    {
        $fullName = $this->normalizeName((string) ($input['full_name'] ?? ''));
        $email = $this->normalizeEmail((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $role = trim((string) ($input['role'] ?? UserModel::ROLE_MANAGER));

        if (mb_strlen($fullName) < 3) {
            throw new InvalidArgumentException('Informe o nome completo do usuário.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um e-mail válido.');
        }

        if (!in_array($role, UserModel::VALID_ROLES, true)) {
            throw new InvalidArgumentException('Role inválida para o usuário.');
        }

        if (strlen($password) < 8) {
            throw new InvalidArgumentException('A senha deve ter pelo menos 8 caracteres.');
        }

        if (!$this->userModel->hasAnyUser() && $role !== UserModel::ROLE_ADMIN) {
            throw new InvalidArgumentException('O primeiro usuário interno deve ser um administrador.');
        }

        if ($this->userModel->findByEmail($email) !== null) {
            throw new InvalidArgumentException('Já existe um usuário interno com este e-mail.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if (!is_string($passwordHash) || $passwordHash === '') {
            throw new InvalidArgumentException('Não foi possível gerar a senha do usuário.');
        }

        return $this->userModel->create([
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role,
            'status' => UserModel::STATUS_ACTIVE,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $actor
     */
    public function updateAccess(int $userId, array $input, ?array $actor = null): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('Usuário inválido.');
        }

        $targetUser = $this->userModel->findById($userId);

        if ($targetUser === null) {
            throw new InvalidArgumentException('Usuário interno não encontrado.');
        }

        $role = trim((string) ($input['role'] ?? ''));
        $status = trim((string) ($input['status'] ?? ''));

        if (!in_array($role, UserModel::VALID_ROLES, true)) {
            throw new InvalidArgumentException('Selecione uma role válida.');
        }

        if (!in_array($status, UserModel::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Selecione um status válido.');
        }

        $actorId = (int) ($actor['id'] ?? 0);

        if ($actorId === $userId && $status === UserModel::STATUS_INACTIVE) {
            throw new InvalidArgumentException('Você não pode desativar o próprio acesso.');
        }

        $targetIsOnlyActiveAdmin = ($targetUser['role'] ?? null) === UserModel::ROLE_ADMIN
            && ($targetUser['status'] ?? null) === UserModel::STATUS_ACTIVE
            && $this->userModel->countActiveAdmins($userId) === 0;
        $willRemainActiveAdmin = $role === UserModel::ROLE_ADMIN && $status === UserModel::STATUS_ACTIVE;

        if ($targetIsOnlyActiveAdmin && !$willRemainActiveAdmin) {
            throw new InvalidArgumentException('O sistema precisa manter ao menos um administrador ativo.');
        }

        $this->userModel->updateAccess($userId, $role, $status);
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }
}
