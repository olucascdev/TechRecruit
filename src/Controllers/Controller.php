<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

abstract class Controller
{
    /**
     * @param array<string, mixed> $data
     */
    protected function render(string $view, array $data = [], string $title = 'TechRecruit'): void
    {
        $viewPath = dirname(__DIR__) . '/Views/' . $view . '.php';
        $layoutPath = dirname(__DIR__) . '/Views/layout/base.php';

        if (!is_file($viewPath) || !is_file($layoutPath)) {
            http_response_code(500);
            echo 'View not found.';

            return;
        }

        $pageTitle = $title;
        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $pageScripts = '';
        $pageStyles = '';

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
        echo $json === false ? '{"success":false,"message":"JSON encoding error."}' : $json;
        exit;
    }

    protected function resolveOperator(): string
    {
        if (isset($_SESSION['operator']) && is_string($_SESSION['operator']) && $_SESSION['operator'] !== '') {
            return $_SESSION['operator'];
        }

        if (isset($_SERVER['REMOTE_USER']) && is_string($_SERVER['REMOTE_USER']) && $_SERVER['REMOTE_USER'] !== '') {
            return $_SERVER['REMOTE_USER'];
        }

        return 'system';
    }
}
