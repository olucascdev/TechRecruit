<?php

declare(strict_types=1);

namespace TechRecruit\Models;

use PDO;
use TechRecruit\Database;

final class OperationsModel
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findQueue(): array
    {
        $sql = <<<SQL
SELECT
    candidate.id AS candidate_id,
    candidate.full_name,
    candidate.status AS candidate_status,
    portal.status AS portal_status,
    portal.submitted_at,
    portal.updated_at,
    COALESCE(document_stats.total_documents, 0) AS total_documents,
    COALESCE(document_stats.pending_documents, 0) AS pending_documents,
    COALESCE(document_stats.correction_documents, 0) AS correction_documents,
    COALESCE(pendency_stats.open_pendencies, 0) AS open_pendencies,
    review_stats.last_review_at
FROM recruit_candidate_portals portal
INNER JOIN recruit_candidates candidate ON candidate.id = portal.candidate_id
LEFT JOIN (
    SELECT
        portal_id,
        COUNT(*) AS total_documents,
        SUM(CASE WHEN review_status = 'pending' THEN 1 ELSE 0 END) AS pending_documents,
        SUM(CASE WHEN review_status = 'correction_requested' THEN 1 ELSE 0 END) AS correction_documents
    FROM recruit_candidate_documents
    GROUP BY portal_id
) AS document_stats ON document_stats.portal_id = portal.id
LEFT JOIN (
    SELECT
        portal_id,
        SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_pendencies
    FROM recruit_review_pendencies
    GROUP BY portal_id
) AS pendency_stats ON pendency_stats.portal_id = portal.id
LEFT JOIN (
    SELECT
        portal_id,
        MAX(created_at) AS last_review_at
    FROM recruit_review_history
    GROUP BY portal_id
) AS review_stats ON review_stats.portal_id = portal.id
WHERE portal.status IN ('submitted', 'under_review', 'correction_requested')
ORDER BY
    COALESCE(pendency_stats.open_pendencies, 0) DESC,
    portal.updated_at DESC,
    candidate.full_name ASC
SQL;

        $statement = $this->pdo->query($sql);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>
     */
    public function findByCandidateId(int $candidateId): array
    {
        $portalStatement = $this->pdo->prepare(
            'SELECT id, status
             FROM recruit_candidate_portals
             WHERE candidate_id = :candidate_id
             LIMIT 1'
        );
        $portalStatement->execute(['candidate_id' => $candidateId]);
        $portal = $portalStatement->fetch();

        if ($portal === false) {
            return [
                'summary' => [
                    'open_pendencies' => 0,
                    'pending_documents' => 0,
                    'approved_documents' => 0,
                    'rejected_documents' => 0,
                    'correction_documents' => 0,
                ],
                'pendencies' => [],
                'history' => [],
            ];
        }

        $summaryStatement = $this->pdo->prepare(
            "SELECT
                SUM(CASE WHEN review_status = 'pending' THEN 1 ELSE 0 END) AS pending_documents,
                SUM(CASE WHEN review_status = 'approved' THEN 1 ELSE 0 END) AS approved_documents,
                SUM(CASE WHEN review_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_documents,
                SUM(CASE WHEN review_status = 'correction_requested' THEN 1 ELSE 0 END) AS correction_documents,
                (
                    SELECT COUNT(*)
                    FROM recruit_review_pendencies
                    WHERE portal_id = :portal_id_summary
                      AND status = 'open'
                ) AS open_pendencies
             FROM recruit_candidate_documents
             WHERE portal_id = :portal_id"
        );
        $summaryStatement->execute([
            'portal_id_summary' => $portal['id'],
            'portal_id' => $portal['id'],
        ]);
        $summary = $summaryStatement->fetch() ?: [];

        $pendenciesStatement = $this->pdo->prepare(
            'SELECT id, document_id, title, description, status, created_by, resolved_by, resolved_at, created_at, updated_at
             FROM recruit_review_pendencies
             WHERE portal_id = :portal_id
             ORDER BY status ASC, created_at DESC, id DESC'
        );
        $pendenciesStatement->execute(['portal_id' => $portal['id']]);

        $historyStatement = $this->pdo->prepare(
            "SELECT
                history.id,
                history.document_id,
                history.action,
                history.message,
                history.created_by,
                history.created_at,
                document.document_type,
                document.original_name
             FROM recruit_review_history history
             LEFT JOIN recruit_candidate_documents document ON document.id = history.document_id
             WHERE history.portal_id = :portal_id
             ORDER BY history.created_at DESC, history.id DESC
             LIMIT 50"
        );
        $historyStatement->execute(['portal_id' => $portal['id']]);

        return [
            'summary' => $summary,
            'pendencies' => $pendenciesStatement->fetchAll(),
            'history' => $historyStatement->fetchAll(),
        ];
    }
}
