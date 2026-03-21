<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use PDO;
use TechRecruit\Database;
use TechRecruit\Models\CandidateModel;
use TechRecruit\Models\OperationsModel;
use TechRecruit\Models\PortalModel;
use TechRecruit\Models\TriageModel;
use TechRecruit\Services\CandidateService;
use Throwable;

final class CandidateController extends Controller
{
    private PDO $pdo;

    private CandidateModel $candidateModel;

    private PortalModel $portalModel;

    private OperationsModel $operationsModel;

    private TriageModel $triageModel;

    private CandidateService $candidateService;

    public function __construct(
        ?CandidateModel $candidateModel = null,
        ?PortalModel $portalModel = null,
        ?OperationsModel $operationsModel = null,
        ?CandidateService $candidateService = null,
        ?PDO $pdo = null
    )
    {
        $this->requireAuth();
        $this->pdo = $pdo ?? Database::connect();
        $this->candidateModel = $candidateModel ?? new CandidateModel($this->pdo);
        $this->portalModel = $portalModel ?? new PortalModel($this->pdo);
        $this->operationsModel = $operationsModel ?? new OperationsModel($this->pdo);
        $this->triageModel = new TriageModel($this->pdo);
        $this->candidateService = $candidateService ?? new CandidateService($this->pdo);
    }

    public function index(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $filters = [
            'skill' => trim((string) ($_GET['skill'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'state' => trim((string) ($_GET['state'] ?? '')),
            'search' => trim((string) ($_GET['search'] ?? '')),
        ];

        $appliedFilters = array_filter(
            $filters,
            static fn (string $value): bool => $value !== ''
        );

        $result = $this->candidateModel->findAll($appliedFilters, $page, 30);
        $totalPages = max(1, (int) ceil($result['total'] / 30));

        $this->render('candidates/index', [
            'candidates' => $result['data'],
            'filters' => $filters,
            'statuses' => CandidateModel::VALID_STATUSES,
            'skills' => $this->fetchDistinctValues('SELECT DISTINCT skill FROM recruit_candidate_skills ORDER BY skill ASC'),
            'states' => $this->fetchDistinctValues('SELECT DISTINCT state FROM recruit_candidate_addresses ORDER BY state ASC'),
            'page' => $result['page'],
            'perPage' => 30,
            'total' => $result['total'],
            'totalPages' => $totalPages,
        ], 'Candidatos');
    }

    public function show(int $id): void
    {
        $candidate = $this->candidateModel->findById($id);

        if ($candidate === null) {
            http_response_code(404);
            echo 'Candidato não encontrado.';

            return;
        }

        $portal = $this->portalModel->findByCandidateId($id);

        $this->render('candidates/show', [
            'candidate' => $candidate,
            'statuses' => CandidateModel::VALID_STATUSES,
            'portal' => $portal,
            'portalStatuses' => PortalModel::VALID_STATUSES,
            'portalUrl' => $portal !== null
                ? $this->absoluteUrl('/portal/' . $portal['access_token'])
                : null,
            'operations' => $this->operationsModel->findByCandidateId($id),
            'triageSession' => $this->triageModel->findLatestSessionByCandidateId($id),
        ], 'Candidato');
    }

    public function updateStatus(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->json([
                'success' => false,
                'message' => 'Método não permitido.',
            ], 405);
        }

        $candidateId = (int) ($_POST['candidate_id'] ?? 0);
        $newStatus = trim((string) ($_POST['new_status'] ?? ''));

        if ($candidateId < 1) {
            $this->json([
                'success' => false,
                'message' => 'O ID do candidato é obrigatório.',
            ], 422);
        }

        if (!in_array($newStatus, CandidateModel::VALID_STATUSES, true)) {
            $this->json([
                'success' => false,
                'message' => 'Status do candidato inválido.',
            ], 422);
        }

        if ($this->candidateModel->findById($candidateId) === null) {
            $this->json([
                'success' => false,
                'message' => 'Candidato não encontrado.',
            ], 404);
        }

        $success = $this->candidateModel->updateStatus($candidateId, $newStatus, $this->resolveOperator());

        $this->json([
            'success' => $success,
            'message' => $success
                ? 'Status atualizado com sucesso.'
                : 'Não foi possível atualizar o status do candidato.',
        ], $success ? 200 : 422);
    }

    public function destroy(int $id): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/candidates/' . $id);
        }

        try {
            $this->candidateService->deleteCandidate($id);
            $this->setFlash('success', 'Candidato excluído com sucesso.');
            $this->redirect('/candidates');
        } catch (Throwable $exception) {
            $this->setFlash(
                'error',
                trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao excluir o candidato.'
            );
            $this->redirect('/candidates/' . $id);
        }
    }

    /**
     * @return list<string>
     */
    private function fetchDistinctValues(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        $values = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        ));
    }
}
