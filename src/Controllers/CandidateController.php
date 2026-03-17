<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use PDO;
use TechRecruit\Database;
use TechRecruit\Models\CandidateModel;

final class CandidateController extends Controller
{
    private PDO $pdo;

    private CandidateModel $candidateModel;

    public function __construct(?CandidateModel $candidateModel = null, ?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
        $this->candidateModel = $candidateModel ?? new CandidateModel($this->pdo);
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
            echo 'Candidate not found.';

            return;
        }

        $this->render('candidates/show', [
            'candidate' => $candidate,
            'statuses' => CandidateModel::VALID_STATUSES,
        ], 'Candidato');
    }

    public function updateStatus(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->json([
                'success' => false,
                'message' => 'Method not allowed.',
            ], 405);
        }

        $candidateId = (int) ($_POST['candidate_id'] ?? 0);
        $newStatus = trim((string) ($_POST['new_status'] ?? ''));

        if ($candidateId < 1) {
            $this->json([
                'success' => false,
                'message' => 'Candidate ID is required.',
            ], 422);
        }

        if (!in_array($newStatus, CandidateModel::VALID_STATUSES, true)) {
            $this->json([
                'success' => false,
                'message' => 'Invalid candidate status.',
            ], 422);
        }

        if ($this->candidateModel->findById($candidateId) === null) {
            $this->json([
                'success' => false,
                'message' => 'Candidate not found.',
            ], 404);
        }

        $success = $this->candidateModel->updateStatus($candidateId, $newStatus, $this->resolveOperator());

        $this->json([
            'success' => $success,
            'message' => $success
                ? 'Status updated successfully.'
                : 'Could not update candidate status.',
        ], $success ? 200 : 422);
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
