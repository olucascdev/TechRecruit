<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use TechRecruit\Controllers\CandidateController;
use TechRecruit\Controllers\CampaignController;
use TechRecruit\Controllers\ImportController;
use TechRecruit\Controllers\OperationsController;
use TechRecruit\Controllers\PortalController;
use TechRecruit\Controllers\TriageController;
use TechRecruit\Router;

session_start();

try {
    $router = new Router();

    $router->get('/', [CandidateController::class, 'index']);
    $router->get('/candidates', [CandidateController::class, 'index']);
    $router->get('/candidates/{id}', [CandidateController::class, 'show']);
    $router->post('/candidates/status', [CandidateController::class, 'updateStatus']);
    $router->post('/candidates/{id}/portal/generate', [PortalController::class, 'generate']);
    $router->post('/candidates/{id}/portal/status', [PortalController::class, 'updateStatus']);
    $router->get('/campaigns', [CampaignController::class, 'index']);
    $router->post('/campaigns', [CampaignController::class, 'store']);
    $router->post('/campaigns/process-due', [CampaignController::class, 'processDue']);
    $router->post('/campaigns/process-due/run', [CampaignController::class, 'processDueApi']);
    $router->get('/campaigns/{id}', [CampaignController::class, 'show']);
    $router->post('/campaigns/{id}/process', [CampaignController::class, 'process']);
    $router->post('/campaigns/{id}/process/run', [CampaignController::class, 'processApi']);
    $router->post('/campaigns/{id}/pause', [CampaignController::class, 'pause']);
    $router->post('/campaigns/{id}/resume', [CampaignController::class, 'resume']);
    $router->post('/campaigns/{id}/cancel', [CampaignController::class, 'cancel']);
    $router->post('/campaigns/{id}/reply', [CampaignController::class, 'reply']);
    $router->post('/triage/inbound', [TriageController::class, 'inbound']);
    $router->get('/operations', [OperationsController::class, 'index']);
    $router->post('/operations/candidates/{id}/note', [OperationsController::class, 'addNote']);
    $router->post('/operations/candidates/{id}/decision', [OperationsController::class, 'candidateDecision']);
    $router->post('/operations/documents/{id}/decision', [OperationsController::class, 'documentDecision']);
    $router->post('/operations/pendencies/{id}/resolve', [OperationsController::class, 'resolvePendency']);
    $router->get('/portal/{token}', [PortalController::class, 'show']);
    $router->post('/portal/{token}/submit', [PortalController::class, 'submit']);
    $router->get('/portal/documents/{id}', [PortalController::class, 'downloadDocument']);
    $router->get('/import', [ImportController::class, 'index']);
    $router->post('/import/upload', [ImportController::class, 'upload']);
    $router->get('/import/result/{id}', [ImportController::class, 'result']);

    $router->dispatch();
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(500);
    echo 'Internal server error.';
}
