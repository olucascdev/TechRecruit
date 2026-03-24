<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use InvalidArgumentException;

final class WhatsGwClient
{
    private const DEFAULT_COUNTRY_CODE = '55';

    private string $baseUrl;

    private ?string $apiKey;

    private ?string $webhookApiKey;

    private ?string $phoneNumber;

    private ?int $instanceId;

    private int $checkStatus;

    private int $simulateTyping;

    private int $timeoutSeconds;

    private string $defaultCountryCode;

    public function __construct()
    {
        $this->baseUrl = rtrim($this->env('WHATSGW_BASE_URL', 'https://app.whatsgw.com.br/api/WhatsGw'), '/');
        $this->apiKey = $this->normalizeNullableString($this->env('WHATSGW_API_KEY'));
        $this->webhookApiKey = $this->normalizeNullableString($this->env('WHATSGW_WEBHOOK_API_KEY', $this->apiKey));
        $this->defaultCountryCode = $this->normalizeCountryCode($this->env('WHATSGW_DEFAULT_COUNTRY_CODE', self::DEFAULT_COUNTRY_CODE));
        $this->phoneNumber = $this->normalizePhone($this->env('WHATSGW_PHONE_NUMBER'));
        $this->instanceId = $this->normalizeNullableInt($this->env('WHATSGW_INSTANCE_ID'));
        $this->checkStatus = $this->normalizeFlag($this->env('WHATSGW_CHECK_STATUS', '1'));
        $this->simulateTyping = $this->normalizeFlag($this->env('WHATSGW_SIMULATE_TYPING', '0'));
        $this->timeoutSeconds = max(5, (int) $this->env('WHATSGW_TIMEOUT_SECONDS', '30'));
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && ($this->phoneNumber !== null || $this->instanceId !== null);
    }

    public function hasWebhookApiKey(): bool
    {
        return $this->webhookApiKey !== null;
    }

    public function matchesApiKey(?string $incomingApiKey): bool
    {
        if ($this->webhookApiKey === null || $incomingApiKey === null) {
            return false;
        }

        return hash_equals($this->webhookApiKey, trim($incomingApiKey));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendTextMessage(string $contactPhoneNumber, string $messageBody, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new InvalidArgumentException('WhatsGW não configurado. Defina WHATSGW_API_KEY e WHATSGW_PHONE_NUMBER ou WHATSGW_INSTANCE_ID.');
        }

        $contactPhoneNumber = $this->normalizePhone($contactPhoneNumber);
        $messageBody = trim($messageBody);

        if ($contactPhoneNumber === null || $contactPhoneNumber === '') {
            throw new InvalidArgumentException('Contato de destino inválido para envio no WhatsGW.');
        }

        if ($messageBody === '') {
            throw new InvalidArgumentException('Mensagem vazia para envio no WhatsGW.');
        }

        $payload = [
            'apikey' => $this->apiKey,
            'phone_number' => $this->phoneNumber ?? '',
            'contact_phone_number' => $contactPhoneNumber,
            'message_custom_id' => $options['message_custom_id'] ?? null,
            'message_type' => 'text',
            'message_body' => $messageBody,
            'check_status' => $options['check_status'] ?? $this->checkStatus,
            'simule_typing' => $options['simule_typing'] ?? $this->simulateTyping,
        ];

        if ($this->instanceId !== null) {
            $payload['w_instancia_id'] = $this->instanceId;
        }

        $response = $this->postJson('/Send', $payload);

        return array_merge($response, [
            'request_payload' => $payload,
            'message_custom_id' => $payload['message_custom_id'],
            'provider_message_id' => $this->extractProviderValue($response['decoded_body'], ['message_id']),
            'provider_waid' => $this->extractProviderValue($response['decoded_body'], ['waid']),
        ]);
    }

    /**
     * @param array<string, mixed> $interactivePayload
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function sendInteractiveMessage(
        string $contactPhoneNumber,
        string $messageBody,
        array $interactivePayload,
        array $options = []
    ): array {
        if (!$this->isConfigured()) {
            throw new InvalidArgumentException('WhatsGW não configurado. Defina WHATSGW_API_KEY e WHATSGW_PHONE_NUMBER ou WHATSGW_INSTANCE_ID.');
        }

        $contactPhoneNumber = $this->normalizePhone($contactPhoneNumber);
        $messageBody = trim($messageBody);

        if ($contactPhoneNumber === null || $contactPhoneNumber === '') {
            throw new InvalidArgumentException('Contato de destino inválido para envio no WhatsGW.');
        }

        if ($messageBody === '') {
            throw new InvalidArgumentException('Mensagem vazia para envio no WhatsGW.');
        }

        if ($interactivePayload === []) {
            throw new InvalidArgumentException('Payload interativo vazio para envio no WhatsGW.');
        }

        $payload = [
            'apikey' => $this->apiKey,
            'phone_number' => $this->phoneNumber ?? '',
            'contact_phone_number' => $contactPhoneNumber,
            'message_custom_id' => $options['message_custom_id'] ?? null,
            'message_type' => 'text',
            'message_body' => $messageBody,
            'check_status' => $options['check_status'] ?? $this->checkStatus,
            'simule_typing' => $options['simule_typing'] ?? $this->simulateTyping,
        ];

        if ($this->instanceId !== null) {
            $payload['w_instancia_id'] = $this->instanceId;
        }

        foreach (['buttons', 'listButton'] as $key) {
            if (array_key_exists($key, $interactivePayload)) {
                $payload[$key] = $interactivePayload[$key];
            }
        }

        if (!array_key_exists('buttons', $payload) && !array_key_exists('listButton', $payload)) {
            throw new InvalidArgumentException('Payload interativo inválido para o WhatsGW.');
        }

        $response = $this->postJson('/Send', $payload);

        return array_merge($response, [
            'request_payload' => $payload,
            'message_custom_id' => $payload['message_custom_id'],
            'provider_message_id' => $this->extractProviderValue($response['decoded_body'], ['message_id']),
            'provider_waid' => $this->extractProviderValue($response['decoded_body'], ['waid']),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new InvalidArgumentException('Extensão cURL não disponível para integrar com o WhatsGW.');
        }

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encodedPayload === false) {
            throw new InvalidArgumentException('Falha ao serializar o payload de envio para o WhatsGW.');
        }

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->baseUrl . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
        ]);

        $rawBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($rawBody === false) {
            throw new InvalidArgumentException(
                trim($curlError) !== '' ? $curlError : 'Falha desconhecida ao chamar o WhatsGW.'
            );
        }

        $decodedBody = json_decode((string) $rawBody, true);
        $decodedPayload = is_array($decodedBody) ? $decodedBody : [];

        return [
            'success' => $this->isSuccessfulResponse($httpStatus, $decodedPayload),
            'http_status' => $httpStatus,
            'raw_body' => (string) $rawBody,
            'decoded_body' => $decodedPayload,
        ];
    }

    /**
     * @param array<string, mixed> $decodedBody
     */
    private function isSuccessfulResponse(int $httpStatus, array $decodedBody): bool
    {
        if ($httpStatus < 200 || $httpStatus >= 300) {
            return false;
        }

        if ($decodedBody === []) {
            return true;
        }

        $result = strtolower(trim((string) ($decodedBody['result'] ?? '')));
        $status = strtolower(trim((string) ($decodedBody['status'] ?? '')));
        $phoneState = strtolower(trim((string) ($decodedBody['phone_state'] ?? '')));
        $providerMessageId = $this->extractProviderValue($decodedBody, ['message_id']);

        return ($decodedBody['ok'] ?? null) === true
            || ($decodedBody['success'] ?? null) === true
            || ($decodedBody['result'] ?? null) === true
            || ($decodedBody['status'] ?? null) === true
            || in_array($result, ['success', 'ok', 'sent', 'queued'], true)
            || in_array($status, ['success', 'ok', 'sent', 'queued'], true)
            || (
                $providerMessageId !== null
                && ($phoneState === '' || in_array($phoneState, ['conectado', 'connected'], true))
            );
    }

    /**
     * @param array<string, mixed> $decodedBody
     * @param list<string> $path
     */
    private function extractProviderValue(array $decodedBody, array $path): ?string
    {
        $directKey = $path[0] ?? null;

        if (is_string($directKey) && array_key_exists($directKey, $decodedBody)) {
            $value = trim((string) $decodedBody[$directKey]);

            return $value !== '' ? $value : null;
        }

        foreach (['data', 'result'] as $containerKey) {
            $nested = $decodedBody[$containerKey] ?? null;

            if (!is_array($nested) || !is_string($directKey) || !array_key_exists($directKey, $nested)) {
                continue;
            }

            $value = trim((string) $nested[$directKey]);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null || trim((string) $value) === '') {
            return $default;
        }

        return trim((string) $value);
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeNullableInt(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    private function normalizeFlag(?string $value): int
    {
        return in_array(trim((string) $value), ['1', 'true', 'yes'], true) ? 1 : 0;
    }

    private function normalizePhone(?string $phoneNumber): ?string
    {
        if ($phoneNumber === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phoneNumber);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, $this->defaultCountryCode) && strlen($digits) >= 12) {
            return $digits;
        }

        // Accept BR contacts informed as DDD+numero and normalize to country+DDD+numero.
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return $this->defaultCountryCode . $digits;
        }

        return strlen($digits) >= 12 ? $digits : null;
    }

    private function normalizeCountryCode(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits !== null && $digits !== '' ? $digits : self::DEFAULT_COUNTRY_CODE;
    }
}
