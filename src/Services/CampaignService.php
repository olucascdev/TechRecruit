<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use InvalidArgumentException;
use PDO;
use TechRecruit\Database;
use TechRecruit\Models\CampaignModel;
use TechRecruit\Models\CandidateModel;
use Throwable;

final class CampaignService
{
    private PDO $pdo;

    private CandidateModel $candidateModel;

    private CampaignModel $campaignModel;

    public function __construct(
        ?CandidateModel $candidateModel = null,
        ?CampaignModel $campaignModel = null,
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo ?? Database::connect();
        $this->candidateModel = $candidateModel ?? new CandidateModel($this->pdo);
        $this->campaignModel = $campaignModel ?? new CampaignModel($this->pdo);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createBaseCampaign(array $input, string $operator): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        $messageTemplate = trim((string) ($input['message_template'] ?? ''));
        $filters = $this->normalizeFilters($input);
        $recipientLimit = $this->normalizeRecipientLimit($input['recipient_limit'] ?? null);

        if ($name === '') {
            throw new InvalidArgumentException('Informe um nome para a campanha.');
        }

        if ($messageTemplate === '') {
            throw new InvalidArgumentException('Informe o script base da campanha.');
        }

        $eligibleCandidates = $this->candidateModel->findEligibleForCampaign($filters, $recipientLimit);

        if ($eligibleCandidates === []) {
            throw new InvalidArgumentException('Nenhum candidato elegivel com contato valido foi encontrado para essa segmentacao.');
        }

        $recipients = [];

        foreach ($eligibleCandidates as $candidate) {
            $messageBody = $this->renderMessageTemplate($messageTemplate, (string) $candidate['full_name']);

            $payload = json_encode([
                'template' => $messageTemplate,
                'rendered_message' => $messageBody,
                'candidate_name' => $candidate['full_name'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($payload === false) {
                $payload = null;
            }

            $recipients[] = [
                'candidate_id' => (int) $candidate['id'],
                'candidate_name_snapshot' => (string) $candidate['full_name'],
                'candidate_status_snapshot' => (string) $candidate['status'],
                'destination_contact' => (string) $candidate['destination_contact'],
                'message_body' => $messageBody,
                'payload' => $payload,
            ];
        }

        $encodedFilters = json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->campaignModel->createWithRecipients([
            'name' => $name,
            'status' => 'queued',
            'message_template' => $messageTemplate,
            'segment_filters' => $encodedFilters === false ? '{}' : $encodedFilters,
            'recipient_limit' => $recipientLimit,
            'audience_count' => count($recipients),
            'queued_count' => count($recipients),
            'created_by' => $operator,
        ], $recipients);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{skill?:string,status?:string,state?:string,search?:string}
     */
    public function normalizeFilters(array $input): array
    {
        $filters = [
            'skill' => trim((string) ($input['skill'] ?? '')),
            'status' => trim((string) ($input['status'] ?? '')),
            'state' => trim((string) ($input['state'] ?? '')),
            'search' => trim((string) ($input['search'] ?? '')),
        ];

        return array_filter(
            $filters,
            static fn (string $value): bool => $value !== ''
        );
    }

    private function normalizeRecipientLimit(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $limit = (int) $value;

        if ($limit < 1) {
            throw new InvalidArgumentException('O limite de destinatarios deve ser maior que zero.');
        }

        return min($limit, 5000);
    }

    private function renderMessageTemplate(string $template, string $fullName): string
    {
        $firstName = trim(strtok($fullName, ' ') ?: $fullName);

        return strtr($template, [
            '{full_name}' => $fullName,
            '{first_name}' => $firstName,
        ]);
    }

    /**
     * @return array{processed:int,sent:int,failed:int,opt_out:int,status:string}
     */
    public function processCampaign(int $campaignId, string $operator): array
    {
        $campaign = $this->fetchCampaign($campaignId);

        if ($campaign === null) {
            throw new InvalidArgumentException('Campanha nao encontrada.');
        }

        if ($campaign['status'] === 'paused') {
            throw new InvalidArgumentException('A campanha esta pausada. Retome antes de processar a fila.');
        }

        if ($campaign['status'] === 'cancelled') {
            throw new InvalidArgumentException('A campanha foi cancelada e nao pode mais ser processada.');
        }

        if ($campaign['status'] === 'completed') {
            throw new InvalidArgumentException('A campanha ja foi concluida.');
        }

        $jobsStatement = $this->pdo->prepare(
            "SELECT
                queue.id,
                queue.campaign_recipient_id,
                queue.candidate_id,
                queue.destination_contact,
                queue.message_body,
                queue.attempt_count
             FROM recruit_message_queue queue
             WHERE queue.campaign_id = :campaign_id
               AND queue.status = 'pending'
             ORDER BY queue.scheduled_at ASC, queue.id ASC"
        );
        $jobsStatement->execute(['campaign_id' => $campaignId]);
        $jobs = $jobsStatement->fetchAll();

        if ($jobs === []) {
            $status = $this->refreshCampaignStatus($campaignId, $operator);

            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0,
                'opt_out' => 0,
                'status' => $status,
            ];
        }

        $this->setCampaignStatus($campaignId, 'sending');

        $result = [
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'opt_out' => 0,
        ];

        foreach ($jobs as $job) {
            try {
                $this->pdo->beginTransaction();

                $queueUpdateStatement = $this->pdo->prepare(
                    "UPDATE recruit_message_queue
                     SET status = 'processing',
                         attempt_count = attempt_count + 1,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $queueUpdateStatement->execute(['id' => $job['id']]);

                if ($this->candidateHasOptOut((int) $job['candidate_id'])) {
                    $this->markRecipientAsOptOut(
                        $campaignId,
                        (int) $job['campaign_recipient_id'],
                        (int) $job['candidate_id'],
                        (string) $job['destination_contact'],
                        'Opt-out ja registrado antes do envio.'
                    );

                    $this->pdo->commit();
                    $result['processed']++;
                    $result['opt_out']++;

                    continue;
                }

                $sentStatement = $this->pdo->prepare(
                    "UPDATE recruit_message_queue
                     SET status = 'sent',
                         processed_at = CURRENT_TIMESTAMP,
                         error_message = NULL,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $sentStatement->execute(['id' => $job['id']]);

                $recipientStatement = $this->pdo->prepare(
                    "UPDATE recruit_campaign_recipients
                     SET status = 'sent',
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $recipientStatement->execute(['id' => $job['campaign_recipient_id']]);

                $this->advanceCandidateToMessageSent((int) $job['candidate_id'], $operator);
                $this->logMessageEvent(
                    $campaignId,
                    (int) $job['campaign_recipient_id'],
                    (int) $job['candidate_id'],
                    'outbound',
                    'sent',
                    (string) $job['message_body'],
                    [
                        'destination_contact' => $job['destination_contact'],
                        'attempt_count' => ((int) $job['attempt_count']) + 1,
                    ]
                );

                $this->pdo->commit();

                $result['processed']++;
                $result['sent']++;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                $this->markQueueAsFailed(
                    $campaignId,
                    (int) $job['id'],
                    (int) $job['campaign_recipient_id'],
                    (int) $job['candidate_id'],
                    trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao processar envio.'
                );

                $result['processed']++;
                $result['failed']++;
            }
        }

        $result['status'] = $this->refreshCampaignStatus($campaignId, $operator);

        return $result;
    }

    public function pauseCampaign(int $campaignId): void
    {
        $campaign = $this->fetchCampaign($campaignId);

        if ($campaign === null) {
            throw new InvalidArgumentException('Campanha nao encontrada.');
        }

        if (in_array($campaign['status'], ['cancelled', 'completed'], true)) {
            throw new InvalidArgumentException('Essa campanha nao pode mais ser pausada.');
        }

        if ($campaign['status'] === 'paused') {
            return;
        }

        $this->setCampaignStatus($campaignId, 'paused');
        $this->logMessageEvent($campaignId, null, null, 'system', 'paused', null, []);
    }

    public function resumeCampaign(int $campaignId, string $operator): string
    {
        $campaign = $this->fetchCampaign($campaignId);

        if ($campaign === null) {
            throw new InvalidArgumentException('Campanha nao encontrada.');
        }

        if ($campaign['status'] === 'cancelled') {
            throw new InvalidArgumentException('Campanha cancelada nao pode ser retomada.');
        }

        if ($campaign['status'] === 'completed') {
            throw new InvalidArgumentException('Campanha concluida nao pode ser retomada.');
        }

        if ($campaign['status'] !== 'paused') {
            return (string) $campaign['status'];
        }

        $hasPendingStatement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM recruit_message_queue
             WHERE campaign_id = :campaign_id
               AND status = 'pending'"
        );
        $hasPendingStatement->execute(['campaign_id' => $campaignId]);
        $hasPending = (int) $hasPendingStatement->fetchColumn() > 0;

        $newStatus = $hasPending ? 'queued' : $this->refreshCampaignStatus($campaignId, $operator);

        if ($hasPending) {
            $this->setCampaignStatus($campaignId, $newStatus);
        }

        $this->logMessageEvent($campaignId, null, null, 'system', 'resumed', null, ['status' => $newStatus]);

        return $newStatus;
    }

    public function cancelCampaign(int $campaignId): void
    {
        $campaign = $this->fetchCampaign($campaignId);

        if ($campaign === null) {
            throw new InvalidArgumentException('Campanha nao encontrada.');
        }

        if ($campaign['status'] === 'cancelled') {
            return;
        }

        if ($campaign['status'] === 'completed') {
            throw new InvalidArgumentException('Campanha concluida nao pode ser cancelada.');
        }

        $this->pdo->beginTransaction();

        try {
            $queueStatement = $this->pdo->prepare(
                "UPDATE recruit_message_queue
                 SET status = 'cancelled',
                     processed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE campaign_id = :campaign_id
                   AND status IN ('pending', 'processing')"
            );
            $queueStatement->execute(['campaign_id' => $campaignId]);

            $recipientStatement = $this->pdo->prepare(
                "UPDATE recruit_campaign_recipients
                 SET status = 'skipped',
                     updated_at = CURRENT_TIMESTAMP
                 WHERE campaign_id = :campaign_id
                   AND status = 'queued'"
            );
            $recipientStatement->execute(['campaign_id' => $campaignId]);

            $this->setCampaignStatus($campaignId, 'cancelled');
            $this->logMessageEvent($campaignId, null, null, 'system', 'cancelled', null, []);

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function registerInboundReply(
        int $campaignId,
        int $campaignRecipientId,
        string $messageBody,
        string $operator
    ): string {
        $messageBody = trim($messageBody);

        if ($messageBody === '') {
            throw new InvalidArgumentException('Informe a mensagem recebida para registrar o retorno.');
        }

        $recipientStatement = $this->pdo->prepare(
            "SELECT
                r.id,
                r.candidate_id,
                r.destination_contact,
                c.status AS campaign_status
             FROM recruit_campaign_recipients r
             INNER JOIN recruit_campaigns c ON c.id = r.campaign_id
             WHERE r.id = :recipient_id
               AND r.campaign_id = :campaign_id
             LIMIT 1"
        );
        $recipientStatement->execute([
            'recipient_id' => $campaignRecipientId,
            'campaign_id' => $campaignId,
        ]);
        $recipient = $recipientStatement->fetch();

        if ($recipient === false) {
            throw new InvalidArgumentException('Destinatario da campanha nao encontrado.');
        }

        if ($recipient['campaign_status'] === 'cancelled') {
            throw new InvalidArgumentException('Nao e possivel registrar retorno em campanha cancelada.');
        }

        $intent = $this->resolveInboundIntent($messageBody);

        $this->pdo->beginTransaction();

        try {
            $inboundStatement = $this->pdo->prepare(
                'INSERT INTO recruit_whatsapp_inbound (
                    campaign_id,
                    campaign_recipient_id,
                    candidate_id,
                    source_contact,
                    message_body,
                    parsed_intent
                 ) VALUES (
                    :campaign_id,
                    :campaign_recipient_id,
                    :candidate_id,
                    :source_contact,
                    :message_body,
                    :parsed_intent
                 )'
            );
            $inboundStatement->execute([
                'campaign_id' => $campaignId,
                'campaign_recipient_id' => $campaignRecipientId,
                'candidate_id' => $recipient['candidate_id'],
                'source_contact' => $recipient['destination_contact'],
                'message_body' => $messageBody,
                'parsed_intent' => $intent,
            ]);

            $newRecipientStatus = $intent === 'opt_out' ? 'opt_out' : 'responded';

            $recipientUpdateStatement = $this->pdo->prepare(
                "UPDATE recruit_campaign_recipients
                 SET status = :status,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $recipientUpdateStatement->execute([
                'status' => $newRecipientStatus,
                'id' => $campaignRecipientId,
            ]);

            if ($intent === 'opt_out') {
                $this->insertOptOutIfMissing(
                    (int) $recipient['candidate_id'],
                    (string) $recipient['destination_contact'],
                    $messageBody
                );
            }

            $candidateStatus = match ($intent) {
                'interested' => 'interested',
                'not_interested', 'opt_out' => 'not_interested',
                default => 'responded',
            };

            $this->applyCandidateStatus(
                (int) $recipient['candidate_id'],
                $candidateStatus,
                $operator,
                'Atualizado a partir de retorno WhatsApp.'
            );

            $this->logMessageEvent(
                $campaignId,
                $campaignRecipientId,
                (int) $recipient['candidate_id'],
                'inbound',
                $intent === 'opt_out' ? 'opt_out' : 'reply',
                $messageBody,
                ['intent' => $intent]
            );

            $this->pdo->commit();

            return $intent;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCampaign(int $campaignId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status
             FROM recruit_campaigns
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $campaignId]);
        $campaign = $statement->fetch();

        return $campaign === false ? null : $campaign;
    }

    private function candidateHasOptOut(int $candidateId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM recruit_opt_outs
             WHERE candidate_id = :candidate_id
             LIMIT 1'
        );
        $statement->execute(['candidate_id' => $candidateId]);

        return $statement->fetchColumn() !== false;
    }

    private function markRecipientAsOptOut(
        int $campaignId,
        int $campaignRecipientId,
        int $candidateId,
        string $contactValue,
        string $reason
    ): void {
        $queueStatement = $this->pdo->prepare(
            "UPDATE recruit_message_queue
             SET status = 'cancelled',
                 processed_at = CURRENT_TIMESTAMP,
                 error_message = :reason,
                 updated_at = CURRENT_TIMESTAMP
             WHERE campaign_recipient_id = :campaign_recipient_id
               AND status IN ('pending', 'processing')"
        );
        $queueStatement->execute([
            'reason' => $reason,
            'campaign_recipient_id' => $campaignRecipientId,
        ]);

        $recipientStatement = $this->pdo->prepare(
            "UPDATE recruit_campaign_recipients
             SET status = 'opt_out',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $recipientStatement->execute(['id' => $campaignRecipientId]);

        $this->insertOptOutIfMissing($candidateId, $contactValue, $reason);
        $this->logMessageEvent(
            $campaignId,
            $campaignRecipientId,
            $candidateId,
            'system',
            'opt_out',
            null,
            ['reason' => $reason]
        );
    }

    private function markQueueAsFailed(
        int $campaignId,
        int $queueId,
        int $campaignRecipientId,
        int $candidateId,
        string $errorMessage
    ): void {
        $this->pdo->beginTransaction();

        try {
            $queueStatement = $this->pdo->prepare(
                "UPDATE recruit_message_queue
                 SET status = 'failed',
                     processed_at = CURRENT_TIMESTAMP,
                     error_message = :error_message,
                     attempt_count = attempt_count + 1,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $queueStatement->execute([
                'error_message' => $errorMessage,
                'id' => $queueId,
            ]);

            $recipientStatement = $this->pdo->prepare(
                "UPDATE recruit_campaign_recipients
                 SET status = 'failed',
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $recipientStatement->execute(['id' => $campaignRecipientId]);

            $this->logMessageEvent(
                $campaignId,
                $campaignRecipientId,
                $candidateId,
                'outbound',
                'failed',
                $errorMessage,
                []
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function advanceCandidateToMessageSent(int $candidateId, string $operator): void
    {
        $statement = $this->pdo->prepare(
            'SELECT status
             FROM recruit_candidates
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $candidateId]);
        $status = $statement->fetchColumn();

        if (!is_string($status) || !in_array($status, ['imported', 'queued'], true)) {
            return;
        }

        $this->applyCandidateStatus($candidateId, 'message_sent', $operator, 'Mensagem enviada por campanha WhatsApp.');
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

    private function setCampaignStatus(int $campaignId, string $status): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recruit_campaigns
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'id' => $campaignId,
        ]);
    }

    private function refreshCampaignStatus(int $campaignId, string $operator): string
    {
        $campaign = $this->fetchCampaign($campaignId);

        if ($campaign === null) {
            throw new InvalidArgumentException('Campanha nao encontrada.');
        }

        if ($campaign['status'] === 'paused' || $campaign['status'] === 'cancelled') {
            return (string) $campaign['status'];
        }

        if ($campaign['status'] === 'completed') {
            return 'completed';
        }

        $statement = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_jobs,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_jobs
             FROM recruit_message_queue
             WHERE campaign_id = :campaign_id"
        );
        $statement->execute(['campaign_id' => $campaignId]);
        $stats = $statement->fetch() ?: [];

        $pendingJobs = (int) ($stats['pending_jobs'] ?? 0);
        $processingJobs = (int) ($stats['processing_jobs'] ?? 0);

        if ($processingJobs > 0) {
            $this->setCampaignStatus($campaignId, 'sending');

            return 'sending';
        }

        if ($pendingJobs > 0) {
            $this->setCampaignStatus($campaignId, 'queued');

            return 'queued';
        }

        $this->setCampaignStatus($campaignId, 'completed');
        $this->logMessageEvent($campaignId, null, null, 'system', 'completed', null, ['changed_by' => $operator]);

        return 'completed';
    }

    private function insertOptOutIfMissing(int $candidateId, string $contactValue, string $reason): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_opt_outs (
                candidate_id,
                contact_value,
                source,
                reason
             )
             SELECT
                :candidate_id,
                :contact_value,
                :source,
                :reason
             FROM DUAL
             WHERE NOT EXISTS (
                SELECT 1
                FROM recruit_opt_outs
                WHERE candidate_id = :candidate_id_check
                  AND contact_value = :contact_value_check
             )'
        );
        $statement->execute([
            'candidate_id' => $candidateId,
            'contact_value' => $contactValue,
            'source' => 'campaign_reply',
            'reason' => $reason,
            'candidate_id_check' => $candidateId,
            'contact_value_check' => $contactValue,
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function logMessageEvent(
        int $campaignId,
        ?int $campaignRecipientId,
        ?int $candidateId,
        string $direction,
        string $eventType,
        ?string $messageBody,
        array $metadata
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_message_logs (
                campaign_id,
                campaign_recipient_id,
                candidate_id,
                direction,
                event_type,
                message_body,
                metadata
             ) VALUES (
                :campaign_id,
                :campaign_recipient_id,
                :candidate_id,
                :direction,
                :event_type,
                :message_body,
                :metadata
             )'
        );

        $encodedMetadata = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $statement->execute([
            'campaign_id' => $campaignId,
            'campaign_recipient_id' => $campaignRecipientId,
            'candidate_id' => $candidateId,
            'direction' => $direction,
            'event_type' => $eventType,
            'message_body' => $messageBody,
            'metadata' => $encodedMetadata === false ? null : $encodedMetadata,
        ]);
    }

    private function resolveInboundIntent(string $messageBody): string
    {
        $normalized = mb_strtolower(trim($messageBody));

        if (
            str_contains($normalized, 'sair')
            || str_contains($normalized, 'stop')
            || str_contains($normalized, 'remover')
        ) {
            return 'opt_out';
        }

        if (
            str_contains($normalized, 'nao')
            || str_contains($normalized, 'não')
            || str_contains($normalized, 'sem interesse')
        ) {
            return 'not_interested';
        }

        if (
            str_contains($normalized, 'sim')
            || str_contains($normalized, 'tenho interesse')
            || str_contains($normalized, 'quero')
            || str_contains($normalized, 'interesse')
        ) {
            return 'interested';
        }

        return 'unknown';
    }
}
