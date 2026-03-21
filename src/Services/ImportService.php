<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use TechRecruit\Database;
use Throwable;

final class ImportService
{
    /** @var array<string, list<string>> */
    private const HEADER_ALIASES = [
        'full_name' => ['nome', 'name', 'full_name'],
        'cpf' => ['cpf', 'documento', 'cpf/cnpj', 'cpf cnpj'],
        'phone' => ['telefone', 'phone', 'celular', 'tel'],
        'whatsapp' => ['whatsapp', 'wpp', 'zap'],
        'email' => ['email', 'e-mail'],
        'skill' => ['skill', 'habilidade', 'perfil'],
        'level' => ['nivel', 'level', 'seniority'],
        'state' => ['estado', 'uf', 'state', 'endereco estado', 'endereço estado'],
        'city' => ['cidade', 'city', 'endereco cidade', 'endereço cidade'],
    ];

    /** @var array<string, string> */
    private const LEVEL_ALIASES = [
        'junior' => 'junior',
        'jr' => 'junior',
        'júnior' => 'junior',
        'pleno' => 'pleno',
        'pl' => 'pleno',
        'senior' => 'senior',
        'sr' => 'senior',
        'sênior' => 'senior',
    ];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
    }

    /**
     * @return array{batch_id:int,total:int,imported:int,errors:int,duplicates:int}
     */
    public function run(string $filepath, string $operator): array
    {
        if (!is_file($filepath) || !is_readable($filepath)) {
            throw new RuntimeException('Arquivo de importação não encontrado ou sem permissão de leitura.');
        }

        $batchId = $this->createBatch(basename($filepath), $operator);
        $total = 0;
        $imported = 0;
        $errors = 0;
        $duplicates = 0;

        try {
            $worksheet = $this->loadWorksheet($filepath);
            [$headerRowNumber, $headers, $fieldMap, $highestColumn] = $this->resolveHeaderMap($worksheet);

            if ($headerRowNumber === 0 || $fieldMap === []) {
                throw new RuntimeException('Não foi possível identificar uma linha de cabeçalho válida na planilha.');
            }

            $highestRow = $worksheet->getHighestDataRow();

            for ($rowNumber = $headerRowNumber + 1; $rowNumber <= $highestRow; $rowNumber++) {
                $rowValues = $this->readRow($worksheet, $rowNumber, $highestColumn);
                $rawSource = $this->buildRawSource($headers, $rowValues);

                if ($this->isRowEmpty($rawSource)) {
                    continue;
                }

                $total++;
                $rawPayload = $this->encodeJson(['source' => $rawSource]);

                try {
                    $mappedRow = $this->extractMappedRow($rowValues, $fieldMap);
                    $normalizedRow = $this->normalizeMappedRow($mappedRow);
                    $this->validateContactFields($mappedRow, $normalizedRow);
                    $rawPayload = $this->encodeJson([
                        'source' => $rawSource,
                        'mapped' => $normalizedRow,
                    ]);

                    if ($normalizedRow['full_name'] === '') {
                        throw new RuntimeException('O campo obrigatório full_name não foi informado.');
                    }

                    $duplicateId = $this->findDuplicateCandidateId($normalizedRow);

                    if ($duplicateId !== null) {
                        $this->insertImportRow(
                            $batchId,
                            $rowNumber,
                            $rawPayload,
                            'duplicate',
                            'Candidato duplicado encontrado por CPF, telefone principal ou e-mail.',
                            $duplicateId
                        );
                        $duplicates++;

                        continue;
                    }

                    $this->pdo->beginTransaction();

                    $candidateId = $this->insertCandidate($normalizedRow, $batchId);
                    $this->insertInitialStatusHistory($candidateId, $operator);
                    $this->insertContacts($candidateId, $normalizedRow);
                    $this->insertSkills($candidateId, $normalizedRow['skill'], $normalizedRow['level']);
                    $this->insertAddress($candidateId, $normalizedRow['state'], $normalizedRow['city']);
                    $this->insertImportRow($batchId, $rowNumber, $rawPayload, 'ok', null, $candidateId);

                    $this->pdo->commit();
                    $imported++;
                } catch (Throwable $exception) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }

                    $this->insertImportRow(
                        $batchId,
                        $rowNumber,
                        $rawPayload,
                        'error',
                        $exception->getMessage(),
                        null
                    );
                    $errors++;
                }
            }

            $this->updateBatch($batchId, $total, $imported, $errors, 'done');

            return [
                'batch_id' => $batchId,
                'total' => $total,
                'imported' => $imported,
                'errors' => $errors,
                'duplicates' => $duplicates,
            ];
        } catch (Throwable $exception) {
            $this->updateBatch($batchId, $total, $imported, $errors, 'failed');

            throw new ImportProcessException('A importação falhou.', $batchId, $exception);
        }
    }

    public function deleteBatch(int $batchId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT id, filename, status
             FROM recruit_import_batches
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $batchId]);
        $batch = $statement->fetch();

        if ($batch === false) {
            throw new RuntimeException('Lote de importação não encontrado.');
        }

        if (($batch['status'] ?? null) === 'processing') {
            throw new RuntimeException('Não é possível excluir um lote que ainda está em processamento.');
        }

        $deleteStatement = $this->pdo->prepare(
            'DELETE FROM recruit_import_batches
             WHERE id = :id'
        );
        $deleteStatement->execute(['id' => $batchId]);

        $filename = trim((string) ($batch['filename'] ?? ''));

        if ($filename !== '') {
            $filepath = dirname(__DIR__, 2) . '/storage/imports/' . basename($filename);

            if (is_file($filepath)) {
                @unlink($filepath);
            }
        }
    }

    private function loadWorksheet(string $filepath): Worksheet
    {
        $extension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            throw new RuntimeException('Extensão de arquivo não suportada para importação.');
        }

        $reader = IOFactory::createReaderForFile($filepath);
        $reader->setReadDataOnly(true);

        return $reader->load($filepath)->getActiveSheet();
    }

    /**
     * @return array{0:int,1:array<int, string>,2:array<string, list<int>>,3:string}
     */
    private function resolveHeaderMap(Worksheet $worksheet): array
    {
        $highestRow = min(10, $worksheet->getHighestDataRow());
        $highestColumn = $worksheet->getHighestDataColumn();
        $bestHeaderRow = 0;
        $bestHeaders = [];
        $bestFieldMap = [];
        $bestScore = 0;

        for ($rowNumber = 1; $rowNumber <= $highestRow; $rowNumber++) {
            $headers = $this->readRow($worksheet, $rowNumber, $highestColumn);
            $fieldMap = [];

            foreach ($headers as $index => $header) {
                $field = $this->mapHeaderToField($header);

                if ($field === null) {
                    continue;
                }

                $fieldMap[$field] ??= [];
                $fieldMap[$field][] = $index;
            }

            $score = count($fieldMap);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestHeaderRow = $rowNumber;
                $bestHeaders = $headers;
                $bestFieldMap = $fieldMap;
            }
        }

        return [$bestHeaderRow, $bestHeaders, $bestFieldMap, $highestColumn];
    }

    /**
     * @return list<string>
     */
    private function readRow(Worksheet $worksheet, int $rowNumber, string $highestColumn): array
    {
        $rows = $worksheet->rangeToArray(
            sprintf('A%d:%s%d', $rowNumber, $highestColumn, $rowNumber),
            null,
            true,
            false,
            false
        );

        if ($rows === []) {
            return [];
        }

        return array_map(
            static fn (mixed $value): string => trim((string) ($value ?? '')),
            $rows[0]
        );
    }

    /**
     * @param array<int, string> $headers
     * @param list<string> $rowValues
     * @return array<string, string>
     */
    private function buildRawSource(array $headers, array $rowValues): array
    {
        $rawSource = [];

        foreach ($rowValues as $index => $value) {
            $key = trim($headers[$index] ?? '');

            if ($key === '') {
                $key = sprintf('column_%d', $index + 1);
            }

            $rawSource[$key] = $value;
        }

        return $rawSource;
    }

    /**
     * @param array<string, string> $rawSource
     */
    private function isRowEmpty(array $rawSource): bool
    {
        foreach ($rawSource as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function mapHeaderToField(string $header): ?string
    {
        $normalizedHeader = $this->normalizeToken($header);

        if ($normalizedHeader === '') {
            return null;
        }

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if ($normalizedHeader === $this->normalizeToken($alias)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $rowValues
     * @param array<string, list<int>> $fieldMap
     * @return array<string, string|null>
     */
    private function extractMappedRow(array $rowValues, array $fieldMap): array
    {
        $mapped = [
            'full_name' => null,
            'cpf' => null,
            'phone' => null,
            'whatsapp' => null,
            'email' => null,
            'skill' => null,
            'level' => null,
            'state' => null,
            'city' => null,
        ];

        foreach ($mapped as $field => $value) {
            foreach ($fieldMap[$field] ?? [] as $index) {
                $cellValue = trim($rowValues[$index] ?? '');

                if ($cellValue === '') {
                    continue;
                }

                $mapped[$field] = $cellValue;
                break;
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, string|null> $row
     * @return array{
     *     full_name:string,
     *     cpf:?string,
     *     phone:?string,
     *     whatsapp:?string,
     *     email:?string,
     *     skill:?string,
     *     level:string,
     *     state:?string,
     *     city:?string
     * }
     */
    private function normalizeMappedRow(array $row): array
    {
        return [
            'full_name' => $this->normalizeText($row['full_name']),
            'cpf' => $this->normalizeCpf($row['cpf']),
            'phone' => $this->normalizePhone($row['phone']),
            'whatsapp' => $this->normalizePhone($row['whatsapp']),
            'email' => $this->normalizeEmail($row['email']),
            'skill' => $this->normalizeSkill($row['skill']),
            'level' => $this->normalizeLevel($row['level']),
            'state' => $this->normalizeState($row['state']),
            'city' => $this->normalizeText($row['city']),
        ];
    }

    /**
     * @param array<string, string|null> $mappedRow
     * @param array{
     *     full_name:string,
     *     cpf:?string,
     *     phone:?string,
     *     whatsapp:?string,
     *     email:?string,
     *     skill:?string,
     *     level:string,
     *     state:?string,
     *     city:?string
     * } $normalizedRow
     */
    private function validateContactFields(array $mappedRow, array $normalizedRow): void
    {
        $phoneRaw = trim((string) ($mappedRow['phone'] ?? ''));
        $whatsappRaw = trim((string) ($mappedRow['whatsapp'] ?? ''));
        $emailRaw = trim((string) ($mappedRow['email'] ?? ''));

        if ($phoneRaw !== '' && $normalizedRow['phone'] === null) {
            throw new RuntimeException('Valor de telefone inválido.');
        }

        if ($whatsappRaw !== '' && $normalizedRow['whatsapp'] === null) {
            throw new RuntimeException('Valor de WhatsApp inválido.');
        }

        if ($emailRaw !== '' && $normalizedRow['email'] === null) {
            throw new RuntimeException('Valor de e-mail inválido.');
        }
    }

    private function normalizeText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', trim($value));

        return $value === null ? '' : $value;
    }

    private function normalizeCpf(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === null || $digits === '') {
            return null;
        }

        return strlen($digits) === 11 ? $digits : null;
    }

    private function normalizePhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10) {
            $digits = substr($digits, 0, 2) . '9' . substr($digits, 2);
        }

        return strlen($digits) === 11 ? $digits : null;
    }

    private function normalizeEmail(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $candidates = preg_split('/[\\s]*[\\/,;|]+[\\s]*/u', trim($value)) ?: [];

        foreach ($candidates as $candidate) {
            $candidate = mb_strtolower(trim($candidate));

            if ($candidate === '') {
                continue;
            }

            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeSkill(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value));

        if ($value === null || $value === '') {
            return null;
        }

        return mb_strtoupper($value);
    }

    private function normalizeLevel(?string $value): string
    {
        if ($value === null) {
            return 'nao_informado';
        }

        $normalized = $this->normalizeToken($value);

        if ($normalized === '') {
            return 'nao_informado';
        }

        foreach (self::LEVEL_ALIASES as $alias => $level) {
            if ($normalized === $this->normalizeToken($alias)) {
                return $level;
            }
        }

        return 'nao_informado';
    }

    private function normalizeState(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtoupper(trim($value));
        $value = preg_replace('/[^A-Z]/', '', $value);

        if ($value === null || strlen($value) !== 2) {
            return null;
        }

        return $value;
    }

    private function normalizeToken(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }

        $value = mb_strtolower($value);

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    /**
     * @param array{
     *     cpf:?string,
     *     phone:?string,
     *     whatsapp:?string,
     *     email:?string
     * } $row
     */
    private function findDuplicateCandidateId(array $row): ?int
    {
        if ($row['cpf'] !== null) {
            $statement = $this->pdo->prepare(
                'SELECT id FROM recruit_candidates WHERE cpf = :cpf LIMIT 1'
            );
            $statement->execute(['cpf' => $row['cpf']]);
            $candidateId = $statement->fetchColumn();

            if ($candidateId !== false) {
                return (int) $candidateId;
            }
        }

        $phones = array_values(array_unique(array_filter([
            $row['phone'],
            $row['whatsapp'],
        ])));

        if ($phones !== []) {
            $placeholders = [];
            $params = [];

            foreach ($phones as $index => $phone) {
                $placeholder = ':phone_' . $index;
                $placeholders[] = $placeholder;
                $params[ltrim($placeholder, ':')] = $phone;
            }

            $statement = $this->pdo->prepare(
                sprintf(
                    "SELECT candidate_id
                     FROM recruit_candidate_contacts
                     WHERE value IN (%s)
                       AND is_primary = 1
                       AND type IN ('phone', 'whatsapp')
                     LIMIT 1",
                    implode(', ', $placeholders)
                )
            );
            $statement->execute($params);
            $candidateId = $statement->fetchColumn();

            if ($candidateId !== false) {
                return (int) $candidateId;
            }
        }

        if ($row['email'] !== null) {
            $statement = $this->pdo->prepare(
                "SELECT candidate_id
                 FROM recruit_candidate_contacts
                 WHERE value = :value
                   AND type = 'email'
                 LIMIT 1"
            );
            $statement->execute(['value' => $row['email']]);
            $candidateId = $statement->fetchColumn();

            if ($candidateId !== false) {
                return (int) $candidateId;
            }
        }

        return null;
    }

    /**
     * @param array{
     *     full_name:string,
     *     cpf:?string
     * } $row
     */
    private function insertCandidate(array $row, int $batchId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_candidates (full_name, cpf, status, source_batch_id, notes)
             VALUES (:full_name, :cpf, :status, :source_batch_id, :notes)'
        );

        $statement->execute([
            'full_name' => $row['full_name'],
            'cpf' => $row['cpf'],
            'status' => 'imported',
            'source_batch_id' => $batchId,
            'notes' => null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{
     *     phone:?string,
     *     whatsapp:?string,
     *     email:?string
     * } $row
     */
    private function insertContacts(int $candidateId, array $row): void
    {
        $contacts = [];

        if ($row['phone'] !== null) {
            $contacts['phone'] = $row['phone'];
        }

        if ($row['whatsapp'] !== null) {
            $contacts['whatsapp'] = $row['whatsapp'];
        }

        if ($row['email'] !== null) {
            $contacts['email'] = $row['email'];
        }

        if ($contacts === []) {
            return;
        }

        $primaryType = array_key_first($contacts);
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_candidate_contacts (candidate_id, type, value, is_primary)
             VALUES (:candidate_id, :type, :value, :is_primary)'
        );

        foreach ($contacts as $type => $value) {
            $statement->execute([
                'candidate_id' => $candidateId,
                'type' => $type,
                'value' => $value,
                'is_primary' => $type === $primaryType ? 1 : 0,
            ]);
        }
    }

    private function insertInitialStatusHistory(int $candidateId, string $operator): void
    {
        $statement = $this->pdo->prepare(
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

        $statement->execute([
            'candidate_id' => $candidateId,
            'from_status' => 'imported',
            'to_status' => 'imported',
            'changed_by' => $operator,
            'reason' => 'Status inicial atribuído pela importação.',
        ]);
    }

    private function insertSkills(int $candidateId, ?string $skill, string $level): void
    {
        if ($skill === null) {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_candidate_skills (candidate_id, skill, level)
             VALUES (:candidate_id, :skill, :level)'
        );

        foreach ($this->splitSkills($skill) as $item) {
            $statement->execute([
                'candidate_id' => $candidateId,
                'skill' => $item,
                'level' => $level,
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function splitSkills(string $skill): array
    {
        $items = preg_split('/[,;]+/', $skill) ?: [];
        $unique = [];

        foreach ($items as $item) {
            $item = mb_strtoupper(trim($item));

            if ($item === '') {
                continue;
            }

            $unique[$item] = true;
        }

        return array_keys($unique);
    }

    private function insertAddress(int $candidateId, ?string $state, ?string $city): void
    {
        if ($state === null || $city === null || $city === '') {
            return;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_candidate_addresses (candidate_id, state, city, region)
             VALUES (:candidate_id, :state, :city, :region)'
        );

        $statement->execute([
            'candidate_id' => $candidateId,
            'state' => $state,
            'city' => $city,
            'region' => null,
        ]);
    }

    private function insertImportRow(
        int $batchId,
        int $rowNumber,
        string $rawPayload,
        string $status,
        ?string $errorMessage,
        ?int $candidateId
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_import_rows (batch_id, `row_number`, raw_data, status, error_message, candidate_id)
             VALUES (:batch_id, :row_number, :raw_data, :status, :error_message, :candidate_id)'
        );

        $statement->execute([
            'batch_id' => $batchId,
            'row_number' => $rowNumber,
            'raw_data' => $rawPayload,
            'status' => $status,
            'error_message' => $errorMessage,
            'candidate_id' => $candidateId,
        ]);
    }

    private function createBatch(string $filename, string $operator): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_import_batches (filename, total_rows, imported_rows, error_rows, status, created_by)
             VALUES (:filename, :total_rows, :imported_rows, :error_rows, :status, :created_by)'
        );

        $statement->execute([
            'filename' => $filename,
            'total_rows' => 0,
            'imported_rows' => 0,
            'error_rows' => 0,
            'status' => 'processing',
            'created_by' => $operator,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function updateBatch(int $batchId, int $total, int $imported, int $errors, string $status): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recruit_import_batches
             SET total_rows = :total_rows,
                 imported_rows = :imported_rows,
                 error_rows = :error_rows,
                 status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $batchId,
            'total_rows' => $total,
            'imported_rows' => $imported,
            'error_rows' => $errors,
            'status' => $status,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJson(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return $json === false ? '{}' : $json;
    }
}
