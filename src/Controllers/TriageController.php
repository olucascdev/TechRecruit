<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use InvalidArgumentException;
use PDO;
use TechRecruit\Database;
use TechRecruit\Security\Csrf;
use TechRecruit\Services\CampaignService;
use TechRecruit\Services\WhatsGwWebhookService;
use Throwable;

final class TriageController extends Controller
{
    private CampaignService $campaignService;

    private WhatsGwWebhookService $whatsGwWebhookService;

    public function __construct(?CampaignService $campaignService = null, ?PDO $pdo = null)
    {
        $connection = $pdo ?? Database::connect();
        $this->campaignService = $campaignService ?? new CampaignService(null, null, null, null, $connection);
        $this->whatsGwWebhookService = new WhatsGwWebhookService($this->campaignService, null, $connection);
    }

    public function inbound(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->json([
                'success' => false,
                'message' => 'Método não suportado.',
            ], 405);
        }

        $payload = $this->readPayload();
        $event = strtolower(trim((string) ($payload['event'] ?? '')));
        $campaignId = (int) ($payload['campaign_id'] ?? 0);
        $campaignRecipientId = (int) ($payload['campaign_recipient_id'] ?? 0);
        $contact = trim((string) (
            $payload['contact']
            ?? $payload['phone']
            ?? $payload['from']
            ?? $payload['source_contact']
            ?? $payload['contact_phone_number']
            ?? ''
        ));
        $messageBody = trim((string) ($payload['message_body'] ?? $payload['message'] ?? $payload['body'] ?? $payload['text'] ?? ''));

        try {
            if ($event !== '') {
                $result = $this->whatsGwWebhookService->handle($payload, $this->resolveOperator());

                $this->json([
                    'success' => true,
                    'data' => $result,
                ]);
            }

            $this->ensureManualInboundAccess();

            if ($messageBody === '') {
                throw new InvalidArgumentException('Informe a mensagem recebida.');
            }

            if ($campaignRecipientId > 0) {
                if ($campaignId < 1) {
                    throw new InvalidArgumentException('Informe campaign_id quando usar campaign_recipient_id.');
                }

                $result = $this->campaignService->registerInboundReply(
                    $campaignId,
                    $campaignRecipientId,
                    $messageBody,
                    $this->resolveOperator()
                );
            } else {
                $result = $this->campaignService->registerInboundReplyByContact(
                    $campaignId > 0 ? $campaignId : null,
                    $contact,
                    $messageBody,
                    $this->resolveOperator()
                );
            }

            $this->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            error_log((string) $exception);
            $this->json([
                'success' => false,
                'message' => trim($exception->getMessage()) !== ''
                    ? $exception->getMessage()
                    : 'Falha ao processar inbound.',
            ], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(): array
    {
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $rawBody = file_get_contents('php://input');
            $decoded = json_decode(is_string($rawBody) ? $rawBody : '', true);

            return is_array($decoded) ? $decoded : [];
        }

        return $_POST;
    }

    private function ensureManualInboundAccess(): void
    {
        if ($this->currentUser() === null) {
            $this->json([
                'success' => false,
                'message' => 'Autenticação obrigatória para registrar inbound manual sem evento.',
            ], 403);
        }

        if (!Csrf::isValid(Csrf::requestToken())) {
            $this->json([
                'success' => false,
                'message' => 'Sessão expirada ou token inválido. Atualize a página e tente novamente.',
            ], 419);
        }
    }
}
