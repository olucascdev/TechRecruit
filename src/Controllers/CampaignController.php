<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use InvalidArgumentException;
use PDO;
use TechRecruit\Database;
use TechRecruit\Models\CampaignModel;
use TechRecruit\Models\CandidateModel;
use TechRecruit\Services\CampaignService;
use TechRecruit\Services\TriageBotService;
use Throwable;

final class CampaignController extends Controller
{
    private PDO $pdo;

    private CampaignModel $campaignModel;

    private CandidateModel $candidateModel;

    private CampaignService $campaignService;

    public function __construct(
        ?CampaignModel $campaignModel = null,
        ?CandidateModel $candidateModel = null,
        ?CampaignService $campaignService = null,
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo ?? Database::connect();
        $this->campaignModel = $campaignModel ?? new CampaignModel($this->pdo);
        $this->candidateModel = $candidateModel ?? new CandidateModel($this->pdo);
        $this->campaignService = $campaignService ?? new CampaignService(
            $this->candidateModel,
            $this->campaignModel,
            null,
            null,
            $this->pdo
        );
    }

    public function index(): void
    {
        $this->renderIndex();
    }

    public function store(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/campaigns');
        }

        $formData = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'automation_type' => trim((string) ($_POST['automation_type'] ?? '')),
            'skill' => trim((string) ($_POST['skill'] ?? '')),
            'status' => trim((string) ($_POST['status'] ?? '')),
            'state' => trim((string) ($_POST['state'] ?? '')),
            'search' => trim((string) ($_POST['search'] ?? '')),
            'recipient_limit' => trim((string) ($_POST['recipient_limit'] ?? '')),
            'message_template' => trim((string) ($_POST['message_template'] ?? '')),
        ];

        try {
            $campaignId = $this->campaignService->createBaseCampaign($formData, $this->resolveOperator());
            $this->setFlash('success', 'Campanha criada e fila inicial de WhatsApp montada com sucesso.');
            $this->redirect('/campaigns/' . $campaignId);
        } catch (InvalidArgumentException $exception) {
            $this->renderIndex($formData, $exception->getMessage());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $message = trim($exception->getMessage());
            $this->renderIndex(
                $formData,
                $message !== '' ? $message : 'Nao foi possivel criar a campanha neste momento.'
            );
        }
    }

    public function show(int $id): void
    {
        $campaign = $this->campaignModel->findById($id);

        if ($campaign === null) {
            http_response_code(404);
            echo 'Campaign not found.';

            return;
        }

        $this->render('campaigns/show', [
            'campaign' => $campaign,
        ], 'Campanha WhatsApp');
    }

    public function process(int $id): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/campaigns/' . $id);
        }

        try {
            $result = $this->campaignService->processCampaign($id, $this->resolveOperator());
            $this->setFlash(
                'success',
                sprintf(
                    'Fila processada. %d item(ns), %d enviado(s), %d falha(s), %d opt-out.',
                    $result['processed'],
                    $result['sent'],
                    $result['failed'],
                    $result['opt_out']
                )
            );
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao processar a campanha.');
        }

        $this->redirect('/campaigns/' . $id);
    }

    public function pause(int $id): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/campaigns/' . $id);
        }

        try {
            $this->campaignService->pauseCampaign($id);
            $this->setFlash('success', 'Campanha pausada.');
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao pausar a campanha.');
        }

        $this->redirect('/campaigns/' . $id);
    }

    public function resume(int $id): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/campaigns/' . $id);
        }

        try {
            $status = $this->campaignService->resumeCampaign($id, $this->resolveOperator());
            $this->setFlash('success', sprintf('Campanha retomada. Status atual: %s.', $status));
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao retomar a campanha.');
        }

        $this->redirect('/campaigns/' . $id);
    }

    public function cancel(int $id): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/campaigns/' . $id);
        }

        try {
            $this->campaignService->cancelCampaign($id);
            $this->setFlash('success', 'Campanha cancelada e fila pendente encerrada.');
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao cancelar a campanha.');
        }

        $this->redirect('/campaigns/' . $id);
    }

    public function reply(int $id): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/campaigns/' . $id);
        }

        $recipientId = (int) ($_POST['campaign_recipient_id'] ?? 0);
        $messageBody = trim((string) ($_POST['message_body'] ?? ''));

        try {
            $result = $this->campaignService->registerInboundReply(
                $id,
                $recipientId,
                $messageBody,
                $this->resolveOperator()
            );
            $message = sprintf('Retorno registrado com sucesso. Intencao identificada: %s.', $result['intent'] ?? 'unknown');

            if (!empty($result['auto_reply'])) {
                $message .= ' Resposta automatica gerada pelo bot.';
            }

            if (isset($result['auto_reply_dispatch']['success']) && $result['auto_reply_dispatch']['success'] === false) {
                $message .= ' O envio automatico pelo WhatsGW falhou e precisa de conferência manual.';
            }

            if (!empty($result['needs_operator'])) {
                $message .= ' Sessao encaminhada para operador.';
            }

            $this->setFlash('success', $message);
        } catch (InvalidArgumentException $exception) {
            $this->setFlash('error', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->setFlash('error', trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao registrar retorno.');
        }

        $this->redirect('/campaigns/' . $id);
    }

    /**
     * @param array<string, mixed> $formData
     */
    private function renderIndex(array $formData = [], ?string $errorMessage = null): void
    {
        $filters = $this->campaignService->normalizeFilters($formData);
        $recipientLimit = trim((string) ($formData['recipient_limit'] ?? ''));
        $audienceEstimate = null;

        if ($filters !== [] || $recipientLimit !== '') {
            try {
                $normalizedLimit = $recipientLimit === '' ? null : max(1, min(5000, (int) $recipientLimit));
                $audienceEstimate = $this->candidateModel->countEligibleForCampaign($filters);

                if ($normalizedLimit !== null) {
                    $audienceEstimate = min($audienceEstimate, $normalizedLimit);
                }
            } catch (Throwable) {
                $audienceEstimate = null;
            }
        }

        $this->render('campaigns/index', [
            'campaigns' => $this->campaignModel->findAll(),
            'automationTypes' => [
                'broadcast' => 'Broadcast manual',
                TriageBotService::AUTOMATION_TYPE => 'Bot de triagem W13',
            ],
            'skills' => $this->fetchDistinctValues('SELECT DISTINCT skill FROM recruit_candidate_skills ORDER BY skill ASC'),
            'states' => $this->fetchDistinctValues('SELECT DISTINCT state FROM recruit_candidate_addresses ORDER BY state ASC'),
            'candidateStatuses' => CandidateModel::VALID_STATUSES,
            'formData' => array_merge([
                'name' => '',
                'automation_type' => TriageBotService::AUTOMATION_TYPE,
                'skill' => '',
                'status' => '',
                'state' => '',
                'search' => '',
                'recipient_limit' => '',
                'message_template' => (new TriageBotService(null, $this->pdo))->buildInitialOfferMessage('[CIDADE/UF]'),
            ], $formData),
            'errorMessage' => $errorMessage,
            'audienceEstimate' => $audienceEstimate,
        ], 'Campanhas WhatsApp');
    }

    /**
     * @return list<string>
     */
    private function fetchDistinctValues(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        $values = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $values),
            static fn (string $value): bool => $value !== ''
        ));
    }
}
