<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use InvalidArgumentException;
use PDO;
use TechRecruit\Database;
use TechRecruit\Models\PortalModel;
use TechRecruit\Services\PortalService;
use Throwable;

final class PortalController extends Controller
{
    private PDO $pdo;

    private PortalModel $portalModel;

    private PortalService $portalService;

    public function __construct(
        ?PortalModel $portalModel = null,
        ?PortalService $portalService = null,
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo ?? Database::connect();
        $this->portalModel = $portalModel ?? new PortalModel($this->pdo);
        $this->portalService = $portalService ?? new PortalService(null, $this->portalModel, null, $this->pdo);
    }

    public function show(string $token): void
    {
        $portal = $this->portalModel->findByToken($token);

        if ($portal === null) {
            http_response_code(404);
            echo 'Portal link not found.';

            return;
        }

        $this->portalService->markPortalAccessed($token);
        $portal = $this->portalModel->findByToken($token) ?? $portal;

        $this->render('portal/show', [
            'portal' => $portal,
            'portalFormAction' => '/portal/' . $token . '/submit',
        ], 'Portal do Candidato', 'layout/portal');
    }

    public function submit(string $token): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/portal/' . $token);
        }

        try {
            $this->portalService->submitPortal($token, $_POST, $_FILES);
            $this->setFlash('success', 'Cadastro e documentos enviados com sucesso.');
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash(
                'error',
                trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao enviar cadastro e documentos.'
            );
        }

        $this->redirect('/portal/' . $token);
    }

    public function generate(int $candidateId): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/candidates/' . $candidateId);
        }

        try {
            $portal = $this->portalService->generatePortalForCandidate($candidateId, $this->resolveOperator());
            $portalUrl = $this->absoluteUrl('/portal/' . $portal['access_token']);
            $dispatchResult = $this->portalService->sendPortalLink($candidateId, $portalUrl);

            if (!empty($dispatchResult['success'])) {
                $suffix = !empty($dispatchResult['simulated']) ? ' (simulado)' : '';
                $this->setFlash(
                    'success',
                    sprintf(
                        'Link do portal gerado e enviado por WhatsApp%s para %s: %s',
                        $suffix,
                        (string) ($dispatchResult['destination_contact'] ?? '-'),
                        $portalUrl
                    )
                );
            } else {
                $this->setFlash(
                    'success',
                    'Link do portal gerado com sucesso: ' . $portalUrl
                );
                $this->setFlash(
                    'error',
                    'Falha ao enviar o link por WhatsApp: ' . (string) ($dispatchResult['error'] ?? 'erro desconhecido.')
                );
            }
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash(
                'error',
                trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao gerar link do portal.'
            );
        }

        $this->redirect('/candidates/' . $candidateId);
    }

    public function updateStatus(int $candidateId): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/candidates/' . $candidateId);
        }

        $status = trim((string) ($_POST['portal_status'] ?? ''));

        try {
            $this->portalService->updatePortalStatusForCandidate($candidateId, $status, $this->resolveOperator());
            $this->setFlash('success', 'Status do portal atualizado com sucesso.');
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash(
                'error',
                trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao atualizar status do portal.'
            );
        }

        $this->redirect('/candidates/' . $candidateId);
    }

    public function downloadDocument(int $documentId): never
    {
        $document = $this->portalModel->findDocumentById($documentId);

        if ($document === null || !is_file((string) $document['stored_path']) || !is_readable((string) $document['stored_path'])) {
            http_response_code(404);
            echo 'Document not found.';
            exit;
        }

        $mimeType = trim((string) ($document['mime_type'] ?? '')) ?: 'application/octet-stream';
        $fileName = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename((string) $document['original_name'])) ?: 'documento';

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize((string) $document['stored_path']));
        header('Content-Disposition: inline; filename="' . $fileName . '"');

        readfile((string) $document['stored_path']);
        exit;
    }
}
