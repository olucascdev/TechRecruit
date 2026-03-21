<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use TechRecruit\Models\OperationsModel;
use TechRecruit\Services\OperationsService;
use Throwable;

final class OperationsController extends Controller
{
    private OperationsModel $operationsModel;

    private OperationsService $operationsService;

    public function __construct(
        ?OperationsModel $operationsModel = null,
        ?OperationsService $operationsService = null
    ) {
        $this->operationsModel = $operationsModel ?? new OperationsModel();
        $this->operationsService = $operationsService ?? new OperationsService();
    }

    public function index(): void
    {
        $this->render('operations/index', [
            'queue' => $this->operationsModel->findQueue(),
        ], 'Validação Operacional');
    }

    public function addNote(int $candidateId): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/candidates/' . $candidateId);
        }

        $message = trim((string) ($_POST['message'] ?? ''));

        try {
            $this->operationsService->addInternalNote($candidateId, $message, $this->resolveOperator());
            $this->setFlash('success', 'Observação interna registrada.');
        } catch (Throwable $exception) {
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao registrar observação.');
        }

        $this->redirect('/candidates/' . $candidateId);
    }

    public function candidateDecision(int $candidateId): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/candidates/' . $candidateId);
        }

        $decision = trim((string) ($_POST['decision'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        try {
            $this->operationsService->applyCandidateDecision($candidateId, $decision, $message, $this->resolveOperator());
            $this->setFlash('success', 'Decisão operacional registrada.');
        } catch (Throwable $exception) {
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao registrar decisão.');
        }

        $this->redirect('/candidates/' . $candidateId);
    }

    public function documentDecision(int $documentId): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/operations');
        }

        $candidateId = (int) ($_POST['candidate_id'] ?? 0);
        $decision = trim((string) ($_POST['decision'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        try {
            $this->operationsService->applyDocumentDecision($documentId, $decision, $message, $this->resolveOperator());
            $this->setFlash('success', 'Análise documental registrada.');
        } catch (Throwable $exception) {
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao registrar análise documental.');
        }

        $this->redirect($candidateId > 0 ? '/candidates/' . $candidateId : '/operations');
    }

    public function resolvePendency(int $pendencyId): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/operations');
        }

        $candidateId = (int) ($_POST['candidate_id'] ?? 0);
        $message = trim((string) ($_POST['message'] ?? ''));

        try {
            $this->operationsService->resolvePendency($pendencyId, $this->resolveOperator(), $message);
            $this->setFlash('success', 'Pendência resolvida.');
        } catch (Throwable $exception) {
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao resolver pendência.');
        }

        $this->redirect($candidateId > 0 ? '/candidates/' . $candidateId : '/operations');
    }
}
