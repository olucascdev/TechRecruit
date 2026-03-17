<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use PDO;
use TechRecruit\Database;
use TechRecruit\Services\ImportProcessException;
use TechRecruit\Services\ImportService;
use Throwable;

final class ImportController extends Controller
{
    private PDO $pdo;

    private ImportService $importService;

    public function __construct(?ImportService $importService = null, ?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
        $this->importService = $importService ?? new ImportService($this->pdo);
    }

    public function index(): void
    {
        $statement = $this->pdo->query(
            'SELECT id, filename, status, total_rows, imported_rows, error_rows, created_at
             FROM recruit_import_batches
             ORDER BY created_at DESC, id DESC
             LIMIT 20'
        );

        $this->render('import/index', [
            'batches' => $statement->fetchAll(),
        ], 'Importacoes');
    }

    public function upload(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/import');
        }

        $file = $_FILES['excel_file'] ?? null;

        if (!is_array($file)) {
            $this->setFlash('error', 'Selecione um arquivo Excel para importar.');
            $this->redirect('/import');
        }

        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uploadError !== UPLOAD_ERR_OK) {
            $this->setFlash('error', 'Falha no upload do arquivo.');
            $this->redirect('/import');
        }

        $size = (int) ($file['size'] ?? 0);

        if ($size > 10 * 1024 * 1024) {
            $this->setFlash('error', 'O arquivo excede o limite de 10MB.');
            $this->redirect('/import');
        }

        $originalName = (string) ($file['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            $this->setFlash('error', 'Formato inválido. Envie apenas arquivos .xls ou .xlsx.');
            $this->redirect('/import');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $this->setFlash('error', 'Arquivo de upload inválido.');
            $this->redirect('/import');
        }

        $destination = $this->buildUploadPath($originalName);

        if (!move_uploaded_file($tmpName, $destination)) {
            $this->setFlash('error', 'Não foi possível salvar o arquivo enviado.');
            $this->redirect('/import');
        }

        try {
            $result = $this->importService->run($destination, $this->resolveOperator());
            $this->setFlash(
                'success',
                sprintf(
                    'Importação concluída. %d importados, %d duplicados, %d erros.',
                    $result['imported'],
                    $result['duplicates'],
                    $result['errors']
                )
            );

            $this->redirect('/import/result/' . $result['batch_id']);
        } catch (ImportProcessException $exception) {
            if ($exception->getBatchId() !== null) {
                $this->markBatchAsFailed($exception->getBatchId());
            }

            error_log((string) $exception);
            $this->setFlash('error', 'A importação falhou. Verifique o arquivo e tente novamente.');
            $this->redirect('/import');
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash('error', 'Erro inesperado durante a importação.');
            $this->redirect('/import');
        }
    }

    public function result(int $batchId): void
    {
        $batchStatement = $this->pdo->prepare(
            'SELECT id, filename, total_rows, imported_rows, error_rows, status, created_by, created_at, updated_at
             FROM recruit_import_batches
             WHERE id = :id
             LIMIT 1'
        );
        $batchStatement->execute(['id' => $batchId]);
        $batch = $batchStatement->fetch();

        if ($batch === false) {
            http_response_code(404);
            echo 'Batch not found.';

            return;
        }

        $rowsStatement = $this->pdo->prepare(
            "SELECT id, `row_number`, raw_data, status, error_message, candidate_id, created_at
             FROM recruit_import_rows
             WHERE batch_id = :batch_id
               AND status IN ('error', 'duplicate')
             ORDER BY `row_number` ASC, id ASC"
        );
        $rowsStatement->execute(['batch_id' => $batchId]);
        $rows = $rowsStatement->fetchAll();

        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['raw_data'], true);
            $row['raw_data'] = is_array($decoded) ? $decoded : [];
        }
        unset($row);

        $this->render('import/result', [
            'batch' => $batch,
            'rows' => $rows,
        ], 'Resultado da Importacao');
    }

    private function buildUploadPath(string $originalName): string
    {
        $directory = dirname(__DIR__, 2) . '/storage/imports';

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $safeOriginalName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($originalName)) ?: 'import.xlsx';

        return sprintf(
            '%s/%s_%s_%s',
            $directory,
            date('YmdHis'),
            bin2hex(random_bytes(4)),
            $safeOriginalName
        );
    }
    private function markBatchAsFailed(int $batchId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE recruit_import_batches
             SET status = :status,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $batchId,
            'status' => 'failed',
        ]);
    }
}
