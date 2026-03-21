<?php

declare(strict_types=1);

namespace TechRecruit\Models;

use PDO;
use TechRecruit\Database;

final class UserModel
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const ROLE_LABELS = [
        self::ROLE_ADMIN => 'Administrador',
        self::ROLE_MANAGER => 'Gestão',
    ];

    public const STATUS_LABELS = [
        self::STATUS_ACTIVE => 'Ativo',
        self::STATUS_INACTIVE => 'Inativo',
    ];

    public const VALID_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_MANAGER,
    ];

    public const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    public function hasAnyUser(): bool
    {
        $statement = $this->pdo->query(
            'SELECT id
             FROM recruit_management_users
             LIMIT 1'
        );

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, full_name, email, password_hash, role, status, last_login_at, created_by, created_at, updated_at
             FROM recruit_management_users
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, full_name, email, role, status, last_login_at, created_by, created_at, updated_at
             FROM recruit_management_users
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $statement = $this->pdo->query(
            "SELECT id, full_name, email, role, status, last_login_at, created_by, created_at, updated_at
             FROM recruit_management_users
             ORDER BY
                 CASE role
                     WHEN 'admin' THEN 0
                     ELSE 1
                 END,
                 CASE status
                     WHEN 'active' THEN 0
                     ELSE 1
                 END,
                 full_name ASC,
                 id ASC"
        );

        return $statement->fetchAll();
    }

    /**
     * @param array{
     *     full_name:string,
     *     email:string,
     *     password_hash:string,
     *     role:string,
     *     status:string,
     *     created_by:string|null
     * } $userData
     */
    public function create(array $userData): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_management_users (
                full_name,
                email,
                password_hash,
                role,
                status,
                created_by
            ) VALUES (
                :full_name,
                :email,
                :password_hash,
                :role,
                :status,
                :created_by
            )'
        );
        $statement->execute([
            'full_name' => $userData['full_name'],
            'email' => $userData['email'],
            'password_hash' => $userData['password_hash'],
            'role' => $userData['role'],
            'status' => $userData['status'],
            'created_by' => $userData['created_by'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateLastLoginAt(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recruit_management_users
             SET last_login_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }

    public function updateAccess(int $userId, string $role, string $status): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recruit_management_users
             SET role = :role,
                 status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $userId,
            'role' => $role,
            'status' => $status,
        ]);
    }

    public function countActiveAdmins(?int $excludingUserId = null): int
    {
        $sql = "SELECT COUNT(*)
                FROM recruit_management_users
                WHERE role = :role
                  AND status = :status";
        $params = [
            'role' => self::ROLE_ADMIN,
            'status' => self::STATUS_ACTIVE,
        ];

        if ($excludingUserId !== null && $excludingUserId > 0) {
            $sql .= ' AND id <> :excluding_id';
            $params['excluding_id'] = $excludingUserId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
