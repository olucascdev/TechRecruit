#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use TechRecruit\Services\UserService;

$options = getopt('', ['name:', 'email:', 'password:', 'role::']);
$name = trim((string) ($options['name'] ?? ''));
$email = trim((string) ($options['email'] ?? ''));
$password = (string) ($options['password'] ?? '');
$role = trim((string) ($options['role'] ?? 'manager'));

if ($name === '' || $email === '' || $password === '') {
    fwrite(STDERR, "Uso: php bin/create_management_user.php --name=\"Nome\" --email=\"email@empresa.com\" --password=\"senha-segura\" [--role=admin|manager]\n");
    exit(1);
}

try {
    $userService = new UserService();
    $userId = $userService->create([
        'full_name' => $name,
        'email' => $email,
        'password' => $password,
        'role' => $role,
    ], 'cli-bootstrap');

    fwrite(STDOUT, sprintf("Usuário interno criado com sucesso. ID: %d\n", $userId));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
