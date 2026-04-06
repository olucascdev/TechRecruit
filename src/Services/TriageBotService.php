<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use PDO;
use TechRecruit\Database;
use TechRecruit\Models\CandidateModel;
use TechRecruit\Models\TriageModel;

final class TriageBotService
{
    public const AUTOMATION_TYPE = 'triage_w13';
    public const FLOW_VERSION = '1.0.0';

    private PDO $pdo;

    private TriageModel $triageModel;

    private CandidateModel $candidateModel;

    public function __construct(
        ?TriageModel $triageModel = null,
        ?PDO $pdo = null,
        ?CandidateModel $candidateModel = null
    ) {
        $this->pdo = $pdo ?? Database::connect();
        $this->triageModel = $triageModel ?? new TriageModel($this->pdo);
        $this->candidateModel = $candidateModel ?? new CandidateModel($this->pdo);
    }

    public function isTriageAutomationType(string $automationType): bool
    {
        return $automationType === self::AUTOMATION_TYPE;
    }

    /**
     * @return array<string, mixed>
     */
    public function activateInitialSession(
        int $campaignId,
        int $campaignRecipientId,
        int $candidateId,
        string $initialMessage
    ): array {
        $session = $this->triageModel->findSessionByCampaignRecipientId($campaignRecipientId);
        $now = date('Y-m-d H:i:s');

        if ($session === null) {
            $sessionId = $this->triageModel->createSession([
                'campaign_id' => $campaignId,
                'campaign_recipient_id' => $campaignRecipientId,
                'candidate_id' => $candidateId,
                'flow_version' => self::FLOW_VERSION,
                'triage_status' => 'sent',
                'current_step' => 'initial_offer',
                'automation_status' => 'active',
                'needs_operator' => false,
                'invalid_reply_count' => 0,
                'fallback_reason' => null,
                'collected_data' => $this->defaultCollectedData(),
                'last_inbound_message' => null,
                'last_outbound_message' => $initialMessage,
                'last_interaction_at' => $now,
            ]);

            $this->triageModel->logAnswer(
                $sessionId,
                'initial_offer',
                'outbound',
                $initialMessage,
                ['source' => 'campaign_initial_send'],
                'system'
            );

            return $this->requireSession($campaignRecipientId);
        }

        $this->triageModel->updateSession((int) $session['id'], [
            'flow_version' => self::FLOW_VERSION,
            'last_outbound_message' => $initialMessage,
            'last_interaction_at' => $now,
        ]);

        $this->triageModel->logAnswer(
            (int) $session['id'],
            'initial_offer',
            'outbound',
            $initialMessage,
            ['source' => 'campaign_initial_send'],
            'system'
        );

        return $this->requireSession($campaignRecipientId);
    }

    /**
     * @return array<string, mixed>
     */
    public function handleInbound(
        int $campaignId,
        int $campaignRecipientId,
        int $candidateId,
        string $messageBody,
        string $operator
    ): array {
        $messageBody = trim($messageBody);
        $session = $this->triageModel->findSessionByCampaignRecipientId($campaignRecipientId);

        if ($session === null) {
            $session = $this->activateInitialSession(
                $campaignId,
                $campaignRecipientId,
                $candidateId,
                $this->buildInitialOfferMessage('')
            );
        }

        $parsedIntent = $this->resolveIntent($messageBody);
        $currentStep = (string) ($session['current_step'] ?? 'initial_offer');

        $this->triageModel->logAnswer(
            (int) $session['id'],
            $currentStep,
            'inbound',
            $messageBody,
            ['parsed_intent' => $parsedIntent],
            $operator
        );

        if ($parsedIntent === 'opt_out') {
            return $this->closeSession(
                $session,
                'not_interested',
                'completed',
                $messageBody,
                $this->buildNotInterestedMessage(),
                [
                    'parsed_intent' => 'opt_out',
                    'candidate_status' => 'not_interested',
                    'metadata' => ['flow_status' => 'sem_interesse'],
                ]
            );
        }

        if ($currentStep === 'initial_offer') {
            return $this->handleInitialOfferStep($session, $messageBody, $parsedIntent);
        }

        if ($currentStep === 'completed') {
            return $this->buildStaticResult($session, [
                'parsed_intent' => $parsedIntent,
                'triage_status' => (string) $session['triage_status'],
                'current_step' => 'completed',
                'automation_status' => 'completed',
                'needs_operator' => false,
                'candidate_status' => null,
                'auto_reply' => null,
                'metadata' => ['session_closed' => true],
            ]);
        }

        return $this->handoffToOperator($session, $messageBody, 'Entrada fora do fluxo esperado.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSessionByContact(string $contact, ?int $campaignId = null): ?array
    {
        $normalizedContact = $this->normalizePhoneDigits($contact);

        if ($normalizedContact === '') {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $normalizedContact,
            strlen($normalizedContact) > 11 ? substr($normalizedContact, -11) : null,
            strlen($normalizedContact) > 10 ? substr($normalizedContact, -10) : null,
        ])));

        foreach ($candidates as $candidateContact) {
            $session = $this->triageModel->findLatestSessionByContact($candidateContact, $campaignId);

            if ($session !== null) {
                return $session;
            }
        }

        return null;
    }

    public function buildInitialOfferMessage(string $cityLabel): string
    {
        return trim(
            "W13 - Cadastro de Técnicos de Campo\n\n" .
            "Olá, tudo bem?\n\n" .
            "Estamos expandindo nossa operação nacional e buscamos técnicos parceiros para atendimentos em campo.\n" .
            "\nVocê tem interesse em prestar serviços para a W13?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO"
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handleInitialOfferStep(array $session, string $messageBody, string $parsedIntent): array
    {
        $option = $this->parseMenuOption($messageBody, ['1', '2']);

        if ($option === null) {
            if ($parsedIntent === 'interested') {
                $option = '1';
            } elseif ($parsedIntent === 'not_interested') {
                $option = '2';
            }
        }

        if ($option === '1') {
            $reply = "Ótimo! Acesse o formulário de cadastro pelo link abaixo:\n\nhttps://docs.google.com/forms/d/e/1FAIpQLSdQXERb1vW2LP9wCNRzrL4-8tPYzfaM2Ib3PjvSjKEEI_9RIQ/viewform";

            return $this->closeSession(
                $session,
                'interested',
                'completed',
                $messageBody,
                $reply,
                [
                    'parsed_intent' => 'interested',
                    'candidate_status' => 'interested',
                    'metadata' => ['flow_status' => 'forms_link_sent'],
                ]
            );
        }

        if ($option === '2') {
            return $this->closeSession(
                $session,
                'not_interested',
                'completed',
                $messageBody,
                $this->buildNotInterestedMessage(),
                [
                    'parsed_intent' => 'not_interested',
                    'candidate_status' => 'not_interested',
                    'metadata' => ['flow_status' => 'sem_interesse'],
                ]
            );
        }

        return $this->handleInvalidStepReply(
            $session,
            $messageBody,
            'initial_offer',
            $this->buildInitialOfferRetryMessage(),
            'Resposta invalida no menu inicial.'
        );
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function advanceSession(array $session, array $attributes, array $result): array
    {
        $this->triageModel->updateSession((int) $session['id'], $attributes);

        if (isset($result['auto_reply']) && is_string($result['auto_reply']) && $result['auto_reply'] !== '') {
            $stepKey = (string) ($attributes['current_step'] ?? $session['current_step']);
            $metadata = $result['metadata'] ?? [];

            $this->triageModel->logAnswer(
                (int) $session['id'],
                $stepKey,
                'outbound',
                $result['auto_reply'],
                is_array($metadata) ? $metadata : [],
                'triage_bot'
            );
        }

        return $this->buildStaticResult($session, $result, $attributes);
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function closeSession(
        array $session,
        string $triageStatus,
        string $currentStep,
        string $messageBody,
        string $reply,
        array $result
    ): array {
        return $this->advanceSession(
            $session,
            [
                'triage_status' => $triageStatus,
                'current_step' => $currentStep,
                'automation_status' => 'completed',
                'needs_operator' => false,
                'invalid_reply_count' => 0,
                'fallback_reason' => null,
                'last_inbound_message' => $messageBody,
                'last_outbound_message' => $reply,
                'last_interaction_at' => date('Y-m-d H:i:s'),
            ],
            array_merge($result, [
                'triage_status' => $triageStatus,
                'current_step' => $currentStep,
                'automation_status' => 'completed',
                'needs_operator' => false,
                'auto_reply' => $reply,
            ])
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handoffToOperator(array $session, string $messageBody, string $reason): array
    {
        $reply = $this->buildOperatorFallbackMessage();

        return $this->advanceSession(
            $session,
            [
                'current_step' => 'operator_fallback',
                'automation_status' => 'waiting_operator',
                'needs_operator' => true,
                'fallback_reason' => $reason,
                'last_inbound_message' => $messageBody,
                'last_outbound_message' => $reply,
                'last_interaction_at' => date('Y-m-d H:i:s'),
            ],
            [
                'parsed_intent' => 'unknown',
                'triage_status' => (string) $session['triage_status'],
                'current_step' => 'operator_fallback',
                'automation_status' => 'waiting_operator',
                'needs_operator' => true,
                'candidate_status' => null,
                'auto_reply' => $reply,
                'metadata' => ['fallback_reason' => $reason],
            ]
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handleInvalidStepReply(
        array $session,
        string $messageBody,
        string $stepKey,
        string $retryMessage,
        string $fallbackReason
    ): array {
        $invalidReplyCount = ((int) ($session['invalid_reply_count'] ?? 0)) + 1;

        if ($invalidReplyCount >= 2) {
            return $this->handoffToOperator($session, $messageBody, $fallbackReason);
        }

        return $this->advanceSession(
            $session,
            [
                'invalid_reply_count' => $invalidReplyCount,
                'last_inbound_message' => $messageBody,
                'last_outbound_message' => $retryMessage,
                'last_interaction_at' => date('Y-m-d H:i:s'),
            ],
            [
                'parsed_intent' => 'unknown',
                'triage_status' => (string) $session['triage_status'],
                'current_step' => $stepKey,
                'automation_status' => (string) $session['automation_status'],
                'needs_operator' => (bool) $session['needs_operator'],
                'candidate_status' => null,
                'auto_reply' => $retryMessage,
                'metadata' => ['invalid_reply_count' => $invalidReplyCount],
            ]
        );
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $result
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function buildStaticResult(array $session, array $result, array $attributes = []): array
    {
        return [
            'parsed_intent' => $result['parsed_intent'] ?? 'unknown',
            'triage_status' => $result['triage_status'] ?? $attributes['triage_status'] ?? $session['triage_status'],
            'current_step' => $result['current_step'] ?? $attributes['current_step'] ?? $session['current_step'],
            'automation_status' => $result['automation_status'] ?? $attributes['automation_status'] ?? $session['automation_status'],
            'needs_operator' => $result['needs_operator'] ?? $attributes['needs_operator'] ?? $session['needs_operator'],
            'candidate_status' => $result['candidate_status'] ?? null,
            'auto_reply' => $result['auto_reply'] ?? null,
            'metadata' => $result['metadata'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultCollectedData(): array
    {
        return [
            'flow_status' => 'lead_novo',
            'last_prompt' => 'initial_offer',
        ];
    }

    private function resolveIntent(string $messageBody): string
    {
        $normalized = $this->normalizeFreeText($messageBody);

        if (
            str_contains($normalized, 'sair')
            || str_contains($normalized, 'stop')
            || str_contains($normalized, 'remover')
            || str_contains($normalized, 'descadastrar')
        ) {
            return 'opt_out';
        }

        if (
            $normalized === '2'
            || str_contains($normalized, 'nao')
            || str_contains($normalized, 'sem interesse')
        ) {
            return 'not_interested';
        }

        if (
            $normalized === '1'
            || $normalized === 'sim'
            || str_contains($normalized, 'tenho interesse')
            || str_contains($normalized, 'quero')
            || str_contains($normalized, 'confirmo')
        ) {
            return 'interested';
        }

        return 'unknown';
    }

    /**
     * @param list<string> $allowed
     */
    private function parseMenuOption(string $messageBody, array $allowed): ?string
    {
        $normalized = $this->normalizeFreeText($messageBody);

        if (in_array($normalized, $allowed, true)) {
            return $normalized;
        }

        if (preg_match('/(^|\D)([1-9])(\D|$)/', $normalized, $matches) === 1) {
            $option = $matches[2];

            return in_array($option, $allowed, true) ? $option : null;
        }

        if (preg_match('/([1-9])/', $normalized, $matches) === 1) {
            $option = $matches[1];

            return in_array($option, $allowed, true) ? $option : null;
        }

        return null;
    }

    private function normalizeFreeText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function normalizePhoneDigits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function requireSession(int $campaignRecipientId): array
    {
        return $this->triageModel->findSessionByCampaignRecipientId($campaignRecipientId) ?? [];
    }

    private function buildNotInterestedMessage(): string
    {
        return "Sem problemas. Agradecemos seu retorno.\n\n" .
            "Caso tenha interesse futuramente, estaremos à disposição.\n" .
            "Equipe W13";
    }

    private function buildInitialOfferRetryMessage(): string
    {
        return "Não consegui identificar sua opção.\n\n" .
            "Responda com:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildOperatorFallbackMessage(): string
    {
        return "Recebi sua mensagem e vou encaminhar o atendimento para um operador da W13 continuar por aqui.";
    }
}
