<?php

declare(strict_types=1);

namespace TechRecruit\Models;

use PDO;
use TechRecruit\Database;

final class TriageModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSessionByCampaignRecipientId(int $campaignRecipientId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                campaign_id,
                campaign_recipient_id,
                candidate_id,
                flow_version,
                triage_status,
                current_step,
                automation_status,
                needs_operator,
                invalid_reply_count,
                fallback_reason,
                collected_data,
                last_inbound_message,
                last_outbound_message,
                last_interaction_at,
                created_at,
                updated_at
             FROM recruit_triage_sessions
             WHERE campaign_recipient_id = :campaign_recipient_id
             LIMIT 1'
        );
        $statement->execute(['campaign_recipient_id' => $campaignRecipientId]);
        $session = $statement->fetch();

        return $session === false ? null : $this->decodeSession($session);
    }

    /**
     * @param array<string, mixed> $sessionData
     */
    public function createSession(array $sessionData): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_triage_sessions (
                campaign_id,
                campaign_recipient_id,
                candidate_id,
                flow_version,
                triage_status,
                current_step,
                automation_status,
                needs_operator,
                invalid_reply_count,
                fallback_reason,
                collected_data,
                last_inbound_message,
                last_outbound_message,
                last_interaction_at
             ) VALUES (
                :campaign_id,
                :campaign_recipient_id,
                :candidate_id,
                :flow_version,
                :triage_status,
                :current_step,
                :automation_status,
                :needs_operator,
                :invalid_reply_count,
                :fallback_reason,
                :collected_data,
                :last_inbound_message,
                :last_outbound_message,
                :last_interaction_at
             )'
        );
        $statement->execute([
            'campaign_id' => $sessionData['campaign_id'],
            'campaign_recipient_id' => $sessionData['campaign_recipient_id'],
            'candidate_id' => $sessionData['candidate_id'],
            'flow_version' => $sessionData['flow_version'] ?? '0.3.0',
            'triage_status' => $sessionData['triage_status'],
            'current_step' => $sessionData['current_step'],
            'automation_status' => $sessionData['automation_status'],
            'needs_operator' => !empty($sessionData['needs_operator']) ? 1 : 0,
            'invalid_reply_count' => $sessionData['invalid_reply_count'] ?? 0,
            'fallback_reason' => $sessionData['fallback_reason'] ?? null,
            'collected_data' => $this->encodeJson($sessionData['collected_data'] ?? null),
            'last_inbound_message' => $sessionData['last_inbound_message'] ?? null,
            'last_outbound_message' => $sessionData['last_outbound_message'] ?? null,
            'last_interaction_at' => $sessionData['last_interaction_at'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function updateSession(int $sessionId, array $attributes): void
    {
        if ($attributes === []) {
            return;
        }

        $allowedColumns = [
            'triage_status',
            'current_step',
            'automation_status',
            'needs_operator',
            'invalid_reply_count',
            'fallback_reason',
            'collected_data',
            'last_inbound_message',
            'last_outbound_message',
            'last_interaction_at',
        ];

        $sets = [];
        $params = ['id' => $sessionId];

        foreach ($allowedColumns as $column) {
            if (!array_key_exists($column, $attributes)) {
                continue;
            }

            $sets[] = sprintf('%s = :%s', $column, $column);

            if ($column === 'collected_data') {
                $params[$column] = $this->encodeJson($attributes[$column]);
                continue;
            }

            if ($column === 'needs_operator') {
                $params[$column] = !empty($attributes[$column]) ? 1 : 0;

                continue;
            }

            $params[$column] = $attributes[$column];
        }

        if ($sets === []) {
            return;
        }

        $sql = sprintf(
            'UPDATE recruit_triage_sessions SET %s, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            implode(', ', $sets)
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
    }

    /**
     * @param array<string, mixed>|null $normalizedPayload
     */
    public function logAnswer(
        int $sessionId,
        string $stepKey,
        string $direction,
        string $messageBody,
        ?array $normalizedPayload = null,
        ?string $createdBy = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_triage_answers (
                triage_session_id,
                step_key,
                message_direction,
                message_body,
                normalized_payload,
                created_by
             ) VALUES (
                :triage_session_id,
                :step_key,
                :message_direction,
                :message_body,
                :normalized_payload,
                :created_by
             )'
        );
        $statement->execute([
            'triage_session_id' => $sessionId,
            'step_key' => $stepKey,
            'message_direction' => $direction,
            'message_body' => $messageBody,
            'normalized_payload' => $this->encodeJson($normalizedPayload),
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestSessionByContact(string $normalizedContact, ?int $campaignId = null): ?array
    {
        $contactLength = max(1, strlen($normalizedContact));
        $sql = "SELECT
                    session.id,
                    session.campaign_id,
                    session.campaign_recipient_id,
                    session.candidate_id,
                    session.flow_version,
                    session.triage_status,
                    session.current_step,
                    session.automation_status,
                    session.needs_operator,
                    session.invalid_reply_count,
                    session.fallback_reason,
                    session.collected_data,
                    session.last_inbound_message,
                    session.last_outbound_message,
                    session.last_interaction_at,
                    session.created_at,
                    session.updated_at
                FROM recruit_triage_sessions session
                INNER JOIN recruit_campaign_recipients recipient ON recipient.id = session.campaign_recipient_id
                INNER JOIN recruit_campaigns campaign ON campaign.id = session.campaign_id
                WHERE campaign.automation_type = :automation_type
                  AND campaign.status <> :cancelled
                  AND (
                    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(recipient.destination_contact, '+', ''), '-', ''), '(', ''), ')', ''), ' ', ''), '.', ''), '/', '') = :normalized_contact_full
                    OR RIGHT(
                        REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(recipient.destination_contact, '+', ''), '-', ''), '(', ''), ')', ''), ' ', ''), '.', ''), '/', ''),
                        {$contactLength}
                    ) = :normalized_contact_suffix
                  )";

        $params = [
            'automation_type' => 'triage_w13',
            'cancelled' => 'cancelled',
            'normalized_contact_full' => $normalizedContact,
            'normalized_contact_suffix' => $normalizedContact,
        ];

        if ($campaignId !== null) {
            $sql .= ' AND session.campaign_id = :campaign_id';
            $params['campaign_id'] = $campaignId;
        }

        $sql .= " ORDER BY
                    CASE session.automation_status
                        WHEN 'active' THEN 0
                        WHEN 'waiting_operator' THEN 1
                        ELSE 2
                    END ASC,
                    session.updated_at DESC
                  LIMIT 1";

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $session = $statement->fetch();

        return $session === false ? null : $this->decodeSession($session);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLatestSessionByCandidateId(int $candidateId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                id,
                campaign_id,
                campaign_recipient_id,
                candidate_id,
                flow_version,
                triage_status,
                current_step,
                automation_status,
                needs_operator,
                invalid_reply_count,
                fallback_reason,
                collected_data,
                last_inbound_message,
                last_outbound_message,
                last_interaction_at,
                created_at,
                updated_at
             FROM recruit_triage_sessions
             WHERE candidate_id = :candidate_id
             ORDER BY updated_at DESC, id DESC
             LIMIT 1'
        );
        $statement->execute(['candidate_id' => $candidateId]);
        $session = $statement->fetch();

        return $session === false ? null : $this->decodeSession($session);
    }

    /**
     * @return array<string, int>
     */
    public function findStatsByCampaignId(int $campaignId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN triage_status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
                SUM(CASE WHEN triage_status = 'interested' THEN 1 ELSE 0 END) AS interested_count,
                SUM(CASE WHEN triage_status = 'not_interested' THEN 1 ELSE 0 END) AS not_interested_count,
                SUM(CASE WHEN triage_status = 'needs_details' THEN 1 ELSE 0 END) AS needs_details_count,
                SUM(CASE WHEN triage_status = 'awaiting_validation' THEN 1 ELSE 0 END) AS awaiting_validation_count,
                SUM(CASE WHEN needs_operator = 1 THEN 1 ELSE 0 END) AS operator_count
             FROM recruit_triage_sessions
             WHERE campaign_id = :campaign_id"
        );
        $statement->execute(['campaign_id' => $campaignId]);

        return $statement->fetch() ?: [];
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function decodeSession(array $session): array
    {
        $decoded = json_decode((string) ($session['collected_data'] ?? ''), true);
        $session['collected_data'] = is_array($decoded) ? $decoded : [];

        return $session;
    }

    private function encodeJson(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
