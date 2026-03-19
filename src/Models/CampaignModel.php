<?php

declare(strict_types=1);

namespace TechRecruit\Models;

use PDO;
use TechRecruit\Database;
use Throwable;

final class CampaignModel
{
    /** @var list<string> */
    public const VALID_STATUSES = [
        'draft',
        'queued',
        'sending',
        'paused',
        'completed',
        'cancelled',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $sql = <<<SQL
SELECT
    c.id,
    c.name,
    c.channel,
    c.status,
    c.audience_count,
    c.queued_count,
    c.recipient_limit,
    c.created_by,
    c.created_at,
    c.updated_at,
    COALESCE(queue_stats.pending_count, 0) AS pending_count,
    COALESCE(queue_stats.sent_count, 0) AS sent_count,
    COALESCE(queue_stats.failed_count, 0) AS failed_count,
    COALESCE(recipient_stats.responded_count, 0) AS responded_count,
    COALESCE(recipient_stats.opt_out_count, 0) AS opt_out_count
FROM recruit_campaigns c
LEFT JOIN (
    SELECT
        campaign_id,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
    FROM recruit_message_queue
    GROUP BY campaign_id
) AS queue_stats ON queue_stats.campaign_id = c.id
LEFT JOIN (
    SELECT
        campaign_id,
        SUM(CASE WHEN status = 'responded' THEN 1 ELSE 0 END) AS responded_count,
        SUM(CASE WHEN status = 'opt_out' THEN 1 ELSE 0 END) AS opt_out_count
    FROM recruit_campaign_recipients
    GROUP BY campaign_id
) AS recipient_stats ON recipient_stats.campaign_id = c.id
ORDER BY c.created_at DESC, c.id DESC
LIMIT 30
SQL;

        $statement = $this->pdo->query($sql);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, channel, status, message_template, segment_filters, recipient_limit, audience_count, queued_count, created_by, created_at, updated_at
             FROM recruit_campaigns
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $campaign = $statement->fetch();

        if ($campaign === false) {
            return null;
        }

        $decodedFilters = json_decode((string) $campaign['segment_filters'], true);
        $campaign['segment_filters'] = is_array($decodedFilters) ? $decodedFilters : [];

        $statsStatement = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total_recipients,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued_recipients,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_recipients,
                SUM(CASE WHEN status = 'responded' THEN 1 ELSE 0 END) AS responded_recipients,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_recipients,
                SUM(CASE WHEN status = 'opt_out' THEN 1 ELSE 0 END) AS opt_out_recipients,
                SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped_recipients
             FROM recruit_campaign_recipients
             WHERE campaign_id = :campaign_id"
        );
        $statsStatement->execute(['campaign_id' => $id]);
        $campaign['recipient_stats'] = $statsStatement->fetch() ?: [];

        $queueStatsStatement = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_jobs,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_jobs,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_jobs,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_jobs,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_jobs
             FROM recruit_message_queue
             WHERE campaign_id = :campaign_id"
        );
        $queueStatsStatement->execute(['campaign_id' => $id]);
        $campaign['queue_stats'] = $queueStatsStatement->fetch() ?: [];

        $recipientsStatement = $this->pdo->prepare(
            "SELECT
                r.id,
                r.candidate_id,
                r.candidate_name_snapshot,
                r.candidate_status_snapshot,
                r.destination_contact,
                r.status,
                r.created_at,
                candidate.status AS current_candidate_status,
                queue.status AS queue_status,
                queue.scheduled_at,
                queue.processed_at
             FROM recruit_campaign_recipients r
             INNER JOIN recruit_candidates candidate ON candidate.id = r.candidate_id
             LEFT JOIN recruit_message_queue queue ON queue.campaign_recipient_id = r.id
             WHERE r.campaign_id = :campaign_id
             ORDER BY r.id ASC"
        );
        $recipientsStatement->execute(['campaign_id' => $id]);
        $campaign['recipients'] = $recipientsStatement->fetchAll();

        $logsStatement = $this->pdo->prepare(
            "SELECT
                l.id,
                l.direction,
                l.event_type,
                l.message_body,
                l.metadata,
                l.created_at,
                candidate.full_name AS candidate_name
             FROM recruit_message_logs l
             LEFT JOIN recruit_candidates candidate ON candidate.id = l.candidate_id
             WHERE l.campaign_id = :campaign_id
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT 50"
        );
        $logsStatement->execute(['campaign_id' => $id]);
        $logs = $logsStatement->fetchAll();

        foreach ($logs as &$log) {
            $decodedMetadata = json_decode((string) ($log['metadata'] ?? ''), true);
            $log['metadata'] = is_array($decodedMetadata) ? $decodedMetadata : [];
        }
        unset($log);

        $campaign['activity_logs'] = $logs;

        $inboundStatement = $this->pdo->prepare(
            "SELECT
                inbound.id,
                inbound.source_contact,
                inbound.message_body,
                inbound.parsed_intent,
                inbound.received_at,
                candidate.full_name AS candidate_name
             FROM recruit_whatsapp_inbound inbound
             INNER JOIN recruit_candidates candidate ON candidate.id = inbound.candidate_id
             WHERE inbound.campaign_id = :campaign_id
             ORDER BY inbound.received_at DESC, inbound.id DESC
             LIMIT 20"
        );
        $inboundStatement->execute(['campaign_id' => $id]);
        $campaign['inbound_messages'] = $inboundStatement->fetchAll();

        return $campaign;
    }

    /**
     * @param array<string, mixed> $campaignData
     * @param array<int, array<string, mixed>> $recipients
     */
    public function createWithRecipients(array $campaignData, array $recipients): int
    {
        try {
            $this->pdo->beginTransaction();

            $campaignStatement = $this->pdo->prepare(
                'INSERT INTO recruit_campaigns (
                    name,
                    channel,
                    status,
                    message_template,
                    segment_filters,
                    recipient_limit,
                    audience_count,
                    queued_count,
                    created_by
                 ) VALUES (
                    :name,
                    :channel,
                    :status,
                    :message_template,
                    :segment_filters,
                    :recipient_limit,
                    :audience_count,
                    :queued_count,
                    :created_by
                 )'
            );
            $campaignStatement->execute([
                'name' => $campaignData['name'],
                'channel' => 'whatsapp',
                'status' => $campaignData['status'],
                'message_template' => $campaignData['message_template'],
                'segment_filters' => $campaignData['segment_filters'],
                'recipient_limit' => $campaignData['recipient_limit'],
                'audience_count' => $campaignData['audience_count'],
                'queued_count' => $campaignData['queued_count'],
                'created_by' => $campaignData['created_by'],
            ]);

            $campaignId = (int) $this->pdo->lastInsertId();

            $recipientStatement = $this->pdo->prepare(
                'INSERT INTO recruit_campaign_recipients (
                    campaign_id,
                    candidate_id,
                    candidate_name_snapshot,
                    candidate_status_snapshot,
                    destination_contact,
                    status
                 ) VALUES (
                    :campaign_id,
                    :candidate_id,
                    :candidate_name_snapshot,
                    :candidate_status_snapshot,
                    :destination_contact,
                    :status
                 )'
            );

            $queueStatement = $this->pdo->prepare(
                'INSERT INTO recruit_message_queue (
                    campaign_id,
                    campaign_recipient_id,
                    candidate_id,
                    channel,
                    direction,
                    destination_contact,
                    message_body,
                    payload,
                    status,
                    scheduled_at
                 ) VALUES (
                    :campaign_id,
                    :campaign_recipient_id,
                    :candidate_id,
                    :channel,
                    :direction,
                    :destination_contact,
                    :message_body,
                    :payload,
                    :status,
                    CURRENT_TIMESTAMP
                 )'
            );

            foreach ($recipients as $recipient) {
                $recipientStatement->execute([
                    'campaign_id' => $campaignId,
                    'candidate_id' => $recipient['candidate_id'],
                    'candidate_name_snapshot' => $recipient['candidate_name_snapshot'],
                    'candidate_status_snapshot' => $recipient['candidate_status_snapshot'],
                    'destination_contact' => $recipient['destination_contact'],
                    'status' => 'queued',
                ]);

                $campaignRecipientId = (int) $this->pdo->lastInsertId();

                $queueStatement->execute([
                    'campaign_id' => $campaignId,
                    'campaign_recipient_id' => $campaignRecipientId,
                    'candidate_id' => $recipient['candidate_id'],
                    'channel' => 'whatsapp',
                    'direction' => 'outbound',
                    'destination_contact' => $recipient['destination_contact'],
                    'message_body' => $recipient['message_body'],
                    'payload' => $recipient['payload'],
                    'status' => 'pending',
                ]);
            }

            $this->pdo->commit();

            return $campaignId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
