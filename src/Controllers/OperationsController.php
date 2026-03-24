<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use TechRecruit\Models\CandidateModel;
use TechRecruit\Models\OperationsModel;
use TechRecruit\Models\PortalModel;
use TechRecruit\Services\OperationsService;
use Throwable;

final class OperationsController extends Controller
{
    private CandidateModel $candidateModel;

    private PortalModel $portalModel;

    private OperationsModel $operationsModel;

    private OperationsService $operationsService;

    public function __construct(
        ?CandidateModel $candidateModel = null,
        ?PortalModel $portalModel = null,
        ?OperationsModel $operationsModel = null,
        ?OperationsService $operationsService = null
    ) {
        $this->requireAuth();
        $this->candidateModel = $candidateModel ?? new CandidateModel();
        $this->portalModel = $portalModel ?? new PortalModel();
        $this->operationsModel = $operationsModel ?? new OperationsModel();
        $this->operationsService = $operationsService ?? new OperationsService();
    }

    public function index(): void
    {
        $this->render('operations/index', [
            'queue' => $this->operationsModel->findQueue(),
        ], 'Validação Operacional');
    }

    public function show(int $candidateId): void
    {
        $candidate = $this->candidateModel->findById($candidateId);

        if ($candidate === null) {
            http_response_code(404);
            echo 'Candidato nao encontrado.';

            return;
        }

        $portal = $this->portalModel->findByCandidateId($candidateId);
        $operations = $this->operationsModel->findByCandidateId($candidateId);

        $this->render('operations/show', [
            'candidate' => $candidate,
            'portal' => $portal,
            'portalUrl' => $portal !== null
                ? $this->absoluteUrl('/portal/' . (string) $portal['access_token'])
                : null,
            'operations' => $operations,
        ], 'Validação Operacional');
    }

    public function addNote(int $candidateId): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/operations/' . $candidateId);
        }

        $message = trim((string) ($_POST['message'] ?? ''));

        try {
            $this->operationsService->addInternalNote($candidateId, $message, $this->resolveOperator());
            $this->setFlash('success', 'Observação interna registrada.');
        } catch (Throwable $exception) {
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao registrar observação.');
        }

        $this->redirect('/operations/' . $candidateId);
    }

    public function candidateDecision(int $candidateId): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/operations/' . $candidateId);
        }

        $decision = trim((string) ($_POST['decision'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        try {
            $this->operationsService->applyCandidateDecision($candidateId, $decision, $message, $this->resolveOperator());
            $this->setFlash('success', 'Decisão operacional registrada.');
        } catch (Throwable $exception) {
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao registrar decisão.');
        }

        $this->redirect('/operations/' . $candidateId);
    }

    public function documentDecisions(int $candidateId): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/operations/' . $candidateId);
        }

        $decisions = is_array($_POST['document_decision'] ?? null) ? $_POST['document_decision'] : [];
        $messages = is_array($_POST['document_message'] ?? null) ? $_POST['document_message'] : [];

        try {
            $result = $this->operationsService->applyDocumentDecisions(
                $candidateId,
                $decisions,
                $messages,
                $this->resolveOperator()
            );

            $this->setFlash(
                'success',
                sprintf(
                    'Analise documental atualizada: %d documento(s) processado(s).',
                    (int) ($result['processed'] ?? 0)
                )
            );
        } catch (Throwable $exception) {
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao registrar análise documental.');
        }

        $this->redirect('/operations/' . $candidateId);
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

        $this->redirect($candidateId > 0 ? '/operations/' . $candidateId : '/operations');
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

        $this->redirect($candidateId > 0 ? '/operations/' . $candidateId : '/operations');
    }
}
