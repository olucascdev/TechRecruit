<?php

declare(strict_types=1);

namespace TechRecruit\Models;

use PDO;
use TechRecruit\Database;

final class PortalModel
{
    /** @var list<string> */
    public const VALID_STATUSES = [
        'draft',
        'link_sent',
        'in_progress',
        'submitted',
        'under_review',
        'correction_requested',
        'approved',
        'rejected',
        'expired',
    ];

    /**
     * @var array<string, array{label:string,required:bool}>
     */
    public const CHECKLIST_ITEMS = [
        'documento_identidade' => [
            'label' => 'Documento de identidade',
            'required' => true,
        ],
        'comprovante_residencia' => [
            'label' => 'Comprovante de residência',
            'required' => true,
        ],
        'cartao_mei' => [
            'label' => 'Cartao CNPJ / comprovante MEI',
            'required' => true,
        ],
        'comprovante_bancario' => [
            'label' => 'Comprovante bancario',
            'required' => true,
        ],
        'aso' => [
            'label' => 'ASO válido',
            'required' => true,
        ],
        'nr10' => [
            'label' => 'Certificado NR10',
            'required' => true,
        ],
        'nr35' => [
            'label' => 'Certificado NR35',
            'required' => true,
        ],
        'curriculo' => [
            'label' => 'Currículo',
            'required' => false,
        ],
        'certificado_tecnico' => [
            'label' => 'Certificado técnico adicional',
            'required' => false,
        ],
        'outro' => [
            'label' => 'Outro anexo',
            'required' => false,
        ],
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCandidateId(int $candidateId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                portal.id,
                portal.candidate_id,
                portal.access_token,
                portal.status,
                portal.terms_version,
                portal.terms_accepted,
                portal.terms_accepted_at,
                portal.last_accessed_at,
                portal.submitted_at,
                portal.created_by,
                portal.created_at,
                portal.updated_at,
                candidate.full_name AS candidate_full_name,
                candidate.cpf AS candidate_cpf,
                candidate.status AS candidate_status,
                contact_data.whatsapp,
                contact_data.email,
                address_data.state,
                address_data.city,
                address_data.region
             FROM recruit_candidate_portals portal
             INNER JOIN recruit_candidates candidate ON candidate.id = portal.candidate_id
             LEFT JOIN (
                SELECT
                    candidate_id,
                    MAX(CASE WHEN type = 'whatsapp' AND is_primary = 1 THEN value END) AS whatsapp,
                    MAX(CASE WHEN type = 'email' AND is_primary = 1 THEN value END) AS email
                FROM recruit_candidate_contacts
                GROUP BY candidate_id
             ) AS contact_data ON contact_data.candidate_id = portal.candidate_id
             LEFT JOIN (
                SELECT
                    candidate_id,
                    MAX(state) AS state,
                    MAX(city) AS city,
                    MAX(region) AS region
                FROM recruit_candidate_addresses
                GROUP BY candidate_id
             ) AS address_data ON address_data.candidate_id = portal.candidate_id
             WHERE portal.candidate_id = :candidate_id
             LIMIT 1"
        );
        $statement->execute(['candidate_id' => $candidateId]);
        $portal = $statement->fetch();

        if ($portal === false) {
            return null;
        }

        return $this->hydratePortal($portal);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                portal.id,
                portal.candidate_id,
                portal.access_token,
                portal.status,
                portal.terms_version,
                portal.terms_accepted,
                portal.terms_accepted_at,
                portal.last_accessed_at,
                portal.submitted_at,
                portal.created_by,
                portal.created_at,
                portal.updated_at,
                candidate.full_name AS candidate_full_name,
                candidate.cpf AS candidate_cpf,
                candidate.status AS candidate_status,
                contact_data.whatsapp,
                contact_data.email,
                address_data.state,
                address_data.city,
                address_data.region
             FROM recruit_candidate_portals portal
             INNER JOIN recruit_candidates candidate ON candidate.id = portal.candidate_id
             LEFT JOIN (
                SELECT
                    candidate_id,
                    MAX(CASE WHEN type = 'whatsapp' AND is_primary = 1 THEN value END) AS whatsapp,
                    MAX(CASE WHEN type = 'email' AND is_primary = 1 THEN value END) AS email
                FROM recruit_candidate_contacts
                GROUP BY candidate_id
             ) AS contact_data ON contact_data.candidate_id = portal.candidate_id
             LEFT JOIN (
                SELECT
                    candidate_id,
                    MAX(state) AS state,
                    MAX(city) AS city,
                    MAX(region) AS region
                FROM recruit_candidate_addresses
                GROUP BY candidate_id
             ) AS address_data ON address_data.candidate_id = portal.candidate_id
             WHERE portal.access_token = :access_token
             LIMIT 1"
        );
        $statement->execute(['access_token' => $token]);
        $portal = $statement->fetch();

        if ($portal === false) {
            return null;
        }

        return $this->hydratePortal($portal);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $portalId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT
                portal.id,
                portal.candidate_id,
                portal.access_token,
                portal.status,
                portal.terms_version,
                portal.terms_accepted,
                portal.terms_accepted_at,
                portal.last_accessed_at,
                portal.submitted_at,
                portal.created_by,
                portal.created_at,
                portal.updated_at,
                candidate.full_name AS candidate_full_name,
                candidate.cpf AS candidate_cpf,
                candidate.status AS candidate_status,
                contact_data.whatsapp,
                contact_data.email,
                address_data.state,
                address_data.city,
                address_data.region
             FROM recruit_candidate_portals portal
             INNER JOIN recruit_candidates candidate ON candidate.id = portal.candidate_id
             LEFT JOIN (
                SELECT
                    candidate_id,
                    MAX(CASE WHEN type = 'whatsapp' AND is_primary = 1 THEN value END) AS whatsapp,
                    MAX(CASE WHEN type = 'email' AND is_primary = 1 THEN value END) AS email
                FROM recruit_candidate_contacts
                GROUP BY candidate_id
             ) AS contact_data ON contact_data.candidate_id = portal.candidate_id
             LEFT JOIN (
                SELECT
                    candidate_id,
                    MAX(state) AS state,
                    MAX(city) AS city,
                    MAX(region) AS region
                FROM recruit_candidate_addresses
                GROUP BY candidate_id
             ) AS address_data ON address_data.candidate_id = portal.candidate_id
             WHERE portal.id = :id
             LIMIT 1"
        );
        $statement->execute(['id' => $portalId]);
        $portal = $statement->fetch();

        if ($portal === false) {
            return null;
        }

        return $this->hydratePortal($portal);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDocumentById(int $documentId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, portal_id, candidate_id, document_type, original_name, stored_path, mime_type, file_size, review_status, uploaded_at
             FROM recruit_candidate_documents
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $documentId]);
        $document = $statement->fetch();

        return $document === false ? null : $document;
    }

    /**
     * @param array<string, mixed> $portal
     * @return array<string, mixed>
     */
    private function hydratePortal(array $portal): array
    {
        $profileStatement = $this->pdo->prepare(
            'SELECT full_name, cpf, cnpj, pix_key, birth_date, rg, company_name, issues_invoice, full_address, equipment_list, transport_modes, availability_days, service_cities, whatsapp, secondary_phone, email, state, city, region, service_region, bank_name, bank_agency, bank_account, bank_holder_name, bank_holder_doc, availability, experience_summary, notes, created_at, updated_at
             FROM recruit_candidate_portal_profiles
             WHERE portal_id = :portal_id
             LIMIT 1'
        );
        $profileStatement->execute(['portal_id' => $portal['id']]);
        $profile = $profileStatement->fetch();
        $portal['profile'] = $profile === false ? null : $profile;

        $documentsStatement = $this->pdo->prepare(
            'SELECT id, portal_id, candidate_id, document_type, original_name, stored_path, mime_type, file_size, review_status, uploaded_at
             FROM recruit_candidate_documents
             WHERE portal_id = :portal_id
             ORDER BY uploaded_at DESC, id DESC'
        );
        $documentsStatement->execute(['portal_id' => $portal['id']]);
        $documents = $documentsStatement->fetchAll();
        $portal['documents'] = $documents;
        $portal['checklist'] = $this->buildChecklist($documents);

        return $portal;
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     * @return array<int, array<string, mixed>>
     */
    private function buildChecklist(array $documents): array
    {
        $counts = [];

        foreach ($documents as $document) {
            $type = (string) ($document['document_type'] ?? '');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        $checklist = [];

        foreach (self::CHECKLIST_ITEMS as $key => $item) {
            $uploadedCount = (int) ($counts[$key] ?? 0);
            $checklist[] = [
                'key' => $key,
                'label' => $item['label'],
                'required' => $item['required'],
                'uploaded_count' => $uploadedCount,
                'received' => $uploadedCount > 0,
            ];
        }

        return $checklist;
    }
}
