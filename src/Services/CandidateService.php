<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use InvalidArgumentException;
use PDO;
use TechRecruit\Database;
use Throwable;

final class CandidateService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    public function deleteCandidate(int $candidateId): void
    {
        $candidateStatement = $this->pdo->prepare(
            'SELECT id
             FROM recruit_candidates
             WHERE id = :id
             LIMIT 1'
        );
        $candidateStatement->execute(['id' => $candidateId]);

        if ($candidateStatement->fetch() === false) {
            throw new InvalidArgumentException('Candidato não encontrado.');
        }

        $campaignIdsStatement = $this->pdo->prepare(
            'SELECT DISTINCT campaign_id
             FROM recruit_campaign_recipients
             WHERE candidate_id = :candidate_id'
        );
        $campaignIdsStatement->execute(['candidate_id' => $candidateId]);
        $campaignIds = array_map('intval', $campaignIdsStatement->fetchAll(PDO::FETCH_COLUMN));

        $this->pdo->beginTransaction();

        try {
            $deleteStatement = $this->pdo->prepare(
                'DELETE FROM recruit_candidates
                 WHERE id = :id'
            );
            $deleteStatement->execute(['id' => $candidateId]);

            if ($deleteStatement->rowCount() !== 1) {
                throw new InvalidArgumentException('Candidato não encontrado.');
            }

            foreach ($campaignIds as $campaignId) {
                $this->refreshCampaignSnapshots($campaignId);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $this->cleanupCandidateStorage($candidateId);
    }

    private function refreshCampaignSnapshots(int $campaignId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recruit_campaigns
             SET audience_count = (
                    SELECT COUNT(*)
                    FROM recruit_campaign_recipients
                    WHERE campaign_id = :campaign_id_audience
                 ),
                 queued_count = (
                    SELECT COUNT(*)
                    FROM recruit_campaign_recipients
                    WHERE campaign_id = :campaign_id_queue
                 ),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :campaign_id'
        );
        $statement->execute([
            'campaign_id_audience' => $campaignId,
            'campaign_id_queue' => $campaignId,
            'campaign_id' => $campaignId,
        ]);
    }

    private function cleanupCandidateStorage(int $candidateId): void
    {
        $directory = dirname(__DIR__, 2) . '/storage/portal-documents/' . $candidateId;

        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . '/' . $item;

            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
