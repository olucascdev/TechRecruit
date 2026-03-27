<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use TechRecruit\Controllers\AuthController;
use TechRecruit\Controllers\CandidateController;
use TechRecruit\Controllers\CampaignController;
use TechRecruit\Controllers\ImportController;
use TechRecruit\Controllers\OperationsController;
use TechRecruit\Controllers\PortalController;
use TechRecruit\Controllers\FaqController;
use TechRecruit\Controllers\SetupController;
use TechRecruit\Controllers\TriageController;
use TechRecruit\Controllers\UserController;
use TechRecruit\Controllers\Api\CandidateApiController;
use TechRecruit\Router;
use TechRecruit\Security\Csrf;
use TechRecruit\Support\AppUrl;

function techRecruitRequestPath(): string
{
    return AppUrl::routePath();
}

function techRecruitUrl(string $path): string
{
    return AppUrl::relative($path);
}

function techRecruitExpectsJson(string $path): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));

    return str_contains($accept, 'application/json')
        || $requestedWith === 'xmlhttprequest'
        || str_contains($path, '/run');
}

function techRecruitFallbackRedirect(string $requestPath): string
{
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

    if ($referer !== '') {
        $path = AppUrl::routePath($referer);
        $query = parse_url($referer, PHP_URL_QUERY);

        if (is_string($path) && $path !== '' && str_starts_with($path, '/') && !str_starts_with($path, '//')) {
            return $path . (is_string($query) && $query !== '' ? '?' . $query : '');
        }
    }

    if ($requestPath === '/login' || $requestPath === '/setup') {
        return $requestPath;
    }

    if (preg_match('#^(/portal/[^/]+)/submit$#', $requestPath, $matches) === 1) {
        return $matches[1];
    }

    if (preg_match('#^/candidates/(\d+)/(delete|portal/generate|portal/status)$#', $requestPath, $matches) === 1) {
        return '/candidates/' . $matches[1];
    }

    if (
        $requestPath === '/candidates/status'
        || $requestPath === '/candidates/bulk-delete'
        || str_starts_with($requestPath, '/portal/documents/')
    ) {
        return '/candidates';
    }

    if (preg_match('#^/operations/candidates/(\d+)/(note|decision|documents/decision)$#', $requestPath, $matches) === 1) {
        return '/operations/' . $matches[1];
    }

    if ($requestPath === '/operations' || preg_match('#^/operations/\d+$#', $requestPath) === 1) {
        return $requestPath;
    }

    if (preg_match('#^/operations/pendencies/\d+/resolve$#', $requestPath) === 1) {
        return '/operations';
    }

    if (preg_match('#^/campaigns/(\d+)/(process|pause|resume|cancel|delete|reply|process/run)$#', $requestPath, $matches) === 1) {
        return '/campaigns/' . $matches[1];
    }

    if (
        $requestPath === '/campaigns'
        || $requestPath === '/campaigns/process-due'
        || $requestPath === '/campaigns/process-due/run'
    ) {
        return '/campaigns';
    }

    if (str_starts_with($requestPath, '/import/')) {
        return '/import';
    }

    if (str_starts_with($requestPath, '/management/users')) {
        return '/management/users';
    }

    return '/candidates';
}

function techRecruitHandleCsrfFailure(string $requestPath): never
{
    $message = 'Sessão expirada ou token inválido. Atualize a página e tente novamente.';

    if (techRecruitExpectsJson($requestPath)) {
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $_SESSION['error'] = $message;
    header('Location: ' . techRecruitUrl(techRecruitFallbackRedirect($requestPath)));
    exit;
}

$isHttps = (
    (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), camera=(), microphone=()');

if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

ini_set('session.use_strict_mode', '1');

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $isHttps,
]);

session_start();

try {
    $requestPath = techRecruitRequestPath();
    $requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    $isApiRequest = str_starts_with($requestPath, '/api/');

    if ($requestMethod === 'POST' && !$isApiRequest && $requestPath !== '/triage/inbound' && !Csrf::isValid(Csrf::requestToken())) {
        techRecruitHandleCsrfFailure($requestPath);
    }

    $router = new Router();

    $router->get('/api/candidates', [CandidateApiController::class, 'index']);
    $router->get('/api/candidates/{id}', [CandidateApiController::class, 'show']);

    $router->get('/setup', [SetupController::class, 'show']);
    $router->post('/setup', [SetupController::class, 'store']);
    $router->get('/login', [AuthController::class, 'showLogin']);
    $router->post('/login', [AuthController::class, 'authenticate']);
    $router->post('/logout', [AuthController::class, 'logout']);
    $router->get('/', [CandidateController::class, 'index']);
    $router->get('/candidates', [CandidateController::class, 'index']);
    $router->get('/candidates/{id}', [CandidateController::class, 'show']);
    $router->post('/candidates/status', [CandidateController::class, 'updateStatus']);
    $router->post('/candidates/bulk-delete', [CandidateController::class, 'bulkDestroy']);
    $router->post('/candidates/{id}/delete', [CandidateController::class, 'destroy']);
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
    $router->post('/campaigns/{id}/delete', [CampaignController::class, 'destroy']);
    $router->post('/campaigns/{id}/reply', [CampaignController::class, 'reply']);
    $router->post('/triage/inbound', [TriageController::class, 'inbound']);
    $router->get('/operations', [OperationsController::class, 'index']);
    $router->get('/operations/{id}', [OperationsController::class, 'show']);
    $router->get('/faq', [FaqController::class, 'index']);
    $router->post('/operations/candidates/{id}/note', [OperationsController::class, 'addNote']);
    $router->post('/operations/candidates/{id}/decision', [OperationsController::class, 'candidateDecision']);
    $router->post('/operations/documents/{id}/decision', [OperationsController::class, 'documentDecision']);
    $router->post('/operations/candidates/{id}/documents/decision', [OperationsController::class, 'documentDecisions']);
    $router->post('/operations/pendencies/{id}/resolve', [OperationsController::class, 'resolvePendency']);
    $router->get('/p/{shortCode}', [PortalController::class, 'short']);
    $router->get('/portal/{token}', [PortalController::class, 'show']);
    $router->post('/portal/{token}/submit', [PortalController::class, 'submit']);
    $router->get('/portal/documents/{id}', [PortalController::class, 'downloadDocument']);
    $router->post('/portal/documents/{id}/delete', [PortalController::class, 'deleteDocument']);
    $router->get('/import', [ImportController::class, 'index']);
    $router->post('/import/upload', [ImportController::class, 'upload']);
    $router->get('/import/result/{id}', [ImportController::class, 'result']);
    $router->post('/import/{id}/delete', [ImportController::class, 'destroy']);
    $router->get('/management/users', [UserController::class, 'index']);
    $router->post('/management/users', [UserController::class, 'store']);
    $router->post('/management/users/{id}/access', [UserController::class, 'updateAccess']);

    $router->dispatch();
} catch (Throwable $exception) {
    error_log((string) $exception);
    http_response_code(500);
    echo 'Internal server error.';
}
