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
    private const DEFAULT_BATCH_SIZE = 25;

    private const MAX_BATCH_SIZE = 500;

    private const DEFAULT_MAX_ATTEMPTS = 5;

    private const DEFAULT_STALE_PROCESSING_MINUTES = 10;

    private PDO $pdo;

    private CandidateModel $candidateModel;

    private CampaignModel $campaignModel;

    private TriageBotService $triageBotService;

    private WhatsGwClient $whatsGwClient;

    public function __construct(
        ?CandidateModel $candidateModel = null,
        ?CampaignModel $campaignModel = null,
        ?TriageBotService $triageBotService = null,
        ?WhatsGwClient $whatsGwClient = null,
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo ?? Database::connect();
        $this->candidateModel = $candidateModel ?? new CandidateModel($this->pdo);
        $this->campaignModel = $campaignModel ?? new CampaignModel($this->pdo);
        $this->triageBotService = $triageBotService ?? new TriageBotService(null, $this->pdo);
        $this->whatsGwClient = $whatsGwClient ?? new WhatsGwClient();
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
        $automationType = $this->normalizeAutomationType($input['automation_type'] ?? null);

        if ($name === '') {
            throw new InvalidArgumentException('Informe um nome para a campanha.');
        }

        if ($messageTemplate === '') {
            throw new InvalidArgumentException('Informe o script base da campanha.');
        }

        $eligibleCandidates = $this->candidateModel->findEligibleForCampaign($filters, $recipientLimit);

        if ($eligibleCandidates === []) {
            throw new InvalidArgumentException('Nenhum candidato elegível com contato válido foi encontrado para essa segmentação.');
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
            'automation_type' => $automationType,
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

    public function normalizeAutomationType(mixed $value): string
    {
        $automationType = trim((string) $value);

        if ($automationType === '') {
            return TriageBotService::AUTOMATION_TYPE;
        }

        if (!in_array($automationType, ['broadcast', TriageBotService::AUTOMATION_TYPE], true)) {
            throw new InvalidArgumentException('Tipo de automação inválido.');
        }

        return $automationType;
    }

    private function normalizeRecipientLimit(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $limit = (int) $value;

        if ($limit < 1) {
            throw new InvalidArgumentException('O limite de destinatários deve ser maior que zero.');
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
    public function processCampaign(int $campaignId, string $operator, ?int $limit = null): array
    {
        $campaign = $this->fetchCampaign($campaignId);
        $batchSize = $this->resolveBatchSize($limit);
        $maxAttempts = $this->resolveMaxAttempts();

        if ($campaign === null) {
            throw new InvalidArgumentException('Campanha não encontrada.');
        }

        if ($campaign['status'] === 'paused') {
            throw new InvalidArgumentException('A campanha está pausada. Retome antes de processar a fila.');
        }

        if ($campaign['status'] === 'cancelled') {
            throw new InvalidArgumentException('A campanha foi cancelada e não pode mais ser processada.');
        }

        if ($campaign['status'] === 'completed') {
            throw new InvalidArgumentException('A campanha já foi concluída.');
        }

        $this->releaseStaleProcessingJobs($campaignId);

        $jobsStatement = $this->pdo->prepare(
            sprintf(
                "SELECT
                queue.id,
                queue.campaign_recipient_id,
                queue.candidate_id,
                queue.destination_contact,
                queue.message_body,
                queue.attempt_count
             FROM recruit_message_queue queue
             WHERE queue.campaign_id = :campaign_id
               AND queue.status IN ('pending', 'failed')
               AND queue.scheduled_at <= CURRENT_TIMESTAMP
               AND (queue.status = 'pending' OR queue.attempt_count < :max_attempts)
             ORDER BY
                CASE WHEN queue.status = 'pending' THEN 0 ELSE 1 END,
                queue.scheduled_at ASC,
                queue.id ASC
             LIMIT %d",
                $batchSize
            )
        );
        $jobsStatement->execute([
            'campaign_id' => $campaignId,
            'max_attempts' => $maxAttempts,
        ]);
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
            $queueId = (int) $job['id'];
            $campaignRecipientId = (int) $job['campaign_recipient_id'];
            $candidateId = (int) $job['candidate_id'];
            $destinationContact = (string) $job['destination_contact'];
            $messageBody = (string) $job['message_body'];
            $attemptCount = ((int) $job['attempt_count']) + 1;
            $messageCustomId = $this->buildQueueMessageCustomId($queueId);

            try {
                $this->pdo->beginTransaction();

                $claimStatement = $this->pdo->prepare(
                    "UPDATE recruit_message_queue
                     SET status = 'processing',
                         attempt_count = attempt_count + 1,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id
                       AND status IN ('pending', 'failed')
                       AND scheduled_at <= CURRENT_TIMESTAMP
                       AND (status = 'pending' OR attempt_count < :max_attempts)"
                );
                $claimStatement->execute([
                    'id' => $queueId,
                    'max_attempts' => $maxAttempts,
                ]);

                if ($claimStatement->rowCount() !== 1) {
                    $this->pdo->commit();

                    continue;
                }

                if ($this->candidateHasOptOut($candidateId)) {
                    $this->markRecipientAsOptOut(
                        $campaignId,
                        $campaignRecipientId,
                        $candidateId,
                        $destinationContact,
                        'Opt-out já registrado antes do envio.'
                    );

                    $this->pdo->commit();
                    $result['processed']++;
                    $result['opt_out']++;

                    continue;
                }

                $this->pdo->commit();

                $providerResult = $this->sendOutboundMessage(
                    $destinationContact,
                    $messageBody,
                    $messageCustomId
                );

                $this->pdo->beginTransaction();
                $this->markQueueAsSent(
                    $campaignId,
                    $queueId,
                    $campaignRecipientId,
                    $candidateId,
                    $destinationContact,
                    $messageBody,
                    $attemptCount,
                    $messageCustomId,
                    $providerResult,
                    $operator,
                    (string) ($campaign['automation_type'] ?? 'broadcast')
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
                    $queueId,
                    $campaignRecipientId,
                    $candidateId,
                    $attemptCount,
                    trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao processar envio.'
                );

                $result['processed']++;
                $result['failed']++;
            }
        }

        $result['status'] = $this->refreshCampaignStatus($campaignId, $operator);

        return $result;
    }

    /**
     * @return array{campaigns:int,processed:int,sent:int,failed:int,opt_out:int}
     */
    public function processDueQueue(string $operator, ?int $limit = null): array
    {
        $remaining = $this->resolveBatchSize($limit);
        $campaignIds = $this->findCampaignIdsWithDueQueue($remaining);
        $summary = [
            'campaigns' => 0,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'opt_out' => 0,
        ];

        foreach ($campaignIds as $campaignId) {
            if ($remaining <= 0) {
                break;
            }

            $result = $this->processCampaign($campaignId, $operator, $remaining);

            if (($result['processed'] ?? 0) < 1) {
                continue;
            }

            $summary['campaigns']++;
            $summary['processed'] += (int) $result['processed'];
            $summary['sent'] += (int) $result['sent'];
            $summary['failed'] += (int) $result['failed'];
            $summary['opt_out'] += (int) $result['opt_out'];
            $remaining -= (int) $result['processed'];
        }

        return $summary;
    }

    public function pauseCampaign(int $campaignId): void
    {
        $campaign = $this->fetchCampaign($campaignId);

        if ($campaign === null) {
            throw new InvalidArgumentException('Campanha não encontrada.');
        }

        if (in_array($campaign['status'], ['cancelled', 'completed'], true)) {
            throw new InvalidArgumentException('Essa campanha não pode mais ser pausada.');
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
            throw new InvalidArgumentException('Campanha não encontrada.');
        }

        if ($campaign['status'] === 'cancelled') {
            throw new InvalidArgumentException('Campanha cancelada não pode ser retomada.');
        }

        if ($campaign['status'] === 'completed') {
            throw new InvalidArgumentException('Campanha concluída não pode ser retomada.');
        }

        if ($campaign['status'] !== 'paused') {
            return (string) $campaign['status'];
        }

        $hasPending = $this->hasOpenQueueJobs($campaignId);

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
            throw new InvalidArgumentException('Campanha não encontrada.');
        }

        if ($campaign['status'] === 'cancelled') {
            return;
        }

        if ($campaign['status'] === 'completed') {
            throw new InvalidArgumentException('Campanha concluída não pode ser cancelada.');
        }

        $this->pdo->beginTransaction();

        try {
            $queueStatement = $this->pdo->prepare(
                "UPDATE recruit_message_queue
                 SET status = 'cancelled',
                     processed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE campaign_id = :campaign_id
                   AND (
                        status IN ('pending', 'processing')
                        OR (status = 'failed' AND attempt_count < :max_attempts)
                   )"
            );
            $queueStatement->execute([
                'campaign_id' => $campaignId,
                'max_attempts' => $this->resolveMaxAttempts(),
            ]);

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

    /**
     * @return array<string, mixed>
     */
    public function registerInboundReply(
        int $campaignId,
        int $campaignRecipientId,
        string $messageBody,
        string $operator,
        array $providerMetadata = []
    ): array {
        $messageBody = trim($messageBody);

        if ($messageBody === '') {
            throw new InvalidArgumentException('Informe a mensagem recebida para registrar o retorno.');
        }

        $recipientStatement = $this->pdo->prepare(
            "SELECT
                r.id,
                r.candidate_id,
                r.destination_contact,
                c.status AS campaign_status,
                c.automation_type
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
            throw new InvalidArgumentException('Destinatário da campanha não encontrado.');
        }

        if ($recipient['campaign_status'] === 'cancelled') {
            throw new InvalidArgumentException('Não é possível registrar retorno em campanha cancelada.');
        }

        $this->pdo->beginTransaction();

        try {
            $botResult = $this->triageBotService->isTriageAutomationType((string) ($recipient['automation_type'] ?? 'broadcast'))
                ? $this->triageBotService->handleInbound(
                    $campaignId,
                    $campaignRecipientId,
                    (int) $recipient['candidate_id'],
                    $messageBody,
                    $operator
                )
                : [
                    'parsed_intent' => $this->resolveInboundIntent($messageBody),
                    'triage_status' => null,
                    'current_step' => null,
                    'automation_status' => null,
                    'needs_operator' => false,
                    'candidate_status' => null,
                    'auto_reply' => null,
                    'metadata' => [],
                ];

            $intent = (string) ($botResult['parsed_intent'] ?? 'unknown');

            $inboundStatement = $this->pdo->prepare(
                'INSERT INTO recruit_whatsapp_inbound (
                    campaign_id,
                    campaign_recipient_id,
                    candidate_id,
                    source_contact,
                    contact_name,
                    chat_type,
                    provider_message_id,
                    provider_waid,
                    group_id,
                    message_type,
                    message_state,
                    context_type,
                    context_waid,
                    received_unix_time,
                    provider_payload,
                    message_body,
                    parsed_intent
                 ) VALUES (
                    :campaign_id,
                    :campaign_recipient_id,
                    :candidate_id,
                    :source_contact,
                    :contact_name,
                    :chat_type,
                    :provider_message_id,
                    :provider_waid,
                    :group_id,
                    :message_type,
                    :message_state,
                    :context_type,
                    :context_waid,
                    :received_unix_time,
                    :provider_payload,
                    :message_body,
                    :parsed_intent
                 )'
            );
            $encodedProviderPayload = json_encode(
                $providerMetadata['provider_payload'] ?? $providerMetadata,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $inboundStatement->execute([
                'campaign_id' => $campaignId,
                'campaign_recipient_id' => $campaignRecipientId,
                'candidate_id' => $recipient['candidate_id'],
                'source_contact' => $recipient['destination_contact'],
                'contact_name' => $providerMetadata['contact_name'] ?? null,
                'chat_type' => $providerMetadata['chat_type'] ?? null,
                'provider_message_id' => isset($providerMetadata['provider_message_id']) ? (string) $providerMetadata['provider_message_id'] : null,
                'provider_waid' => isset($providerMetadata['provider_waid']) ? (string) $providerMetadata['provider_waid'] : null,
                'group_id' => isset($providerMetadata['group_id']) ? (string) $providerMetadata['group_id'] : null,
                'message_type' => isset($providerMetadata['message_type']) ? (string) $providerMetadata['message_type'] : null,
                'message_state' => isset($providerMetadata['message_state']) ? (string) $providerMetadata['message_state'] : null,
                'context_type' => isset($providerMetadata['context_type']) && $providerMetadata['context_type'] !== ''
                    ? (int) $providerMetadata['context_type']
                    : null,
                'context_waid' => isset($providerMetadata['context_waid']) ? (string) $providerMetadata['context_waid'] : null,
                'received_unix_time' => isset($providerMetadata['received_unix_time']) && $providerMetadata['received_unix_time'] !== ''
                    ? (int) $providerMetadata['received_unix_time']
                    : null,
                'provider_payload' => $encodedProviderPayload === false ? null : $encodedProviderPayload,
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

            $candidateStatus = $botResult['candidate_status'] ?? match ($intent) {
                'interested' => 'interested',
                'not_interested', 'opt_out' => 'not_interested',
                default => 'responded',
            };

            if (is_string($candidateStatus) && $candidateStatus !== '') {
                $this->applyCandidateStatus(
                    (int) $recipient['candidate_id'],
                    $candidateStatus,
                    $operator,
                    'Atualizado a partir de retorno WhatsApp.'
                );
            }

            $this->logMessageEvent(
                $campaignId,
                $campaignRecipientId,
                (int) $recipient['candidate_id'],
                'inbound',
                $intent === 'opt_out' ? 'opt_out' : 'reply',
                $messageBody,
                array_merge(
                    ['intent' => $intent],
                    array_filter([
                        'provider_message_id' => $providerMetadata['provider_message_id'] ?? null,
                        'provider_waid' => $providerMetadata['provider_waid'] ?? null,
                        'message_type' => $providerMetadata['message_type'] ?? null,
                        'message_state' => $providerMetadata['message_state'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                    is_array($botResult['metadata'] ?? null) ? $botResult['metadata'] : [],
                    array_filter([
                        'triage_status' => $botResult['triage_status'] ?? null,
                        'current_step' => $botResult['current_step'] ?? null,
                        'automation_status' => $botResult['automation_status'] ?? null,
                        'needs_operator' => $botResult['needs_operator'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null)
                )
            );

            $autoReply = trim((string) ($botResult['auto_reply'] ?? ''));

            $this->pdo->commit();

            $autoReplyDispatch = null;

            if ($autoReply !== '') {
                $autoReplyDispatch = $this->dispatchAutoReply(
                    $campaignId,
                    $campaignRecipientId,
                    (int) $recipient['candidate_id'],
                    (string) $recipient['destination_contact'],
                    $autoReply,
                    [
                        'triage_status' => $botResult['triage_status'] ?? null,
                        'current_step' => $botResult['current_step'] ?? null,
                    ]
                );
            }

            return [
                'intent' => $intent,
                'auto_reply' => $autoReply !== '' ? $autoReply : null,
                'auto_reply_dispatch' => $autoReplyDispatch,
                'triage_status' => $botResult['triage_status'] ?? null,
                'current_step' => $botResult['current_step'] ?? null,
                'automation_status' => $botResult['automation_status'] ?? null,
                'needs_operator' => (bool) ($botResult['needs_operator'] ?? false),
            ];
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
    public function registerInboundReplyByContact(
        ?int $campaignId,
        string $contact,
        string $messageBody,
        string $operator,
        array $providerMetadata = []
    ): array {
        $contact = trim($contact);

        if ($contact === '') {
            throw new InvalidArgumentException('Informe o contato do remetente.');
        }

        $session = $this->triageBotService->findSessionByContact($contact, $campaignId);

        if ($session === null) {
            throw new InvalidArgumentException('Nenhuma sessão ativa de triagem foi encontrada para esse contato.');
        }

        return $this->registerInboundReply(
            (int) $session['campaign_id'],
            (int) $session['campaign_recipient_id'],
            $messageBody,
            $operator,
            $providerMetadata
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recordWhatsGwStatusEvent(array $payload): array
    {
        $providerMessageCustomId = isset($payload['message_custom_id']) ? trim((string) $payload['message_custom_id']) : null;
        $providerMessageId = isset($payload['message_id']) ? trim((string) $payload['message_id']) : null;
        $providerWaid = isset($payload['waid']) ? trim((string) $payload['waid']) : null;
        $contactPhoneNumber = trim((string) ($payload['contact_phone_number'] ?? ''));
        $messageState = strtolower(trim((string) ($payload['message_state'] ?? '')));

        $queue = $this->findQueueByProviderIdentifiers(
            $providerMessageCustomId,
            $providerMessageId,
            $providerWaid,
            $contactPhoneNumber
        );

        if ($queue === null) {
            return [
                'event' => 'status',
                'status' => 'ignored',
                'message' => 'Status recebido sem correlação automática com a fila.',
            ];
        }

        $this->pdo->beginTransaction();

        try {
            $metadata = [
                'provider_message_id' => $providerMessageId,
                'provider_waid' => $providerWaid,
                'provider_message_custom_id' => $providerMessageCustomId,
                'provider_state' => $messageState,
            ];

            $this->updateQueueProviderData((int) $queue['id'], [
                'provider_message_custom_id' => $providerMessageCustomId,
                'provider_message_id' => $providerMessageId,
                'provider_waid' => $providerWaid,
                'provider_message_state' => $messageState,
                'provider_last_event' => 'status',
                'provider_payload' => $payload,
                'delivered_at' => in_array($messageState, ['delivered2server', 'delivered2user', 'read'], true)
                    ? date('Y-m-d H:i:s')
                    : null,
                'read_at' => $messageState === 'read' ? date('Y-m-d H:i:s') : null,
            ]);

            if (in_array($messageState, ['notwa', 'notsent'], true)) {
                $failureReason = sprintf('WhatsGW status: %s', $messageState !== '' ? $messageState : 'failed');

                $queueFailureStatement = $this->pdo->prepare(
                    "UPDATE recruit_message_queue
                     SET status = 'failed',
                         error_message = :error_message,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $queueFailureStatement->execute([
                    'error_message' => $failureReason,
                    'id' => $queue['id'],
                ]);

                $recipientFailureStatement = $this->pdo->prepare(
                    "UPDATE recruit_campaign_recipients
                     SET status = 'failed',
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $recipientFailureStatement->execute(['id' => $queue['campaign_recipient_id']]);
            }

            $eventType = match ($messageState) {
                'read' => 'read',
                'delivered2server', 'delivered2user' => 'delivered',
                default => 'status_update',
            };

            $this->logMessageEvent(
                (int) $queue['campaign_id'],
                (int) $queue['campaign_recipient_id'],
                (int) $queue['candidate_id'],
                'system',
                $eventType,
                null,
                $metadata
            );

            $this->pdo->commit();

            return [
                'event' => 'status',
                'status' => 'processed',
                'message' => 'Status do WhatsGW registrado.',
                'campaign_id' => (int) $queue['campaign_id'],
                'queue_id' => (int) $queue['id'],
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function recordWhatsGwPhoneStateEvent(array $payload): array
    {
        $phoneNumber = $this->normalizePhoneDigits((string) ($payload['phone_number'] ?? ''));
        $state = trim((string) ($payload['state'] ?? ''));

        if ($phoneNumber === '' || $state === '') {
            return [
                'event' => 'phonestate',
                'status' => 'ignored',
                'message' => 'Evento de telefone sem phone_number/state.',
            ];
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_whatsgw_phone_states (
                phone_number,
                w_instancia_id,
                state,
                last_event_at,
                provider_payload
             ) VALUES (
                :phone_number,
                :w_instancia_id,
                :state,
                :last_event_at,
                :provider_payload
             )
             ON DUPLICATE KEY UPDATE
                w_instancia_id = VALUES(w_instancia_id),
                state = VALUES(state),
                last_event_at = VALUES(last_event_at),
                provider_payload = VALUES(provider_payload),
                updated_at = CURRENT_TIMESTAMP'
        );

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $statement->execute([
            'phone_number' => $phoneNumber,
            'w_instancia_id' => isset($payload['w_instancia_id']) && $payload['w_instancia_id'] !== ''
                ? (int) $payload['w_instancia_id']
                : null,
            'state' => $state,
            'last_event_at' => date('Y-m-d H:i:s'),
            'provider_payload' => $encodedPayload === false ? '{}' : $encodedPayload,
        ]);

        return [
            'event' => 'phonestate',
            'status' => 'processed',
            'message' => 'Estado do telefone atualizado.',
            'phone_number' => $phoneNumber,
            'state' => $state,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCampaign(int $campaignId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status, automation_type
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
               AND status IN ('pending', 'processing', 'failed')"
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
        int $attemptCount,
        string $errorMessage
    ): void {
        $this->pdo->beginTransaction();

        try {
            $maxAttempts = $this->resolveMaxAttempts();
            $isFinalFailure = $attemptCount >= $maxAttempts;
            $retryDelayMinutes = $isFinalFailure ? null : $this->retryDelayMinutesForAttempt($attemptCount);
            $nextScheduledAt = $retryDelayMinutes === null
                ? null
                : date('Y-m-d H:i:s', time() + ($retryDelayMinutes * 60));
            $queueStatement = $this->pdo->prepare(
                "UPDATE recruit_message_queue
                 SET status = 'failed',
                     processed_at = :processed_at,
                     scheduled_at = :scheduled_at,
                     error_message = :error_message,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $queueStatement->execute([
                'error_message' => $errorMessage,
                'processed_at' => $isFinalFailure ? date('Y-m-d H:i:s') : null,
                'scheduled_at' => $nextScheduledAt,
                'id' => $queueId,
            ]);

            if ($isFinalFailure) {
                $recipientStatement = $this->pdo->prepare(
                    "UPDATE recruit_campaign_recipients
                     SET status = 'failed',
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                );
                $recipientStatement->execute(['id' => $campaignRecipientId]);
            }

            $this->logMessageEvent(
                $campaignId,
                $campaignRecipientId,
                $candidateId,
                'outbound',
                'failed',
                $errorMessage,
                array_filter([
                    'attempt_count' => $attemptCount,
                    'max_attempts' => $maxAttempts,
                    'is_final_failure' => $isFinalFailure,
                    'retry_in_minutes' => $retryDelayMinutes,
                    'scheduled_at' => $nextScheduledAt,
                ], static fn (mixed $value): bool => $value !== null)
            );

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
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function sendOutboundMessage(
        string $destinationContact,
        string $messageBody,
        string $messageCustomId,
        array $options = []
    ): array
    {
        if (!$this->whatsGwClient->isConfigured()) {
            return [
                'success' => true,
                'simulated' => true,
                'http_status' => 200,
                'raw_body' => '',
                'decoded_body' => [],
                'request_payload' => [
                    'contact_phone_number' => $destinationContact,
                    'message_body' => $messageBody,
                    'message_custom_id' => $messageCustomId,
                ],
                'message_custom_id' => $messageCustomId,
                'provider_message_id' => null,
                'provider_waid' => null,
            ];
        }

        $result = $this->whatsGwClient->sendTextMessage(
            $destinationContact,
            $messageBody,
            array_merge($options, [
                'message_custom_id' => $messageCustomId,
            ])
        );

        if (!($result['success'] ?? false)) {
            $rawBody = trim((string) ($result['raw_body'] ?? ''));
            $httpStatus = (int) ($result['http_status'] ?? 0);

            throw new InvalidArgumentException(
                sprintf(
                    'WhatsGW retornou falha no envio. HTTP %d%s',
                    $httpStatus,
                    $rawBody !== '' ? ': ' . $rawBody : ''
                )
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function dispatchAutoReply(
        int $campaignId,
        int $campaignRecipientId,
        int $candidateId,
        string $destinationContact,
        string $messageBody,
        array $metadata
    ): array {
        $messageCustomId = $this->buildAutoReplyCustomId($campaignRecipientId);

        try {
            $result = $this->sendOutboundMessage($destinationContact, $messageBody, $messageCustomId, [
                'check_status' => 0,
            ]);

            $this->logMessageEvent(
                $campaignId,
                $campaignRecipientId,
                $candidateId,
                'outbound',
                'sent',
                $messageBody,
                array_merge($metadata, [
                    'source' => ($result['simulated'] ?? false) ? 'triage_bot_simulated' : 'triage_bot_whatsgw',
                    'provider_message_custom_id' => $result['message_custom_id'] ?? $messageCustomId,
                    'provider_message_id' => $result['provider_message_id'] ?? null,
                    'provider_waid' => $result['provider_waid'] ?? null,
                ])
            );

            return [
                'success' => true,
                'provider_message_custom_id' => $result['message_custom_id'] ?? $messageCustomId,
                'provider_message_id' => $result['provider_message_id'] ?? null,
                'provider_waid' => $result['provider_waid'] ?? null,
                'simulated' => (bool) ($result['simulated'] ?? false),
            ];
        } catch (Throwable $exception) {
            $this->logMessageEvent(
                $campaignId,
                $campaignRecipientId,
                $candidateId,
                'outbound',
                'failed',
                $messageBody,
                array_merge($metadata, [
                    'source' => 'triage_bot_whatsgw',
                    'provider_message_custom_id' => $messageCustomId,
                    'error' => $exception->getMessage(),
                ])
            );

            return [
                'success' => false,
                'provider_message_custom_id' => $messageCustomId,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $providerResult
     */
    private function markQueueAsSent(
        int $campaignId,
        int $queueId,
        int $campaignRecipientId,
        int $candidateId,
        string $destinationContact,
        string $messageBody,
        int $attemptCount,
        string $messageCustomId,
        array $providerResult,
        string $operator,
        string $automationType
    ): void {
        $queueStatement = $this->pdo->prepare(
            "UPDATE recruit_message_queue
             SET status = 'sent',
                 processed_at = CURRENT_TIMESTAMP,
                 error_message = NULL,
                 provider_message_custom_id = :provider_message_custom_id,
                 provider_message_id = :provider_message_id,
                 provider_waid = :provider_waid,
                 provider_message_state = :provider_message_state,
                 provider_last_event = :provider_last_event,
                 provider_payload = :provider_payload,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $queueStatement->execute([
            'provider_message_custom_id' => $messageCustomId,
            'provider_message_id' => $providerResult['provider_message_id'] ?? null,
            'provider_waid' => $providerResult['provider_waid'] ?? null,
            'provider_message_state' => 'sent',
            'provider_last_event' => ($providerResult['simulated'] ?? false) ? 'simulation' : 'send_api',
            'provider_payload' => $this->encodeJson($providerResult),
            'id' => $queueId,
        ]);

        $recipientStatement = $this->pdo->prepare(
            "UPDATE recruit_campaign_recipients
             SET status = 'sent',
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $recipientStatement->execute(['id' => $campaignRecipientId]);

        $this->advanceCandidateToMessageSent($candidateId, $operator);

        if ($this->triageBotService->isTriageAutomationType($automationType)) {
            $this->triageBotService->activateInitialSession(
                $campaignId,
                $campaignRecipientId,
                $candidateId,
                $messageBody
            );
        }

        $this->logMessageEvent(
            $campaignId,
            $campaignRecipientId,
            $candidateId,
            'outbound',
            'sent',
            $messageBody,
            [
                'destination_contact' => $destinationContact,
                'attempt_count' => $attemptCount,
                'provider_message_custom_id' => $messageCustomId,
                'provider_message_id' => $providerResult['provider_message_id'] ?? null,
                'provider_waid' => $providerResult['provider_waid'] ?? null,
                'source' => ($providerResult['simulated'] ?? false) ? 'simulation' : 'whatsgw',
            ]
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function updateQueueProviderData(int $queueId, array $attributes): void
    {
        if ($attributes === []) {
            return;
        }

        $allowedColumns = [
            'provider_message_custom_id',
            'provider_message_id',
            'provider_waid',
            'provider_message_state',
            'provider_last_event',
            'provider_payload',
            'delivered_at',
            'read_at',
        ];

        $params = ['id' => $queueId];
        $sets = [];

        foreach ($allowedColumns as $column) {
            if (!array_key_exists($column, $attributes)) {
                continue;
            }

            $sets[] = sprintf('%s = :%s', $column, $column);
            $params[$column] = $column === 'provider_payload'
                ? $this->encodeJson($attributes[$column])
                : $attributes[$column];
        }

        if ($sets === []) {
            return;
        }

        $sql = sprintf(
            'UPDATE recruit_message_queue SET %s, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            implode(', ', $sets)
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findQueueByProviderIdentifiers(
        ?string $providerMessageCustomId,
        ?string $providerMessageId,
        ?string $providerWaid,
        string $contactPhoneNumber
    ): ?array {
        $providerMessageCustomId = $providerMessageCustomId !== null ? trim($providerMessageCustomId) : null;
        $providerMessageId = $providerMessageId !== null ? trim($providerMessageId) : null;
        $providerWaid = $providerWaid !== null ? trim($providerWaid) : null;
        $normalizedContact = $this->normalizePhoneDigits($contactPhoneNumber);

        if ($providerMessageCustomId !== null && $providerMessageCustomId !== '') {
            $queue = $this->findQueueByColumn('provider_message_custom_id', $providerMessageCustomId);

            if ($queue !== null) {
                return $queue;
            }
        }

        if ($providerMessageId !== null && $providerMessageId !== '') {
            $queue = $this->findQueueByColumn('provider_message_id', $providerMessageId);

            if ($queue !== null) {
                return $queue;
            }
        }

        if ($providerWaid !== null && $providerWaid !== '') {
            $queue = $this->findQueueByColumn('provider_waid', $providerWaid);

            if ($queue !== null) {
                return $queue;
            }
        }

        if ($normalizedContact === '') {
            return null;
        }

        return $this->findLatestQueueByContact($normalizedContact);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findQueueByColumn(string $column, string $value): ?array
    {
        $allowedColumns = [
            'provider_message_custom_id',
            'provider_message_id',
            'provider_waid',
        ];

        if (!in_array($column, $allowedColumns, true)) {
            return null;
        }

        $statement = $this->pdo->prepare(
            sprintf(
                'SELECT id, campaign_id, campaign_recipient_id, candidate_id
                 FROM recruit_message_queue
                 WHERE %s = :value
                 ORDER BY id DESC
                 LIMIT 1',
                $column
            )
        );
        $statement->execute(['value' => $value]);
        $queue = $statement->fetch();

        return $queue === false ? null : $queue;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatestQueueByContact(string $normalizedContact): ?array
    {
        $contactLength = max(1, strlen($normalizedContact));
        $statement = $this->pdo->prepare(
            "SELECT
                queue.id,
                queue.campaign_id,
                queue.campaign_recipient_id,
                queue.candidate_id
             FROM recruit_message_queue queue
             WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(queue.destination_contact, '+', ''), '-', ''), '(', ''), ')', ''), ' ', ''), '.', ''), '/', ''), {$contactLength}) = :contact
             ORDER BY queue.processed_at DESC, queue.id DESC
             LIMIT 1"
        );
        $statement->execute(['contact' => $normalizedContact]);
        $queue = $statement->fetch();

        return $queue === false ? null : $queue;
    }

    private function buildQueueMessageCustomId(int $queueId): string
    {
        return 'queue-' . $queueId;
    }

    private function resolveBatchSize(?int $limit = null): int
    {
        $resolvedLimit = $limit ?? $this->envInt('CAMPAIGN_QUEUE_BATCH_SIZE', self::DEFAULT_BATCH_SIZE);

        return max(1, min(self::MAX_BATCH_SIZE, $resolvedLimit));
    }

    private function resolveMaxAttempts(): int
    {
        return max(1, min(10, $this->envInt('CAMPAIGN_QUEUE_MAX_ATTEMPTS', self::DEFAULT_MAX_ATTEMPTS)));
    }

    private function resolveStaleProcessingMinutes(): int
    {
        return max(1, min(240, $this->envInt('CAMPAIGN_QUEUE_STALE_MINUTES', self::DEFAULT_STALE_PROCESSING_MINUTES)));
    }

    private function envInt(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || trim((string) $value) === '') {
            return $default;
        }

        return (int) $value;
    }

    private function retryDelayMinutesForAttempt(int $attemptCount): int
    {
        return match (true) {
            $attemptCount <= 1 => 1,
            $attemptCount === 2 => 5,
            $attemptCount === 3 => 15,
            $attemptCount === 4 => 60,
            default => 180,
        };
    }

    private function buildAutoReplyCustomId(int $campaignRecipientId): string
    {
        return 'triage-' . $campaignRecipientId . '-' . time();
    }

    private function normalizePhoneDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function encodeJson(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
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

    private function hasOpenQueueJobs(int $campaignId): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM recruit_message_queue
             WHERE campaign_id = :campaign_id
               AND (
                    status IN ('pending', 'processing')
                    OR (status = 'failed' AND attempt_count < :max_attempts)
               )"
        );
        $statement->execute([
            'campaign_id' => $campaignId,
            'max_attempts' => $this->resolveMaxAttempts(),
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return list<int>
     */
    private function findCampaignIdsWithDueQueue(int $limit): array
    {
        $statement = $this->pdo->prepare(
            sprintf(
                "SELECT
                    queue.campaign_id
                 FROM recruit_message_queue queue
                 INNER JOIN recruit_campaigns campaign ON campaign.id = queue.campaign_id
                 WHERE campaign.status IN ('queued', 'sending')
                   AND queue.status IN ('pending', 'failed')
                   AND queue.scheduled_at <= CURRENT_TIMESTAMP
                   AND (queue.status = 'pending' OR queue.attempt_count < :max_attempts)
                 GROUP BY queue.campaign_id
                 ORDER BY MIN(queue.scheduled_at) ASC, MIN(queue.id) ASC
                 LIMIT %d",
                max(1, $limit)
            )
        );
        $statement->execute([
            'max_attempts' => $this->resolveMaxAttempts(),
        ]);

        return array_map(
            static fn (array $row): int => (int) $row['campaign_id'],
            $statement->fetchAll()
        );
    }

    private function releaseStaleProcessingJobs(?int $campaignId = null): int
    {
        $sql = "UPDATE recruit_message_queue
                SET status = 'failed',
                    scheduled_at = CURRENT_TIMESTAMP,
                    error_message = CASE
                        WHEN error_message IS NULL OR error_message = '' THEN 'PROCESSING expirado automaticamente.'
                        ELSE CONCAT(error_message, ' | PROCESSING expirado automaticamente.')
                    END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE status = 'processing'
                  AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL %d MINUTE)";

        $params = [];

        if ($campaignId !== null) {
            $sql .= ' AND campaign_id = :campaign_id';
            $params['campaign_id'] = $campaignId;
        }

        $statement = $this->pdo->prepare(sprintf($sql, $this->resolveStaleProcessingMinutes()));
        $statement->execute($params);

        return $statement->rowCount();
    }

    private function refreshCampaignStatus(int $campaignId, string $operator): string
    {
        $campaign = $this->fetchCampaign($campaignId);

        if ($campaign === null) {
            throw new InvalidArgumentException('Campanha não encontrada.');
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
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_jobs,
                SUM(CASE WHEN status = 'failed' AND attempt_count < :max_attempts THEN 1 ELSE 0 END) AS retryable_failed_jobs
             FROM recruit_message_queue
             WHERE campaign_id = :campaign_id"
        );
        $statement->execute([
            'campaign_id' => $campaignId,
            'max_attempts' => $this->resolveMaxAttempts(),
        ]);
        $stats = $statement->fetch() ?: [];

        $pendingJobs = (int) ($stats['pending_jobs'] ?? 0);
        $processingJobs = (int) ($stats['processing_jobs'] ?? 0);
        $retryableFailedJobs = (int) ($stats['retryable_failed_jobs'] ?? 0);

        if ($processingJobs > 0) {
            $this->setCampaignStatus($campaignId, 'sending');

            return 'sending';
        }

        if ($pendingJobs > 0 || $retryableFailedJobs > 0) {
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
            $normalized === '3'
            || str_contains($normalized, 'mais detalhes')
            || str_contains($normalized, 'preciso de detalhes')
            || str_contains($normalized, 'talvez')
        ) {
            return 'needs_details';
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
