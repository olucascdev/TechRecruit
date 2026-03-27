<?php

declare(strict_types=1);

namespace TechRecruit\Controllers\Api;

use PDO;
use TechRecruit\Database;
use TechRecruit\Models\CandidateModel;
use TechRecruit\Models\OperationsModel;
use TechRecruit\Models\PortalModel;
use TechRecruit\Models\TriageModel;

final class CandidateApiController
{
    private PDO $pdo;
    private CandidateModel $candidateModel;
    private PortalModel $portalModel;
    private OperationsModel $operationsModel;
    private TriageModel $triageModel;

    public function __construct()
    {
        $this->authenticate();
        $this->pdo = Database::connect();
        $this->candidateModel = new CandidateModel($this->pdo);
        $this->portalModel = new PortalModel($this->pdo);
        $this->operationsModel = new OperationsModel($this->pdo);
        $this->triageModel = new TriageModel($this->pdo);
    }

    /**
     * GET /api/candidates
     * Query params: page, per_page (max 100), search, status, skill, state
     */
    public function index(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 30)));

        $filters = array_filter([
            'search' => trim((string) ($_GET['search'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'skill'  => trim((string) ($_GET['skill'] ?? '')),
            'state'  => trim((string) ($_GET['state'] ?? '')),
        ], static fn (string $v): bool => $v !== '');

        $result = $this->candidateModel->findAll($filters, $page, $perPage);
        $totalPages = max(1, (int) ceil($result['total'] / $perPage));

        $this->json([
            'data' => $result['data'],
            'meta' => [
                'page'        => $result['page'],
                'per_page'    => $perPage,
                'total'       => $result['total'],
                'total_pages' => $totalPages,
            ],
        ]);
    }

    /**
     * GET /api/candidates/{id}
     * Returns full candidate data: contacts, skills, addresses, status_history,
     * portal (profile + documents + checklist), operations (pendencies + history),
     * and latest triage session.
     */
    public function show(int $id): void
    {
        $candidate = $this->candidateModel->findById($id);

        if ($candidate === null) {
            $this->json(['message' => 'Candidato não encontrado.'], 404);
        }

        $candidate['portal']         = $this->portalModel->findByCandidateId($id);
        $candidate['operations']     = $this->operationsModel->findByCandidateId($id);
        $candidate['triage_session'] = $this->triageModel->findLatestSessionByCandidateId($id);

        $this->json(['data' => $candidate]);
    }

    // -------------------------------------------------------------------------

    private function authenticate(): void
    {
        $configuredKey = trim((string) ($_ENV['API_KEY'] ?? $_SERVER['API_KEY'] ?? (string) getenv('API_KEY')));

        if ($configuredKey === '') {
            $this->json(['message' => 'API não configurada. Defina a variável de ambiente API_KEY.'], 503);
        }

        $authHeader = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        $token = '';

        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        }

        if (!hash_equals($configuredKey, $token)) {
            $this->json(['message' => 'Não autorizado.'], 401);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
