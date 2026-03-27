<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use InvalidArgumentException;
use PDO;
use TechRecruit\Database;
use TechRecruit\Models\CandidateModel;
use TechRecruit\Models\PortalModel;
use Throwable;

final class OperationsService
{
    private PDO $pdo;

    private CandidateModel $candidateModel;

    private PortalModel $portalModel;

    private WhatsGwClient $whatsGwClient;

    private PortalService $portalService;

    public function __construct(
        ?PortalModel $portalModel = null,
        ?CandidateModel $candidateModel = null,
        ?WhatsGwClient $whatsGwClient = null,
        ?PortalService $portalService = null,
        ?PDO $pdo = null
    )
    {
        $this->pdo = $pdo ?? Database::connect();
        $this->candidateModel = $candidateModel ?? new CandidateModel($this->pdo);
        $this->portalModel = $portalModel ?? new PortalModel($this->pdo);
        $this->whatsGwClient = $whatsGwClient ?? new WhatsGwClient();
        $this->portalService = $portalService ?? new PortalService($this->candidateModel, $this->portalModel, $this->whatsGwClient, $this->pdo);
    }

    public function addInternalNote(int $candidateId, string $message, string $operator): void
    {
        $message = trim($message);

        if ($message === '') {
            throw new InvalidArgumentException('Informe a observação interna.');
        }

        $portal = $this->requirePortal($candidateId);

        $this->pdo->beginTransaction();

        try {
            $this->moveToReviewIfNeeded($portal, $operator);
            $this->logHistory($candidateId, (int) $portal['id'], null, 'note', $message, $operator);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function applyCandidateDecision(int $candidateId, string $decision, string $message, string $operator): void
    {
        $message = trim($message);
        $portal = $this->requirePortal($candidateId);
        $notificationType = null;
        $notificationItems = [];

        $this->pdo->beginTransaction();

        try {
            if ($decision === 'approve') {
                $this->assertReadyForApproval((int) $portal['id']);
                $this->setPortalStatus((int) $portal['id'], 'approved');
                $this->applyCandidateStatus($candidateId, 'approved', $operator, 'Cadastro aprovado na validação operacional.');
                $this->resolveAllOpenPendencies((int) $portal['id'], $operator);
                $this->logHistory($candidateId, (int) $portal['id'], null, 'approve', $message, $operator);
                $notificationType = 'approved';
            } elseif ($decision === 'reject') {
                if ($message === '') {
                    throw new InvalidArgumentException('Informe o motivo da reprovação.');
                }

                $this->setPortalStatus((int) $portal['id'], 'rejected');
                $this->applyCandidateStatus($candidateId, 'rejected', $operator, 'Cadastro reprovado na validação operacional.');
                $this->resolveAllOpenPendencies((int) $portal['id'], $operator);
                $this->logHistory($candidateId, (int) $portal['id'], null, 'reject', $message, $operator);
                $notificationType = 'rejected';
            } elseif ($decision === 'request_correction') {
                if ($message === '') {
                    throw new InvalidArgumentException('Descreva a correção solicitada.');
                }

                $this->setPortalStatus((int) $portal['id'], 'correction_requested');
                $this->applyCandidateStatus($candidateId, 'awaiting_docs', $operator, 'Correção solicitada na validação operacional.');
                $this->createPendency($candidateId, (int) $portal['id'], null, 'Correção solicitada', $message, $operator);
                $this->logHistory($candidateId, (int) $portal['id'], null, 'request_correction', $message, $operator);
                $notificationType = 'correction_requested';
                $notificationItems = [$message];
            } else {
                throw new InvalidArgumentException('Decisão inválida.');
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        if (is_string($notificationType)) {
            $this->dispatchCandidateNotification($candidateId, $notificationType, $notificationItems);
        }
    }

    public function applyDocumentDecision(int $documentId, string $decision, string $message, string $operator): void
    {
        $message = trim($message);
        $document = $this->requireDocument($documentId);
        $notification = null;

        $this->pdo->beginTransaction();

        try {
            $portal = [
                'id' => $document['portal_id'],
                'status' => $document['portal_status'],
            ];

            $this->moveToReviewIfNeeded($portal, $operator, (int) $document['candidate_id']);
            $this->applyDocumentDecisionInternal($document, $decision, $message, $operator);
            $notification = $this->syncPortalAfterDocumentReview(
                (int) $document['candidate_id'],
                (int) $document['portal_id'],
                $operator
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        if (is_array($notification)) {
            $this->dispatchCandidateNotification(
                (int) $document['candidate_id'],
                (string) ($notification['type'] ?? ''),
                is_array($notification['items'] ?? null) ? $notification['items'] : []
            );
        }
    }

    /**
     * @param array<string, mixed> $decisions
     * @param array<string, mixed> $messages
     * @return array{processed:int,portal_status:string,candidate_status:string}
     */
    public function applyDocumentDecisions(int $candidateId, array $decisions, array $messages, string $operator): array
    {
        $portal = $this->requirePortal($candidateId);
        $documents = $this->findPortalDocuments((int) $portal['id']);

        if ($documents === []) {
            throw new InvalidArgumentException('Nenhum documento encontrado para analise.');
        }

        $processed = 0;
        $notification = null;

        $this->pdo->beginTransaction();

        try {
            $this->moveToReviewIfNeeded($portal, $operator, $candidateId);

            foreach ($documents as $document) {
                $documentId = (int) ($document['id'] ?? 0);

                if ($documentId < 1) {
                    continue;
                }

                $decision = trim((string) ($decisions[(string) $documentId] ?? ''));

                if ($decision === '') {
                    continue;
                }

                $message = trim((string) ($messages[(string) $documentId] ?? ''));
                $this->applyDocumentDecisionInternal($document, $decision, $message, $operator);
                $processed++;
            }

            if ($processed < 1) {
                throw new InvalidArgumentException('Selecione pelo menos um documento para salvar.');
            }

            $notification = $this->syncPortalAfterDocumentReview(
                $candidateId,
                (int) $portal['id'],
                $operator
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        if (is_array($notification)) {
            $this->dispatchCandidateNotification(
                $candidateId,
                (string) ($notification['type'] ?? ''),
                is_array($notification['items'] ?? null) ? $notification['items'] : []
            );
        }

        return [
            'processed' => $processed,
            'portal_status' => (string) ($notification['portal_status'] ?? (string) ($portal['status'] ?? 'under_review')),
            'candidate_status' => (string) ($notification['candidate_status'] ?? 'under_review'),
        ];
    }

    /**
     * @param array<string, mixed> $document
     */
    private function applyDocumentDecisionInternal(array $document, string $decision, string $message, string $operator): void
    {
        $decision = trim($decision);
        $documentId = (int) ($document['id'] ?? 0);

        if ($documentId < 1) {
            throw new InvalidArgumentException('Documento inválido para análise.');
        }

        if ($decision === 'approve') {
            $this->updateDocumentReviewStatus($documentId, 'approved');
            $this->resolveOpenPendenciesForDocument($documentId, $operator);
            $this->logHistory(
                (int) $document['candidate_id'],
                (int) $document['portal_id'],
                $documentId,
                'document_approve',
                $message,
                $operator
            );

            return;
        }

        if (!in_array($decision, ['reject', 'request_correction'], true)) {
            throw new InvalidArgumentException('Decisão de documento inválida.');
        }

        if ($message === '') {
            throw new InvalidArgumentException('Descreva a pendência do documento.');
        }

        $status = $decision === 'reject' ? 'rejected' : 'correction_requested';
        $action = $decision === 'reject' ? 'document_reject' : 'document_request_correction';
        $titlePrefix = $decision === 'reject' ? 'Documento reprovado' : 'Correção documental';

        $this->updateDocumentReviewStatus($documentId, $status);
        $this->createPendency(
            (int) $document['candidate_id'],
            (int) $document['portal_id'],
            $documentId,
            $titlePrefix . ': ' . (string) $document['document_type'],
            $message,
            $operator
        );
        $this->logHistory(
            (int) $document['candidate_id'],
            (int) $document['portal_id'],
            $documentId,
            $action,
            $message,
            $operator
        );
    }

    /**
     * @return array{portal_status:string,candidate_status:string,type:string|null,items:list<string>}
     */
    private function syncPortalAfterDocumentReview(int $candidateId, int $portalId, string $operator): array
    {
        $portal = $this->portalModel->findById($portalId);

        if ($portal === null) {
            return [
                'portal_status' => 'under_review',
                'candidate_status' => 'under_review',
                'type' => null,
                'items' => [],
            ];
        }

        $previousPortalStatus = (string) ($portal['status'] ?? 'under_review');
        $previousCandidateStatus = (string) ($portal['candidate_status'] ?? 'under_review');

        $hasBlockedDocuments = $this->hasBlockedDocuments($portalId);
        $openPendencies = $this->countOpenPendencies($portalId);
        $requiredApproved = $this->allRequiredDocumentsApproved($portalId);

        $targetPortalStatus = 'under_review';
        $targetCandidateStatus = 'under_review';
        $notificationType = null;
        $notificationItems = [];

        if ($hasBlockedDocuments || $openPendencies > 0) {
            $targetPortalStatus = 'correction_requested';
            $targetCandidateStatus = 'awaiting_docs';
            $notificationItems = $this->collectCorrectionItems($portalId);

            if ($previousPortalStatus !== 'correction_requested') {
                $notificationType = 'correction_requested';
            }
        } elseif ($requiredApproved) {
            $targetPortalStatus = 'approved';
            $targetCandidateStatus = 'approved';
            $this->resolveAllOpenPendencies($portalId, $operator);

            if ($previousPortalStatus !== 'approved') {
                $notificationType = 'approved';
            }
        }

        if ($previousPortalStatus !== $targetPortalStatus) {
            $this->setPortalStatus($portalId, $targetPortalStatus);
        }

        $this->applyCandidateStatus(
            $candidateId,
            $targetCandidateStatus,
            $operator,
            sprintf('Sync operacional automatico para %s.', $targetPortalStatus)
        );

        if ($previousPortalStatus !== $targetPortalStatus || $previousCandidateStatus !== $targetCandidateStatus) {
            $this->logHistory(
                $candidateId,
                $portalId,
                null,
                'status_sync',
                sprintf('Status sincronizado automaticamente: portal=%s, candidato=%s.', $targetPortalStatus, $targetCandidateStatus),
                $operator
            );
        }

        return [
            'portal_status' => $targetPortalStatus,
            'candidate_status' => $targetCandidateStatus,
            'type' => $notificationType,
            'items' => $notificationItems,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findPortalDocuments(int $portalId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                document.id,
                document.portal_id,
                document.candidate_id,
                document.document_type,
                document.review_status,
                portal.status AS portal_status
             FROM recruit_candidate_documents document
             INNER JOIN recruit_candidate_portals portal ON portal.id = document.portal_id
             WHERE document.portal_id = :portal_id
             ORDER BY document.uploaded_at DESC, document.id DESC'
        );
        $statement->execute(['portal_id' => $portalId]);

        return $statement->fetchAll();
    }

    private function hasBlockedDocuments(int $portalId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM recruit_candidate_documents
             WHERE portal_id = :portal_id
               AND review_status IN ('rejected', 'correction_requested')"
        );
        $statement->execute(['portal_id' => $portalId]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function allRequiredDocumentsApproved(int $portalId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT document_type, review_status
             FROM recruit_candidate_documents
             WHERE portal_id = :portal_id"
        );
        $statement->execute(['portal_id' => $portalId]);
        $documents = $statement->fetchAll();

        if ($documents === []) {
            return false;
        }

        $approvedByType = [];

        foreach ($documents as $document) {
            if ((string) ($document['review_status'] ?? '') !== 'approved') {
                continue;
            }

            $approvedByType[(string) ($document['document_type'] ?? '')] = true;
        }

        foreach (PortalModel::CHECKLIST_ITEMS as $type => $item) {
            if (!($item['required'] ?? false)) {
                continue;
            }

            if (!isset($approvedByType[$type])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function collectCorrectionItems(int $portalId): array
    {
        $labels = [];

        $documentsStatement = $this->pdo->prepare(
            "SELECT DISTINCT document_type
             FROM recruit_candidate_documents
             WHERE portal_id = :portal_id
               AND review_status IN ('rejected', 'correction_requested')"
        );
        $documentsStatement->execute(['portal_id' => $portalId]);

        foreach ($documentsStatement->fetchAll(PDO::FETCH_COLUMN) as $type) {
            $documentType = (string) $type;
            $labels[] = (string) (PortalModel::CHECKLIST_ITEMS[$documentType]['label'] ?? $documentType);
        }

        $pendencyStatement = $this->pdo->prepare(
            "SELECT title
             FROM recruit_review_pendencies
             WHERE portal_id = :portal_id
               AND status = 'open'
             ORDER BY created_at DESC"
        );
        $pendencyStatement->execute(['portal_id' => $portalId]);

        foreach ($pendencyStatement->fetchAll(PDO::FETCH_COLUMN) as $title) {
            $title = trim((string) $title);

            if ($title !== '') {
                $labels[] = $title;
            }
        }

        return array_values(array_unique(array_filter($labels, static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param list<string> $items
     */
    private function dispatchCandidateNotification(int $candidateId, string $type, array $items = []): void
    {
        if (!in_array($type, ['approved', 'correction_requested', 'rejected'], true)) {
            return;
        }

        $candidate = $this->candidateModel->findById($candidateId);

        if ($candidate === null) {
            return;
        }

        $destinationContact = $this->resolveCandidateDestinationContact($candidate);

        if ($destinationContact === null) {
            return;
        }

        $fullName = (string) ($candidate['full_name'] ?? 'Tecnico');
        $messageBody = '';

        if ($type === 'approved') {
            $messageBody = $this->buildApprovedNotificationMessage($fullName);
        } elseif ($type === 'rejected') {
            $messageBody = $this->buildRejectedNotificationMessage($fullName);
        } elseif ($type === 'correction_requested') {
            $portal = $this->portalModel->findByCandidateId($candidateId);

            if ($portal === null) {
                return;
            }

            $portalUrl = $this->portalService->buildPortalShortUrl($portal, true);
            $messageBody = $this->portalService->buildPortalCorrectionMessage($fullName, $portalUrl, $items);
        }

        if (trim($messageBody) === '') {
            return;
        }

        try {
            $this->whatsGwClient->sendTextMessage($destinationContact, $messageBody, [
                'message_custom_id' => sprintf('operations-%s-%d-%d', $type, $candidateId, time()),
                'check_status' => 1,
            ]);
        } catch (Throwable $exception) {
            error_log('[OperationsService] Falha ao enviar notificacao WhatsApp: ' . $exception->getMessage());
        }
    }

    private function buildApprovedNotificationMessage(string $fullName): string
    {
        $firstName = trim((string) strtok(trim($fullName), ' '));

        if ($firstName === '') {
            $firstName = 'Tecnico';
        }

        return trim(
            "Olá, {$firstName}.\n\n" .
            "Seus documentos foram aprovados pela equipe da W13.\n" .
            "Seu cadastro avançou para a próxima etapa operacional.\n\n" .
            "Equipe W13"
        );
    }

    private function buildRejectedNotificationMessage(string $fullName): string
    {
        $firstName = trim((string) strtok(trim($fullName), ' '));

        if ($firstName === '') {
            $firstName = 'Tecnico';
        }

        return trim(
            "Olá, {$firstName}.\n\n" .
            "No momento seu cadastro foi reprovado na validação operacional da W13.\n" .
            "Se houver nova oportunidade ou necessidade de reavaliação, entraremos em contato.\n\n" .
            "Equipe W13"
        );
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function resolveCandidateDestinationContact(array $candidate): ?string
    {
        $contacts = is_array($candidate['contacts'] ?? null) ? $candidate['contacts'] : [];

        foreach (['whatsapp', 'phone'] as $preferredType) {
            foreach ($contacts as $contact) {
                if (($contact['type'] ?? null) !== $preferredType) {
                    continue;
                }

                $digits = preg_replace('/\D+/', '', (string) ($contact['value'] ?? ''));

                if (is_string($digits) && $digits !== '') {
                    return $digits;
                }
            }
        }

        return null;
    }

    public function resolvePendency(int $pendencyId, string $operator, ?string $message = null): void
    {
        $statement = $this->pdo->prepare(
            'SELECT id, candidate_id, portal_id, document_id, status
             FROM recruit_review_pendencies
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $pendencyId]);
        $pendency = $statement->fetch();

        if ($pendency === false) {
            throw new InvalidArgumentException('Pendência não encontrada.');
        }

        if ($pendency['status'] === 'resolved') {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $updateStatement = $this->pdo->prepare(
                "UPDATE recruit_review_pendencies
                 SET status = 'resolved',
                     resolved_by = :resolved_by,
                     resolved_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $updateStatement->execute([
                'resolved_by' => $operator,
                'id' => $pendencyId,
            ]);

            $this->logHistory(
                (int) $pendency['candidate_id'],
                (int) $pendency['portal_id'],
                $pendency['document_id'] !== null ? (int) $pendency['document_id'] : null,
                'pendency_resolved',
                trim((string) $message) !== '' ? trim((string) $message) : 'Pendência resolvida.',
                $operator
            );

            $openCount = $this->countOpenPendencies((int) $pendency['portal_id']);

            if ($openCount === 0) {
                $this->setPortalStatus((int) $pendency['portal_id'], 'under_review');
                $this->applyCandidateStatus(
                    (int) $pendency['candidate_id'],
                    'under_review',
                    $operator,
                    'Todas as pendências operacionais foram resolvidas.'
                );
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePortal(int $candidateId): array
    {
        $portal = $this->portalModel->findByCandidateId($candidateId);

        if ($portal === null) {
            throw new InvalidArgumentException('Portal do candidato não encontrado.');
        }

        return $portal;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireDocument(int $documentId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                document.id,
                document.portal_id,
                document.candidate_id,
                document.document_type,
                document.review_status,
                portal.status AS portal_status
             FROM recruit_candidate_documents document
             INNER JOIN recruit_candidate_portals portal ON portal.id = document.portal_id
             WHERE document.id = :id
             LIMIT 1"
        );
        $statement->execute(['id' => $documentId]);
        $document = $statement->fetch();

        if ($document === false) {
            throw new InvalidArgumentException('Documento não encontrado.');
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $portal
     */
    private function moveToReviewIfNeeded(array $portal, string $operator, ?int $candidateId = null): void
    {
        $candidateId ??= (int) ($portal['candidate_id'] ?? 0);

        if (in_array((string) $portal['status'], ['submitted', 'correction_requested'], true)) {
            $this->setPortalStatus((int) $portal['id'], 'under_review');

            if ($candidateId > 0) {
                $this->applyCandidateStatus(
                    $candidateId,
                    'under_review',
                    $operator,
                    'Análise operacional iniciada.'
                );
            }

            $this->logHistory($candidateId, (int) $portal['id'], null, 'status_sync', 'Portal movido para under_review.', $operator);
        }
    }

    private function assertReadyForApproval(int $portalId): void
    {
        if ($this->countOpenPendencies($portalId) > 0) {
            throw new InvalidArgumentException('Existem pendências abertas. Resolva antes de aprovar.');
        }

        $statement = $this->pdo->prepare(
            "SELECT document_type, review_status
             FROM recruit_candidate_documents
             WHERE portal_id = :portal_id"
        );
        $statement->execute(['portal_id' => $portalId]);
        $documents = $statement->fetchAll();

        $approvedByType = [];

        foreach ($documents as $document) {
            if ((string) $document['review_status'] !== 'approved') {
                continue;
            }

            $approvedByType[(string) $document['document_type']] = true;
        }

        foreach (PortalModel::CHECKLIST_ITEMS as $type => $item) {
            if (!$item['required']) {
                continue;
            }

            if (!isset($approvedByType[$type])) {
                throw new InvalidArgumentException(
                    sprintf('Aprovação bloqueada: o documento obrigatório "%s" ainda não foi aprovado.', $item['label'])
                );
            }
        }
    }

    private function createPendency(
        int $candidateId,
        int $portalId,
        ?int $documentId,
        string $title,
        string $description,
        string $operator
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_review_pendencies (
                candidate_id,
                portal_id,
                document_id,
                title,
                description,
                status,
                created_by
             ) VALUES (
                :candidate_id,
                :portal_id,
                :document_id,
                :title,
                :description,
                :status,
                :created_by
             )'
        );
        $statement->execute([
            'candidate_id' => $candidateId,
            'portal_id' => $portalId,
            'document_id' => $documentId,
            'title' => $title,
            'description' => $description,
            'status' => 'open',
            'created_by' => $operator,
        ]);
    }

    private function updateDocumentReviewStatus(int $documentId, string $status): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recruit_candidate_documents
             SET review_status = :status
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'id' => $documentId,
        ]);
    }

    private function resolveOpenPendenciesForDocument(int $documentId, string $operator): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE recruit_review_pendencies
             SET status = 'resolved',
                 resolved_by = :resolved_by,
                 resolved_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE document_id = :document_id
               AND status = 'open'"
        );
        $statement->execute([
            'resolved_by' => $operator,
            'document_id' => $documentId,
        ]);
    }

    private function resolveAllOpenPendencies(int $portalId, string $operator): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE recruit_review_pendencies
             SET status = 'resolved',
                 resolved_by = :resolved_by,
                 resolved_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE portal_id = :portal_id
               AND status = 'open'"
        );
        $statement->execute([
            'resolved_by' => $operator,
            'portal_id' => $portalId,
        ]);
    }

    private function countOpenPendencies(int $portalId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM recruit_review_pendencies
             WHERE portal_id = :portal_id
               AND status = 'open'"
        );
        $statement->execute(['portal_id' => $portalId]);

        return (int) $statement->fetchColumn();
    }

    private function setPortalStatus(int $portalId, string $status): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recruit_candidate_portals
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'id' => $portalId,
        ]);
    }

    private function applyCandidateStatus(int $candidateId, string $newStatus, string $operator, ?string $reason): void
    {
        if (!in_array($newStatus, CandidateModel::VALID_STATUSES, true)) {
            return;
        }

        $currentStatusStatement = $this->pdo->prepare(
            'SELECT status
             FROM recruit_candidates
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $currentStatusStatement->execute(['id' => $candidateId]);
        $currentStatus = $currentStatusStatement->fetchColumn();

        if ($currentStatus === false || $currentStatus === $newStatus) {
            return;
        }

        $updateStatement = $this->pdo->prepare(
            'UPDATE recruit_candidates
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $updateStatement->execute([
            'status' => $newStatus,
            'id' => $candidateId,
        ]);

        $historyStatement = $this->pdo->prepare(
            'INSERT INTO recruit_candidate_status_history (
                candidate_id,
                from_status,
                to_status,
                changed_by,
                reason
             ) VALUES (
                :candidate_id,
                :from_status,
                :to_status,
                :changed_by,
                :reason
             )'
        );
        $historyStatement->execute([
            'candidate_id' => $candidateId,
            'from_status' => $currentStatus,
            'to_status' => $newStatus,
            'changed_by' => $operator,
            'reason' => $reason,
        ]);
    }

    private function logHistory(
        int $candidateId,
        int $portalId,
        ?int $documentId,
        string $action,
        ?string $message,
        string $operator
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_review_history (
                candidate_id,
                portal_id,
                document_id,
                action,
                message,
                created_by
             ) VALUES (
                :candidate_id,
                :portal_id,
                :document_id,
                :action,
                :message,
                :created_by
             )'
        );
        $statement->execute([
            'candidate_id' => $candidateId,
            'portal_id' => $portalId,
            'document_id' => $documentId,
            'action' => $action,
            'message' => $message,
            'created_by' => $operator,
        ]);
    }
}
