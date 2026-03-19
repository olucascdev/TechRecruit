<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TechRecruit\Controllers\CandidateController;
use TechRecruit\Controllers\CampaignController;
use TechRecruit\Controllers\ImportController;
use TechRecruit\Router;

$envPath = dirname(__DIR__) . '/.env';

if (is_file($envPath) && is_readable($envPath)) {
    $envValues = parse_ini_file($envPath, false, INI_SCANNER_RAW);

    if (is_array($envValues)) {
        foreach ($envValues as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $stringValue = is_scalar($value) ? (string) $value : '';

            putenv(sprintf('%s=%s', $key, $stringValue));
            $_ENV[$key] = $stringValue;
            $_SERVER[$key] = $stringValue;
        }
    }
}

session_start();

try {
    $router = new Router();

    $router->get('/', [CandidateController::class, 'index']);
    $router->get('/candidates', [CandidateController::class, 'index']);
    $router->get('/candidates/{id}', [CandidateController::class, 'show']);
    $router->post('/candidates/status', [CandidateController::class, 'updateStatus']);
    $router->get('/campaigns', [CampaignController::class, 'index']);
    $router->post('/campaigns', [CampaignController::class, 'store']);
    $router->get('/campaigns/{id}', [CampaignController::class, 'show']);
    $router->post('/campaigns/{id}/process', [CampaignController::class, 'process']);
    $router->post('/campaigns/{id}/pause', [CampaignController::class, 'pause']);
    $router->post('/campaigns/{id}/resume', [CampaignController::class, 'resume']);
    $router->post('/campaigns/{id}/cancel', [CampaignController::class, 'cancel']);
    $router->post('/campaigns/{id}/reply', [CampaignController::class, 'reply']);
    $router->get('/import', [ImportController::class, 'index']);
    $router->post('/import/upload', [ImportController::class, 'upload']);
    $router->get('/import/result/{id}', [ImportController::class, 'result']);

    $router->dispatch();
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(500);
    echo 'Internal server error.';
}
