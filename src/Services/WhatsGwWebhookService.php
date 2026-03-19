<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use InvalidArgumentException;
use PDO;
use TechRecruit\Database;
use Throwable;

final class WhatsGwWebhookService
{
    private PDO $pdo;

    private CampaignService $campaignService;

    private WhatsGwClient $whatsGwClient;

    public function __construct(
        ?CampaignService $campaignService = null,
        ?WhatsGwClient $whatsGwClient = null,
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo ?? Database::connect();
        $this->campaignService = $campaignService ?? new CampaignService(null, null, null, null, $this->pdo);
        $this->whatsGwClient = $whatsGwClient ?? new WhatsGwClient();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload, string $operator): array
    {
        $event = strtolower(trim((string) ($payload['event'] ?? '')));

        if ($event === '') {
            throw new InvalidArgumentException('Evento do WhatsGW nao informado.');
        }

        $incomingApiKey = isset($payload['apikey']) ? trim((string) $payload['apikey']) : null;

        if ($this->whatsGwClient->isConfigured() && !$this->whatsGwClient->matchesApiKey($incomingApiKey)) {
            $this->persistWebhookEvent($event, $payload, 'failed', 'API key invalida.');

            throw new InvalidArgumentException('API key do webhook invalida.');
        }

        try {
            $result = match ($event) {
                'message' => $this->handleMessageEvent($payload, $operator),
                'status' => $this->campaignService->recordWhatsGwStatusEvent($payload),
                'phonestate' => $this->campaignService->recordWhatsGwPhoneStateEvent($payload),
                default => [
                    'event' => $event,
                    'status' => 'ignored',
                    'message' => 'Evento nao tratado.',
                ],
            };

            $this->persistWebhookEvent(
                $event,
                $payload,
                (string) ($result['status'] ?? 'processed'),
                isset($result['message']) ? (string) $result['message'] : null
            );

            return $result;
        } catch (Throwable $exception) {
            $this->persistWebhookEvent($event, $payload, 'failed', $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleMessageEvent(array $payload, string $operator): array
    {
        $messageType = strtolower(trim((string) ($payload['message_type'] ?? '')));
        $chatType = strtolower(trim((string) ($payload['chat_type'] ?? '')));
        $messageState = strtolower(trim((string) ($payload['message_state'] ?? '')));
        $contactPhoneNumber = trim((string) ($payload['contact_phone_number'] ?? ''));
        $messageBody = trim((string) ($payload['message_body'] ?? ''));

        if ($contactPhoneNumber === '' || $messageBody === '') {
            return [
                'event' => 'message',
                'status' => 'ignored',
                'message' => 'Mensagem sem contato ou corpo.',
            ];
        }

        if ($messageType !== '' && $messageType !== 'text') {
            return [
                'event' => 'message',
                'status' => 'ignored',
                'message' => sprintf('Tipo de mensagem ignorado: %s.', $messageType),
            ];
        }

        if ($chatType !== '' && $chatType !== 'user') {
            return [
                'event' => 'message',
                'status' => 'ignored',
                'message' => sprintf('Chat type ignorado: %s.', $chatType),
            ];
        }

        if ($messageState !== '' && !in_array($messageState, ['received', 'edited'], true)) {
            return [
                'event' => 'message',
                'status' => 'ignored',
                'message' => sprintf('Estado de mensagem ignorado: %s.', $messageState),
            ];
        }

        $result = $this->campaignService->registerInboundReplyByContact(
            null,
            $contactPhoneNumber,
            $messageBody,
            $operator,
            $this->buildInboundProviderMetadata($payload)
        );

        return array_merge([
            'event' => 'message',
            'status' => 'processed',
        ], $result);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildInboundProviderMetadata(array $payload): array
    {
        return [
            'contact_name' => $payload['contact_name'] ?? null,
            'chat_type' => $payload['chat_type'] ?? null,
            'provider_message_id' => $payload['message_id'] ?? null,
            'provider_waid' => $payload['waid'] ?? null,
            'group_id' => $payload['group_id'] ?? null,
            'message_type' => $payload['message_type'] ?? null,
            'message_state' => $payload['message_state'] ?? null,
            'context_type' => $payload['context_type'] ?? null,
            'context_waid' => $payload['context_waid'] ?? null,
            'received_unix_time' => $payload['received_time'] ?? null,
            'provider_payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistWebhookEvent(string $event, array $payload, string $processStatus, ?string $resultMessage): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO recruit_whatsgw_webhook_events (
                event_type,
                phone_number,
                contact_phone_number,
                provider_message_id,
                provider_waid,
                process_status,
                result_message,
                payload
             ) VALUES (
                :event_type,
                :phone_number,
                :contact_phone_number,
                :provider_message_id,
                :provider_waid,
                :process_status,
                :result_message,
                :payload
             )'
        );

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $statement->execute([
            'event_type' => $event,
            'phone_number' => isset($payload['phone_number']) ? trim((string) $payload['phone_number']) : null,
            'contact_phone_number' => isset($payload['contact_phone_number']) ? trim((string) $payload['contact_phone_number']) : null,
            'provider_message_id' => isset($payload['message_id']) ? trim((string) $payload['message_id']) : null,
            'provider_waid' => isset($payload['waid']) ? trim((string) $payload['waid']) : null,
            'process_status' => in_array($processStatus, ['processed', 'ignored', 'failed'], true) ? $processStatus : 'processed',
            'result_message' => $resultMessage,
            'payload' => $encodedPayload === false ? '{}' : $encodedPayload,
        ]);
    }
}
