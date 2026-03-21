<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use InvalidArgumentException;
use PDO;
use TechRecruit\Database;
use TechRecruit\Models\CandidateModel;
use TechRecruit\Models\PortalModel;
use Throwable;

final class PortalService
{
    public const TERMS_VERSION = 'portal-v1';

    private PDO $pdo;

    private CandidateModel $candidateModel;

    private PortalModel $portalModel;

    private WhatsGwClient $whatsGwClient;

    public function __construct(
        ?CandidateModel $candidateModel = null,
        ?PortalModel $portalModel = null,
        ?WhatsGwClient $whatsGwClient = null,
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo ?? Database::connect();
        $this->candidateModel = $candidateModel ?? new CandidateModel($this->pdo);
        $this->portalModel = $portalModel ?? new PortalModel($this->pdo);
        $this->whatsGwClient = $whatsGwClient ?? new WhatsGwClient();
    }

    /**
     * @return array<string, mixed>
     */
    public function generatePortalForCandidate(int $candidateId, string $operator): array
    {
        $candidate = $this->candidateModel->findById($candidateId);

        if ($candidate === null) {
            throw new InvalidArgumentException('Candidato não encontrado.');
        }

        $token = bin2hex(random_bytes(32));

        $this->pdo->beginTransaction();

        try {
            $existingPortalStatement = $this->pdo->prepare(
                'SELECT id, status
                 FROM recruit_candidate_portals
                 WHERE candidate_id = :candidate_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingPortalStatement->execute(['candidate_id' => $candidateId]);
            $existingPortal = $existingPortalStatement->fetch();

            if ($existingPortal === false) {
                $createStatement = $this->pdo->prepare(
                    'INSERT INTO recruit_candidate_portals (
                        candidate_id,
                        access_token,
                        status,
                        terms_version,
                        created_by
                     ) VALUES (
                        :candidate_id,
                        :access_token,
                        :status,
                        :terms_version,
                        :created_by
                     )'
                );
                $createStatement->execute([
                    'candidate_id' => $candidateId,
                    'access_token' => $token,
                    'status' => 'link_sent',
                    'terms_version' => self::TERMS_VERSION,
                    'created_by' => $operator,
                ]);
            } else {
                $updateStatement = $this->pdo->prepare(
                    'UPDATE recruit_candidate_portals
                     SET access_token = :access_token,
                         status = :status,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE candidate_id = :candidate_id'
                );
                $nextStatus = in_array($existingPortal['status'], ['approved', 'expired'], true)
                    ? (string) $existingPortal['status']
                    : 'link_sent';
                $updateStatement->execute([
                    'access_token' => $token,
                    'status' => $nextStatus,
                    'candidate_id' => $candidateId,
                ]);
            }

            if (
                in_array((string) $candidate['status'], [
                    'imported',
                    'queued',
                    'message_sent',
                    'responded',
                    'interested',
                ], true)
            ) {
                $this->applyCandidateStatus(
                    $candidateId,
                    'awaiting_docs',
                    $operator,
                    'Link do portal de cadastro/documentos gerado.'
                );
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        $portal = $this->portalModel->findByCandidateId($candidateId);

        if ($portal === null) {
            throw new InvalidArgumentException('Não foi possível carregar o portal do candidato.');
        }

        return $portal;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendPortalLink(int $candidateId, string $portalUrl): array
    {
        $candidate = $this->candidateModel->findById($candidateId);

        if ($candidate === null) {
            return [
                'success' => false,
                'error' => 'Candidato não encontrado para envio do portal.',
            ];
        }

        $destinationContact = $this->resolveCandidateDestinationContact($candidate);

        if ($destinationContact === null) {
            return [
                'success' => false,
                'error' => 'Nenhum WhatsApp ou telefone valido foi encontrado para enviar o link do portal.',
            ];
        }

        $messageBody = $this->buildPortalLinkMessage((string) ($candidate['full_name'] ?? ''), $portalUrl);
        $messageCustomId = sprintf('portal-link-%d-%d', $candidateId, time());

        try {
            $result = $this->whatsGwClient->sendTextMessage($destinationContact, $messageBody, [
                'message_custom_id' => $messageCustomId,
                'check_status' => 1,
            ]);

            if (!($result['success'] ?? false)) {
                $rawBody = trim((string) ($result['raw_body'] ?? ''));
                $httpStatus = (int) ($result['http_status'] ?? 0);

                throw new InvalidArgumentException(
                    sprintf(
                        'WhatsGW retornou falha no envio do portal. HTTP %d%s',
                        $httpStatus,
                        $rawBody !== '' ? ': ' . $rawBody : ''
                    )
                );
            }

            return [
                'success' => true,
                'destination_contact' => $destinationContact,
                'message_body' => $messageBody,
                'provider_message_custom_id' => $result['message_custom_id'] ?? $messageCustomId,
                'provider_message_id' => $result['provider_message_id'] ?? null,
                'provider_waid' => $result['provider_waid'] ?? null,
                'simulated' => (bool) ($result['simulated'] ?? false),
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'destination_contact' => $destinationContact,
                'message_body' => $messageBody,
                'provider_message_custom_id' => $messageCustomId,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $formData
     * @param array<string, mixed> $files
     */
    public function submitPortal(string $token, array $formData, array $files): void
    {
        $portal = $this->portalModel->findByToken($token);

        if ($portal === null) {
            throw new InvalidArgumentException('Link do portal inválido.');
        }

        if (in_array((string) $portal['status'], ['approved', 'expired'], true)) {
            throw new InvalidArgumentException('Este portal não aceita mais alterações.');
        }

        $profileData = $this->validateProfileData($formData, $portal);
        $termsAccepted = isset($formData['terms_accepted']) && (string) $formData['terms_accepted'] === '1';

        if (!$termsAccepted) {
            throw new InvalidArgumentException('Você precisa aceitar os termos para concluir o cadastro.');
        }

        $uploadedDocuments = [];

        try {
            $uploadedDocuments = $this->storeUploadedDocuments((int) $portal['id'], (int) $portal['candidate_id'], $files);
            $existingChecklist = is_array($portal['checklist'] ?? null) ? $portal['checklist'] : [];

            foreach ($existingChecklist as $item) {
                if (!($item['required'] ?? false)) {
                    continue;
                }

                $hasExisting = (bool) ($item['received'] ?? false);
                $hasNew = array_key_exists((string) $item['key'], $uploadedDocuments);

                if (!$hasExisting && !$hasNew) {
                    throw new InvalidArgumentException(
                        sprintf('Envie o documento obrigatório: %s.', $item['label'] ?? $item['key'])
                    );
                }
            }

            $this->pdo->beginTransaction();

            $profileStatement = $this->pdo->prepare(
                'INSERT INTO recruit_candidate_portal_profiles (
                    portal_id,
                    candidate_id,
                    full_name,
                    cpf,
                    cnpj,
                    pix_key,
                    birth_date,
                    whatsapp,
                    email,
                    state,
                    city,
                    region,
                    availability,
                    experience_summary,
                    notes
                 ) VALUES (
                    :portal_id,
                    :candidate_id,
                    :full_name,
                    :cpf,
                    :cnpj,
                    :pix_key,
                    :birth_date,
                    :whatsapp,
                    :email,
                    :state,
                    :city,
                    :region,
                    :availability,
                    :experience_summary,
                    :notes
                 )
                 ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    cpf = VALUES(cpf),
                    cnpj = VALUES(cnpj),
                    pix_key = VALUES(pix_key),
                    birth_date = VALUES(birth_date),
                    whatsapp = VALUES(whatsapp),
                    email = VALUES(email),
                    state = VALUES(state),
                    city = VALUES(city),
                    region = VALUES(region),
                    availability = VALUES(availability),
                    experience_summary = VALUES(experience_summary),
                    notes = VALUES(notes),
                    updated_at = CURRENT_TIMESTAMP'
            );
            $profileStatement->execute([
                'portal_id' => $portal['id'],
                'candidate_id' => $portal['candidate_id'],
                'full_name' => $profileData['full_name'],
                'cpf' => $profileData['cpf'],
                'cnpj' => $profileData['cnpj'],
                'pix_key' => $profileData['pix_key'],
                'birth_date' => $profileData['birth_date'],
                'whatsapp' => $profileData['whatsapp'],
                'email' => $profileData['email'],
                'state' => $profileData['state'],
                'city' => $profileData['city'],
                'region' => $profileData['region'],
                'availability' => $profileData['availability'],
                'experience_summary' => $profileData['experience_summary'],
                'notes' => $profileData['notes'],
            ]);

            $portalStatement = $this->pdo->prepare(
                'UPDATE recruit_candidate_portals
                 SET status = :status,
                     terms_accepted = 1,
                     terms_version = :terms_version,
                     terms_accepted_at = CURRENT_TIMESTAMP,
                     submitted_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $portalStatement->execute([
                'status' => 'submitted',
                'terms_version' => self::TERMS_VERSION,
                'id' => $portal['id'],
            ]);

            if ($uploadedDocuments !== []) {
                $documentStatement = $this->pdo->prepare(
                    'INSERT INTO recruit_candidate_documents (
                        portal_id,
                        candidate_id,
                        document_type,
                        original_name,
                        stored_path,
                        mime_type,
                        file_size,
                        review_status
                     ) VALUES (
                        :portal_id,
                        :candidate_id,
                        :document_type,
                        :original_name,
                        :stored_path,
                        :mime_type,
                        :file_size,
                        :review_status
                     )'
                );

                foreach ($uploadedDocuments as $document) {
                    $documentStatement->execute([
                        'portal_id' => $portal['id'],
                        'candidate_id' => $portal['candidate_id'],
                        'document_type' => $document['document_type'],
                        'original_name' => $document['original_name'],
                        'stored_path' => $document['stored_path'],
                        'mime_type' => $document['mime_type'],
                        'file_size' => $document['file_size'],
                        'review_status' => 'pending',
                    ]);
                }
            }

            $this->syncCandidateBaseData((int) $portal['candidate_id'], $profileData);
            $this->applyCandidateStatus(
                (int) $portal['candidate_id'],
                'docs_sent',
                'candidate_portal',
                'Cadastro e documentos enviados pelo portal.'
            );

            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->cleanupStoredDocuments($uploadedDocuments);

            throw $exception;
        }
    }

    public function updatePortalStatusForCandidate(int $candidateId, string $newStatus, string $operator): void
    {
        if (!in_array($newStatus, PortalModel::VALID_STATUSES, true)) {
            throw new InvalidArgumentException('Status do portal inválido.');
        }

        $portal = $this->portalModel->findByCandidateId($candidateId);

        if ($portal === null) {
            throw new InvalidArgumentException('Portal do candidato não encontrado.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE recruit_candidate_portals
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE candidate_id = :candidate_id'
        );
        $statement->execute([
            'status' => $newStatus,
            'candidate_id' => $candidateId,
        ]);

        $candidateStatusMap = [
            'under_review' => 'under_review',
            'correction_requested' => 'awaiting_docs',
            'approved' => 'approved',
            'rejected' => 'rejected',
            'submitted' => 'docs_sent',
            'in_progress' => 'awaiting_docs',
            'link_sent' => 'awaiting_docs',
        ];

        if (isset($candidateStatusMap[$newStatus])) {
            $this->applyCandidateStatus(
                $candidateId,
                $candidateStatusMap[$newStatus],
                $operator,
                sprintf('Status do portal atualizado para %s.', $newStatus)
            );
        }
    }

    public function deleteDocument(int $documentId): int
    {
        $document = $this->portalModel->findDocumentById($documentId);

        if ($document === null) {
            throw new InvalidArgumentException('Documento não encontrado.');
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM recruit_candidate_documents
             WHERE id = :id'
        );
        $statement->execute(['id' => $documentId]);

        $path = (string) ($document['stored_path'] ?? '');

        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }

        return (int) ($document['candidate_id'] ?? 0);
    }

    public function markPortalAccessed(string $token): void
    {
        $portal = $this->portalModel->findByToken($token);

        if ($portal === null) {
            return;
        }

        $nextStatus = in_array((string) $portal['status'], ['draft', 'link_sent'], true)
            ? 'in_progress'
            : (string) $portal['status'];

        $statement = $this->pdo->prepare(
            'UPDATE recruit_candidate_portals
             SET status = :status,
                 last_accessed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'status' => $nextStatus,
            'id' => $portal['id'],
        ]);
    }

    /**
     * @param array<string, mixed> $formData
     * @param array<string, mixed> $portal
     * @return array<string, string|null>
     */
    private function validateProfileData(array $formData, array $portal): array
    {
        $fullName = trim((string) ($formData['full_name'] ?? ($portal['profile']['full_name'] ?? $portal['candidate_full_name'] ?? '')));
        $cpf = trim((string) ($formData['cpf'] ?? ($portal['profile']['cpf'] ?? $portal['candidate_cpf'] ?? '')));
        $cnpj = trim((string) ($formData['cnpj'] ?? ($portal['profile']['cnpj'] ?? '')));
        $pixKey = trim((string) ($formData['pix_key'] ?? ($portal['profile']['pix_key'] ?? '')));
        $birthDate = trim((string) ($formData['birth_date'] ?? ($portal['profile']['birth_date'] ?? '')));
        $whatsapp = trim((string) ($formData['whatsapp'] ?? ($portal['profile']['whatsapp'] ?? $portal['whatsapp'] ?? '')));
        $email = trim((string) ($formData['email'] ?? ($portal['profile']['email'] ?? $portal['email'] ?? '')));
        $state = mb_strtoupper(trim((string) ($formData['state'] ?? ($portal['profile']['state'] ?? $portal['state'] ?? ''))));
        $city = trim((string) ($formData['city'] ?? ($portal['profile']['city'] ?? $portal['city'] ?? '')));
        $region = trim((string) ($formData['region'] ?? ($portal['profile']['region'] ?? $portal['region'] ?? '')));
        $availability = trim((string) ($formData['availability'] ?? ($portal['profile']['availability'] ?? '')));
        $experienceSummary = trim((string) ($formData['experience_summary'] ?? ($portal['profile']['experience_summary'] ?? '')));
        $notes = trim((string) ($formData['notes'] ?? ($portal['profile']['notes'] ?? '')));

        if ($fullName === '') {
            throw new InvalidArgumentException('Informe seu nome completo.');
        }

        if ($whatsapp === '' && $email === '') {
            throw new InvalidArgumentException('Informe ao menos um WhatsApp ou e-mail para contato.');
        }

        if ($state !== '' && strlen($state) !== 2) {
            throw new InvalidArgumentException('Informe a UF com 2 letras.');
        }

        if ($city === '') {
            throw new InvalidArgumentException('Informe sua cidade.');
        }

        if ($availability === '') {
            throw new InvalidArgumentException('Informe sua disponibilidade.');
        }

        if ($cnpj === '') {
            throw new InvalidArgumentException('Informe o CNPJ / MEI.');
        }

        if ($pixKey === '') {
            throw new InvalidArgumentException('Informe a chave Pix.');
        }

        if ($experienceSummary === '') {
            throw new InvalidArgumentException('Descreva sua experiência resumida.');
        }

        return [
            'full_name' => $fullName,
            'cpf' => $cpf === '' ? null : $cpf,
            'cnpj' => $cnpj === '' ? null : $cnpj,
            'pix_key' => $pixKey === '' ? null : $pixKey,
            'birth_date' => $birthDate === '' ? null : $birthDate,
            'whatsapp' => $whatsapp === '' ? null : $whatsapp,
            'email' => $email === '' ? null : $email,
            'state' => $state === '' ? null : $state,
            'city' => $city === '' ? null : $city,
            'region' => $region === '' ? null : $region,
            'availability' => $availability === '' ? null : $availability,
            'experience_summary' => $experienceSummary === '' ? null : $experienceSummary,
            'notes' => $notes === '' ? null : $notes,
        ];
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, array<string, mixed>>
     */
    private function storeUploadedDocuments(int $portalId, int $candidateId, array $files): array
    {
        $storedDocuments = [];
        $directory = dirname(__DIR__, 2) . '/storage/portal-documents/' . $candidateId;

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        foreach (PortalModel::CHECKLIST_ITEMS as $documentType => $item) {
            $file = $files[$documentType] ?? null;

            if (!is_array($file)) {
                continue;
            }

            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException(
                    sprintf('Falha no upload do arquivo "%s".', $item['label'])
                );
            }

            $tmpName = (string) ($file['tmp_name'] ?? '');
            $originalName = trim((string) ($file['name'] ?? 'arquivo'));
            $fileSize = (int) ($file['size'] ?? 0);
            $mimeType = trim((string) ($file['type'] ?? ''));

            if ($fileSize < 1) {
                throw new InvalidArgumentException(
                    sprintf('O arquivo "%s" esta vazio.', $item['label'])
                );
            }

            if ($fileSize > 8 * 1024 * 1024) {
                throw new InvalidArgumentException(
                    sprintf('O arquivo "%s" excede o limite de 8MB.', $item['label'])
                );
            }

            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
                throw new InvalidArgumentException(
                    sprintf('O arquivo "%s" precisa ser PDF, JPG, JPEG ou PNG.', $item['label'])
                );
            }

            $canMove = is_uploaded_file($tmpName) || (PHP_SAPI === 'cli' && is_file($tmpName));

            if (!$canMove) {
                throw new InvalidArgumentException(
                    sprintf('Arquivo temporário inválido para "%s".', $item['label'])
                );
            }

            $safeOriginalName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($originalName)) ?: 'documento.' . $extension;
            $storedPath = sprintf(
                '%s/%s_%s_%s',
                $directory,
                $portalId,
                bin2hex(random_bytes(4)),
                $safeOriginalName
            );

            $moved = is_uploaded_file($tmpName)
                ? move_uploaded_file($tmpName, $storedPath)
                : copy($tmpName, $storedPath);

            if (!$moved) {
                throw new InvalidArgumentException(
                    sprintf('Não foi possível salvar o documento "%s".', $item['label'])
                );
            }

            $storedDocuments[$documentType] = [
                'document_type' => $documentType,
                'original_name' => $originalName,
                'stored_path' => $storedPath,
                'mime_type' => $mimeType !== '' ? $mimeType : null,
                'file_size' => $fileSize,
            ];
        }

        return $storedDocuments;
    }

    /**
     * @param array<string, string|null> $profileData
     */
    private function syncCandidateBaseData(int $candidateId, array $profileData): void
    {
        $candidateStatement = $this->pdo->prepare(
            'UPDATE recruit_candidates
             SET full_name = :full_name,
                 cpf = :cpf,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $candidateStatement->execute([
            'full_name' => $profileData['full_name'],
            'cpf' => $profileData['cpf'],
            'id' => $candidateId,
        ]);

        $this->upsertContact($candidateId, 'whatsapp', $profileData['whatsapp']);
        $this->upsertContact($candidateId, 'email', $profileData['email']);
        $this->upsertAddress(
            $candidateId,
            $profileData['state'],
            $profileData['city'],
            $profileData['region']
        );
    }

    private function upsertContact(int $candidateId, string $type, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM recruit_candidate_contacts
             WHERE candidate_id = :candidate_id
               AND type = :type
             ORDER BY is_primary DESC, id ASC
             LIMIT 1'
        );
        $statement->execute([
            'candidate_id' => $candidateId,
            'type' => $type,
        ]);
        $contactId = $statement->fetchColumn();

        if ($contactId === false) {
            $insertStatement = $this->pdo->prepare(
                'INSERT INTO recruit_candidate_contacts (candidate_id, type, value, is_primary)
                 VALUES (:candidate_id, :type, :value, :is_primary)'
            );
            $insertStatement->execute([
                'candidate_id' => $candidateId,
                'type' => $type,
                'value' => $value,
                'is_primary' => 1,
            ]);

            return;
        }

        $updateStatement = $this->pdo->prepare(
            'UPDATE recruit_candidate_contacts
             SET value = :value,
                 is_primary = 1
             WHERE id = :id'
        );
        $updateStatement->execute([
            'value' => $value,
            'id' => $contactId,
        ]);
    }

    private function upsertAddress(int $candidateId, ?string $state, ?string $city, ?string $region): void
    {
        if ($state === null || $city === null || trim($state) === '' || trim($city) === '') {
            return;
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM recruit_candidate_addresses
             WHERE candidate_id = :candidate_id
             ORDER BY id ASC
             LIMIT 1'
        );
        $statement->execute(['candidate_id' => $candidateId]);
        $addressId = $statement->fetchColumn();

        if ($addressId === false) {
            $insertStatement = $this->pdo->prepare(
                'INSERT INTO recruit_candidate_addresses (candidate_id, state, city, region)
                 VALUES (:candidate_id, :state, :city, :region)'
            );
            $insertStatement->execute([
                'candidate_id' => $candidateId,
                'state' => $state,
                'city' => $city,
                'region' => $region,
            ]);

            return;
        }

        $updateStatement = $this->pdo->prepare(
            'UPDATE recruit_candidate_addresses
             SET state = :state,
                 city = :city,
                 region = :region
             WHERE id = :id'
        );
        $updateStatement->execute([
            'state' => $state,
            'city' => $city,
            'region' => $region,
            'id' => $addressId,
        ]);
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

                if ($digits !== null && $digits !== '') {
                    return $digits;
                }
            }
        }

        return null;
    }

    private function buildPortalLinkMessage(string $fullName, string $portalUrl): string
    {
        $firstName = trim((string) strtok(trim($fullName), ' '));

        if ($firstName === '') {
            $firstName = 'Técnico';
        }

        return trim(sprintf(
            "Olá, %s.\n\nSeu perfil foi pré-aprovado na W13 Tecnologia.\nPara finalizar seu cadastro, acesse o portal abaixo e envie os dados e documentos obrigatórios:\n\n%s\n\nDocumentos esperados:\n- Documento de identidade\n- Comprovante de residência\n- CNPJ / MEI\n- ASO\n- NR10\n- NR35\n\nAssim que a validação for concluída, sua liberação operacional segue para a próxima etapa.\n\nEquipe W13 Tecnologia",
            $firstName,
            $portalUrl
        ));
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

    /**
     * @param array<string, array<string, mixed>> $documents
     */
    private function cleanupStoredDocuments(array $documents): void
    {
        foreach ($documents as $document) {
            $path = (string) ($document['stored_path'] ?? '');

            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
