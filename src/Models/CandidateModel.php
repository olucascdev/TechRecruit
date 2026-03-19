<?php

declare(strict_types=1);

namespace TechRecruit\Models;

use PDO;
use TechRecruit\Database;
use Throwable;

final class CandidateModel
{
    /** @var list<string> */
    public const VALID_STATUSES = [
        'imported',
        'queued',
        'message_sent',
        'responded',
        'not_interested',
        'interested',
        'awaiting_docs',
        'docs_sent',
        'under_review',
        'approved',
        'rejected',
        'awaiting_contract',
        'contract_signed',
        'closed',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @param array{skill?:string,status?:string,state?:string,search?:string} $filters
     * @return array{data:array<int, array<string, mixed>>, total:int, page:int}
     */
    public function findAll(array $filters = [], int $page = 1, int $perPage = 30): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = $this->buildFilterSql($filters);

        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM recruit_candidates c' . $whereSql
        );
        $this->bindParams($countStatement, $params);
        $countStatement->execute();
        $total = (int) $countStatement->fetchColumn();

        $sql = <<<SQL
SELECT
    c.id,
    c.full_name,
    c.cpf,
    c.status,
    c.created_at,
    c.updated_at,
    COALESCE(contact_data.primary_phone, contact_data.any_phone) AS phone,
    COALESCE(contact_data.primary_whatsapp, contact_data.any_whatsapp) AS whatsapp,
    COALESCE(contact_data.primary_email, contact_data.any_email) AS email,
    skill_data.skills,
    address_data.state,
    address_data.city
FROM recruit_candidates c
LEFT JOIN (
    SELECT
        candidate_id,
        MAX(CASE WHEN type = 'phone' AND is_primary = 1 THEN value END) AS primary_phone,
        MAX(CASE WHEN type = 'phone' THEN value END) AS any_phone,
        MAX(CASE WHEN type = 'whatsapp' AND is_primary = 1 THEN value END) AS primary_whatsapp,
        MAX(CASE WHEN type = 'whatsapp' THEN value END) AS any_whatsapp,
        MAX(CASE WHEN type = 'email' AND is_primary = 1 THEN value END) AS primary_email,
        MAX(CASE WHEN type = 'email' THEN value END) AS any_email
    FROM recruit_candidate_contacts
    GROUP BY candidate_id
) AS contact_data ON contact_data.candidate_id = c.id
LEFT JOIN (
    SELECT
        candidate_id,
        GROUP_CONCAT(DISTINCT skill ORDER BY skill SEPARATOR ', ') AS skills
    FROM recruit_candidate_skills
    GROUP BY candidate_id
) AS skill_data ON skill_data.candidate_id = c.id
LEFT JOIN (
    SELECT
        candidate_id,
        MAX(state) AS state,
        MAX(city) AS city
    FROM recruit_candidate_addresses
    GROUP BY candidate_id
) AS address_data ON address_data.candidate_id = c.id
{$whereSql}
ORDER BY c.created_at DESC, c.id DESC
LIMIT :limit OFFSET :offset
SQL;

        $statement = $this->pdo->prepare($sql);
        $this->bindParams($statement, $params);
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'data' => $statement->fetchAll(),
            'total' => $total,
            'page' => $page,
        ];
    }

    /**
     * @param array{skill?:string,status?:string,state?:string,search?:string} $filters
     */
    public function countEligibleForCampaign(array $filters = []): int
    {
        [$whereSql, $params] = $this->buildFilterSql($filters, true);

        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM recruit_candidates c' . $whereSql
        );
        $this->bindParams($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array{skill?:string,status?:string,state?:string,search?:string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function findEligibleForCampaign(array $filters = [], ?int $limit = null): array
    {
        [$whereSql, $params] = $this->buildFilterSql($filters, true);

        $limitSql = '';

        if ($limit !== null) {
            $limit = max(1, $limit);
            $limitSql = ' LIMIT :limit';
        }

        $sql = <<<SQL
SELECT
    c.id,
    c.full_name,
    c.status,
    COALESCE(contact_data.primary_whatsapp, contact_data.any_whatsapp, contact_data.primary_phone, contact_data.any_phone) AS destination_contact
FROM recruit_candidates c
LEFT JOIN (
    SELECT
        candidate_id,
        MAX(CASE WHEN type = 'phone' AND is_primary = 1 THEN value END) AS primary_phone,
        MAX(CASE WHEN type = 'phone' THEN value END) AS any_phone,
        MAX(CASE WHEN type = 'whatsapp' AND is_primary = 1 THEN value END) AS primary_whatsapp,
        MAX(CASE WHEN type = 'whatsapp' THEN value END) AS any_whatsapp
    FROM recruit_candidate_contacts
    GROUP BY candidate_id
) AS contact_data ON contact_data.candidate_id = c.id
{$whereSql}
ORDER BY c.created_at DESC, c.id DESC{$limitSql}
SQL;

        $statement = $this->pdo->prepare($sql);
        $this->bindParams($statement, $params);

        if ($limit !== null) {
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        }

        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, full_name, cpf, status, source_batch_id, notes, created_at, updated_at
             FROM recruit_candidates
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $candidate = $statement->fetch();

        if ($candidate === false) {
            return null;
        }

        $candidate['contacts'] = $this->fetchAllByCandidate(
            'SELECT id, type, value, is_primary, created_at
             FROM recruit_candidate_contacts
             WHERE candidate_id = :candidate_id
             ORDER BY is_primary DESC, id ASC',
            $id
        );

        $candidate['skills'] = $this->fetchAllByCandidate(
            'SELECT id, skill, level, created_at
             FROM recruit_candidate_skills
             WHERE candidate_id = :candidate_id
             ORDER BY skill ASC, id ASC',
            $id
        );

        $candidate['addresses'] = $this->fetchAllByCandidate(
            'SELECT id, state, city, region, created_at
             FROM recruit_candidate_addresses
             WHERE candidate_id = :candidate_id
             ORDER BY id ASC',
            $id
        );

        $candidate['status_history'] = $this->fetchAllByCandidate(
            'SELECT id, from_status, to_status, changed_by, reason, created_at
             FROM recruit_candidate_status_history
             WHERE candidate_id = :candidate_id
             ORDER BY created_at DESC, id DESC',
            $id
        );

        return $candidate;
    }

    public function updateStatus(int $id, string $newStatus, string $operator): bool
    {
        if (!in_array($newStatus, self::VALID_STATUSES, true)) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            $statement = $this->pdo->prepare(
                'SELECT status
                 FROM recruit_candidates
                 WHERE id = :id
                 FOR UPDATE'
            );
            $statement->execute(['id' => $id]);
            $currentStatus = $statement->fetchColumn();

            if ($currentStatus === false) {
                $this->pdo->rollBack();

                return false;
            }

            if ($currentStatus === $newStatus) {
                $this->pdo->commit();

                return true;
            }

            $updateStatement = $this->pdo->prepare(
                'UPDATE recruit_candidates
                 SET status = :status,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $updateStatement->execute([
                'id' => $id,
                'status' => $newStatus,
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
                'candidate_id' => $id,
                'from_status' => (string) $currentStatus,
                'to_status' => $newStatus,
                'changed_by' => $operator,
                'reason' => null,
            ]);

            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            return false;
        }
    }

    /**
     * @param array{skill?:string,status?:string,state?:string,search?:string} $filters
     * @return array{0:string,1:array<string, mixed>}
     */
    private function buildFilterSql(array $filters, bool $requireCampaignContact = false): array
    {
        $conditions = [];
        $params = [];

        if ($requireCampaignContact) {
            $conditions[] = 'EXISTS (
                SELECT 1
                FROM recruit_candidate_contacts rcc_contact
                WHERE rcc_contact.candidate_id = c.id
                  AND rcc_contact.type IN (\'whatsapp\', \'phone\')
                  AND TRIM(rcc_contact.value) <> \'\'
            )';
            $conditions[] = 'NOT EXISTS (
                SELECT 1
                FROM recruit_opt_outs roo
                WHERE roo.candidate_id = c.id
            )';
        }

        if (isset($filters['skill']) && trim($filters['skill']) !== '') {
            $conditions[] = 'EXISTS (
                SELECT 1
                FROM recruit_candidate_skills rcs
                WHERE rcs.candidate_id = c.id
                  AND UPPER(rcs.skill) = :skill
            )';
            $params[':skill'] = mb_strtoupper(trim($filters['skill']));
        }

        if (isset($filters['status']) && in_array($filters['status'], self::VALID_STATUSES, true)) {
            $conditions[] = 'c.status = :status';
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['state']) && trim($filters['state']) !== '') {
            $conditions[] = 'EXISTS (
                SELECT 1
                FROM recruit_candidate_addresses rca
                WHERE rca.candidate_id = c.id
                  AND rca.state = :state
            )';
            $params[':state'] = mb_strtoupper(trim($filters['state']));
        }

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $conditions[] = '(
                c.full_name LIKE :search_name
                OR c.cpf LIKE :search_cpf
                OR EXISTS (
                    SELECT 1
                    FROM recruit_candidate_contacts rcc
                    WHERE rcc.candidate_id = c.id
                      AND rcc.value LIKE :search_contact
                )
            )';
            $searchTerm = '%' . trim($filters['search']) . '%';
            $params[':search_name'] = $searchTerm;
            $params[':search_cpf'] = $searchTerm;
            $params[':search_contact'] = $searchTerm;
        }

        if ($conditions === []) {
            return ['', $params];
        }

        return [' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function bindParams(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllByCandidate(string $sql, int $candidateId): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['candidate_id' => $candidateId]);

        return $statement->fetchAll();
    }
}
