<?php

declare(strict_types=1);

namespace TechRecruit\Controllers;

use InvalidArgumentException;
use PDO;
use TechRecruit\Database;
use TechRecruit\Security\Csrf;
use TechRecruit\Security\RateLimiter;
use TechRecruit\Services\CampaignService;
use TechRecruit\Services\WhatsGwWebhookService;
use Throwable;

final class TriageController extends Controller
{
    private const WEBHOOK_IP_MAX_ATTEMPTS = 900;
    private const WEBHOOK_IP_WINDOW_SECONDS = 60;
    private const WEBHOOK_IP_BLOCK_SECONDS = 120;

    private const WEBHOOK_MESSAGE_CONTACT_MAX_ATTEMPTS = 240;
    private const WEBHOOK_MESSAGE_CONTACT_WINDOW_SECONDS = 60;
    private const WEBHOOK_MESSAGE_CONTACT_BLOCK_SECONDS = 180;

    private CampaignService $campaignService;

    private WhatsGwWebhookService $whatsGwWebhookService;

    private RateLimiter $rateLimiter;

    public function __construct(?CampaignService $campaignService = null, ?PDO $pdo = null, ?RateLimiter $rateLimiter = null)
    {
        $connection = $pdo ?? Database::connect();
        $this->campaignService = $campaignService ?? new CampaignService(null, null, null, null, $connection);
        $this->whatsGwWebhookService = new WhatsGwWebhookService($this->campaignService, null, $connection);
        $this->rateLimiter = $rateLimiter ?? new RateLimiter();
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

        if ($event !== '') {
            $this->enforceWebhookRateLimits($event, $contact, $payload);
        }

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
     * @param array<string, mixed> $payload
     */
    private function enforceWebhookRateLimits(string $event, string $contact, array $payload): void
    {
        $ipFingerprint = $this->resolveWebhookClientFingerprint($payload);

        if ($ipFingerprint !== '') {
            try {
                $ipResult = $this->rateLimiter->consume(
                    'triage_webhook_ip',
                    $ipFingerprint,
                    self::WEBHOOK_IP_MAX_ATTEMPTS,
                    self::WEBHOOK_IP_WINDOW_SECONDS,
                    self::WEBHOOK_IP_BLOCK_SECONDS
                );

                if (!($ipResult['allowed'] ?? true)) {
                    $this->json([
                        'success' => false,
                        'message' => 'Muitas requisições no webhook. Aguarde e tente novamente.',
                    ], 429);
                }
            } catch (Throwable $exception) {
                error_log('[TriageController] Webhook IP limiter unavailable: ' . $exception->getMessage());
            }
        }

        if ($event !== 'message') {
            return;
        }

        $contactDigits = preg_replace('/\D+/', '', $contact) ?? '';

        if ($contactDigits === '') {
            return;
        }

        try {
            $contactResult = $this->rateLimiter->consume(
                'triage_webhook_message_contact',
                $contactDigits,
                self::WEBHOOK_MESSAGE_CONTACT_MAX_ATTEMPTS,
                self::WEBHOOK_MESSAGE_CONTACT_WINDOW_SECONDS,
                self::WEBHOOK_MESSAGE_CONTACT_BLOCK_SECONDS
            );

            if (!($contactResult['allowed'] ?? true)) {
                $this->json([
                    'success' => false,
                    'message' => 'Volume de mensagens excedido para este contato. Aguarde alguns instantes.',
                ], 429);
            }
        } catch (Throwable $exception) {
            error_log('[TriageController] Webhook contact limiter unavailable: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveWebhookClientFingerprint(array $payload): string
    {
        $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $phoneNumber = trim((string) ($payload['phone_number'] ?? ''));

        if ($phoneNumber === '') {
            return $remoteAddress;
        }

        return $remoteAddress . '|' . preg_replace('/\D+/', '', $phoneNumber);
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
