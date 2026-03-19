<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use PDO;
use TechRecruit\Database;
use TechRecruit\Models\TriageModel;

final class TriageBotService
{
    public const AUTOMATION_TYPE = 'triage_w13';
    public const FLOW_VERSION = '0.3.0';

    private PDO $pdo;

    private TriageModel $triageModel;

    public function __construct(?TriageModel $triageModel = null, ?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connect();
        $this->triageModel = $triageModel ?? new TriageModel($this->pdo);
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
                'collected_data' => [
                    'last_prompt' => 'initial_offer',
                ],
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

        $collectedData = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
        $collectedData['last_prompt'] = 'initial_offer';

        $this->triageModel->updateSession((int) $session['id'], [
            'triage_status' => in_array((string) $session['triage_status'], ['awaiting_validation', 'approved', 'rejected_unavailable'], true)
                ? $session['triage_status']
                : 'sent',
            'current_step' => in_array((string) $session['current_step'], ['waiting_validation', 'approval_confirmation', 'completed'], true)
                ? $session['current_step']
                : 'initial_offer',
            'automation_status' => (string) ($session['automation_status'] ?? 'active') === 'completed'
                ? 'completed'
                : 'active',
            'needs_operator' => false,
            'invalid_reply_count' => 0,
            'fallback_reason' => null,
            'collected_data' => $collectedData,
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
                $this->buildInitialOfferMessage('[CIDADE/UF]')
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
                ]
            );
        }

        return match ($currentStep) {
            'initial_offer' => $this->handleInitialOfferStep($session, $messageBody, $parsedIntent),
            'details_followup' => $this->handleDetailsFollowupStep($session, $messageBody, $parsedIntent),
            'qualification' => $this->handleQualificationStep($session, $messageBody, $parsedIntent),
            'approval_confirmation' => $this->handleApprovalConfirmationStep($session, $messageBody, $parsedIntent),
            'waiting_validation' => $this->handoffToOperator(
                $session,
                $messageBody,
                'Candidato respondeu novamente enquanto aguardava validacao.'
            ),
            'completed' => $this->buildStaticResult($session, [
                'parsed_intent' => $parsedIntent,
                'triage_status' => (string) $session['triage_status'],
                'current_step' => 'completed',
                'automation_status' => 'completed',
                'needs_operator' => false,
                'candidate_status' => null,
                'auto_reply' => null,
                'metadata' => ['session_closed' => true],
            ]),
            default => $this->handoffToOperator(
                $session,
                $messageBody,
                'Entrada fora do fluxo esperado.'
            ),
        };
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
        return trim(sprintf(
            "Ola, tudo bem?\n\nAqui e da W13 Tecnologia. Estamos com oportunidade de atendimento tecnico na sua regiao.\n\nAtividade: atendimento de campo\nValor: R\$200 por atividade concluida\nTempo medio: ate 2h\nRegiao: %s\n\nVoce tem interesse e disponibilidade para esse atendimento?\n\nResponda:\n1 - SIM, tenho interesse\n2 - NAO tenho interesse\n3 - Talvez, preciso de mais detalhes\n\nEquipe W13 Tecnologia",
            $cityLabel
        ));
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handleInitialOfferStep(array $session, string $messageBody, string $parsedIntent): array
    {
        if ($this->isInterestedReply($messageBody, $parsedIntent)) {
            $reply = $this->buildQualificationPromptMessage();
            $collectedData = $this->mergeCollectedData($session, [
                'interest_reply' => $messageBody,
                'last_prompt' => 'qualification',
            ]);

            return $this->advanceSession(
                $session,
                [
                    'triage_status' => 'interested',
                    'current_step' => 'qualification',
                    'automation_status' => 'active',
                    'needs_operator' => false,
                    'invalid_reply_count' => 0,
                    'fallback_reason' => null,
                    'collected_data' => $collectedData,
                    'last_inbound_message' => $messageBody,
                    'last_outbound_message' => $reply,
                    'last_interaction_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'parsed_intent' => 'interested',
                    'candidate_status' => 'interested',
                    'auto_reply' => $reply,
                    'metadata' => ['transition' => 'qualification'],
                ]
            );
        }

        if ($this->isNotInterestedReply($messageBody, $parsedIntent)) {
            return $this->closeSession(
                $session,
                'not_interested',
                'completed',
                $messageBody,
                $this->buildNotInterestedMessage(),
                [
                    'parsed_intent' => 'not_interested',
                    'candidate_status' => 'not_interested',
                ]
            );
        }

        if ($this->isNeedsDetailsReply($messageBody, $parsedIntent)) {
            $reply = $this->buildDetailsMessage();
            $collectedData = $this->mergeCollectedData($session, [
                'interest_reply' => $messageBody,
                'last_prompt' => 'details_followup',
            ]);

            return $this->advanceSession(
                $session,
                [
                    'triage_status' => 'needs_details',
                    'current_step' => 'details_followup',
                    'automation_status' => 'active',
                    'needs_operator' => false,
                    'invalid_reply_count' => 0,
                    'fallback_reason' => null,
                    'collected_data' => $collectedData,
                    'last_inbound_message' => $messageBody,
                    'last_outbound_message' => $reply,
                    'last_interaction_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'parsed_intent' => 'needs_details',
                    'candidate_status' => 'responded',
                    'auto_reply' => $reply,
                    'metadata' => ['transition' => 'details_followup'],
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
     * @return array<string, mixed>
     */
    private function handleDetailsFollowupStep(array $session, string $messageBody, string $parsedIntent): array
    {
        if ($this->isInterestedReply($messageBody, $parsedIntent)) {
            $reply = $this->buildQualificationPromptMessage();
            $collectedData = $this->mergeCollectedData($session, [
                'details_confirmation_reply' => $messageBody,
                'last_prompt' => 'qualification',
            ]);

            return $this->advanceSession(
                $session,
                [
                    'triage_status' => 'interested',
                    'current_step' => 'qualification',
                    'automation_status' => 'active',
                    'needs_operator' => false,
                    'invalid_reply_count' => 0,
                    'fallback_reason' => null,
                    'collected_data' => $collectedData,
                    'last_inbound_message' => $messageBody,
                    'last_outbound_message' => $reply,
                    'last_interaction_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'parsed_intent' => 'interested',
                    'candidate_status' => 'interested',
                    'auto_reply' => $reply,
                    'metadata' => ['transition' => 'qualification'],
                ]
            );
        }

        if ($this->isNotInterestedReply($messageBody, $parsedIntent)) {
            return $this->closeSession(
                $session,
                'not_interested',
                'completed',
                $messageBody,
                $this->buildNotInterestedMessage(),
                [
                    'parsed_intent' => 'not_interested',
                    'candidate_status' => 'not_interested',
                ]
            );
        }

        return $this->handleInvalidStepReply(
            $session,
            $messageBody,
            'details_followup',
            $this->buildDetailsRetryMessage(),
            'Resposta invalida apos envio de detalhes.'
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handleQualificationStep(array $session, string $messageBody, string $parsedIntent): array
    {
        $qualificationData = $this->extractQualificationData($messageBody);

        if ($this->isQualificationReplyValid($qualificationData, $messageBody)) {
            $reply = $this->buildQualificationAckMessage();
            $collectedData = $this->mergeCollectedData($session, [
                'qualification' => $qualificationData,
                'qualification_raw_reply' => $messageBody,
                'last_prompt' => 'waiting_validation',
            ]);

            return $this->advanceSession(
                $session,
                [
                    'triage_status' => 'awaiting_validation',
                    'current_step' => 'waiting_validation',
                    'automation_status' => 'active',
                    'needs_operator' => false,
                    'invalid_reply_count' => 0,
                    'fallback_reason' => null,
                    'collected_data' => $collectedData,
                    'last_inbound_message' => $messageBody,
                    'last_outbound_message' => $reply,
                    'last_interaction_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'parsed_intent' => 'interested',
                    'candidate_status' => 'interested',
                    'auto_reply' => $reply,
                    'metadata' => [
                        'transition' => 'waiting_validation',
                        'captured_fields' => array_keys(array_filter(
                            $qualificationData,
                            static fn (mixed $value): bool => $value !== null && $value !== ''
                        )),
                    ],
                ]
            );
        }

        return $this->handleInvalidStepReply(
            $session,
            $messageBody,
            'qualification',
            $this->buildQualificationRetryMessage(),
            'Qualificacao enviada em formato insuficiente.'
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handleApprovalConfirmationStep(array $session, string $messageBody, string $parsedIntent): array
    {
        if ($this->isInterestedReply($messageBody, $parsedIntent)) {
            return $this->closeSession(
                $session,
                'approved',
                'completed',
                $messageBody,
                $this->buildApprovalAcceptedMessage(),
                [
                    'parsed_intent' => 'interested',
                    'candidate_status' => null,
                ]
            );
        }

        if ($this->isNotInterestedReply($messageBody, $parsedIntent)) {
            return $this->closeSession(
                $session,
                'rejected_unavailable',
                'completed',
                $messageBody,
                $this->buildApprovalDeclinedMessage(),
                [
                    'parsed_intent' => 'not_interested',
                    'candidate_status' => 'not_interested',
                ]
            );
        }

        return $this->handleInvalidStepReply(
            $session,
            $messageBody,
            'approval_confirmation',
            "Confirma disponibilidade?\n\nResponda:\n1 - CONFIRMO\n2 - NAO POSSO",
            'Resposta invalida na confirmacao da atividade.'
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
                'collected_data' => $this->mergeCollectedData($session, [
                    'last_prompt' => $currentStep,
                ]),
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
                'metadata' => ['transition' => 'completed'],
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
     * @param array<string, mixed> $session
     * @param array<string, mixed> $newData
     * @return array<string, mixed>
     */
    private function mergeCollectedData(array $session, array $newData): array
    {
        $currentData = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];

        return array_replace_recursive($currentData, $newData);
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

        if ($normalized === '3' || str_contains($normalized, 'mais detalhes') || str_contains($normalized, 'preciso de detalhes')) {
            return 'needs_details';
        }

        if (
            $normalized === '2'
            || str_contains($normalized, 'nao')
            || str_contains($normalized, 'sem interesse')
            || str_contains($normalized, 'nao posso')
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

    private function isInterestedReply(string $messageBody, string $parsedIntent): bool
    {
        $normalized = $this->normalizeFreeText($messageBody);

        return $parsedIntent === 'interested'
            || in_array($normalized, ['1', 'sim', 'confirmo'], true);
    }

    private function isNotInterestedReply(string $messageBody, string $parsedIntent): bool
    {
        $normalized = $this->normalizeFreeText($messageBody);

        return $parsedIntent === 'not_interested'
            || in_array($normalized, ['2', 'nao', 'nao posso'], true);
    }

    private function isNeedsDetailsReply(string $messageBody, string $parsedIntent): bool
    {
        $normalized = $this->normalizeFreeText($messageBody);

        return $parsedIntent === 'needs_details'
            || in_array($normalized, ['3', 'talvez'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractQualificationData(string $messageBody): array
    {
        $data = [
            'full_name' => null,
            'city' => null,
            'state' => null,
            'phone' => null,
            'has_notebook' => null,
            'has_console_cable' => null,
            'immediate_availability' => null,
            'can_pickup_at_base' => null,
        ];

        $lines = preg_split('/\r\n|\r|\n/', trim($messageBody)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\s*[-*]?\s*([^:]+?)\s*[:\-]\s*(.+)\s*$/u', $line, $matches) !== 1) {
                continue;
            }

            $label = $this->normalizeFreeText($matches[1]);
            $value = trim($matches[2]);

            if ($label === '') {
                continue;
            }

            if ($this->containsAny($label, ['nome completo', 'nome']) && $data['full_name'] === null) {
                $data['full_name'] = $value;
                continue;
            }

            if ($this->containsAny($label, ['cidade atual', 'cidade']) && $data['city'] === null) {
                [$city, $state] = $this->splitCityAndState($value);
                $data['city'] = $city;
                $data['state'] = $data['state'] ?? $state;
                continue;
            }

            if ($this->containsAny($label, ['uf', 'estado']) && $data['state'] === null) {
                $state = $this->extractState($value);

                if ($state !== null) {
                    $data['state'] = $state;
                }

                continue;
            }

            if ($this->containsAny($label, ['telefone', 'whatsapp', 'celular']) && $data['phone'] === null) {
                $phone = $this->normalizePhoneDigits($value);
                $data['phone'] = $phone !== '' ? $phone : null;
                continue;
            }

            if ($this->containsAny($label, ['possui notebook', 'notebook'])) {
                $data['has_notebook'] = $this->parseBooleanValue($value);
                continue;
            }

            if ($this->containsAny($label, ['possui cabo console', 'cabo console'])) {
                $data['has_console_cable'] = $this->parseBooleanValue($value);
                continue;
            }

            if ($this->containsAny($label, ['disponibilidade imediata', 'disponibilidade'])) {
                $data['immediate_availability'] = $this->parseBooleanValue($value);
                continue;
            }

            if ($this->containsAny($label, ['retirar equipamento', 'retirar em base', 'pode retirar em base'])) {
                $data['can_pickup_at_base'] = $this->parseBooleanValue($value);
            }
        }

        if ($data['phone'] === null) {
            $phone = $this->extractPhoneFromMessage($messageBody);
            $data['phone'] = $phone !== '' ? $phone : null;
        }

        if ($data['state'] === null) {
            $state = $this->extractState($messageBody);

            if ($state !== null) {
                $data['state'] = $state;
            }
        }

        if ($data['full_name'] === null) {
            $data['full_name'] = $this->extractPossibleName($lines);
        }

        if ($data['city'] === null) {
            $data['city'] = $this->extractPossibleCity($lines);
        }

        if ($data['has_notebook'] === null) {
            $data['has_notebook'] = $this->parseBooleanFromWholeMessage($messageBody, 'notebook');
        }

        if ($data['has_console_cable'] === null) {
            $data['has_console_cable'] = $this->parseBooleanFromWholeMessage($messageBody, 'cabo console');
        }

        if ($data['immediate_availability'] === null) {
            $data['immediate_availability'] = $this->parseBooleanFromWholeMessage($messageBody, 'disponibilidade');
        }

        if ($data['can_pickup_at_base'] === null) {
            $data['can_pickup_at_base'] = $this->parseBooleanFromWholeMessage($messageBody, 'retirar');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $qualificationData
     */
    private function isQualificationReplyValid(array $qualificationData, string $messageBody): bool
    {
        $filledFields = count(array_filter(
            $qualificationData,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        ));

        if ($filledFields >= 4) {
            return true;
        }

        return mb_strlen(trim($messageBody)) >= 40 && ($filledFields >= 2);
    }

    private function containsAny(string $subject, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($subject, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeFreeText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a',
            'à' => 'a',
            'ã' => 'a',
            'â' => 'a',
            'é' => 'e',
            'ê' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);

        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function parseBooleanValue(string $value): ?bool
    {
        $normalized = $this->normalizeFreeText($value);

        if ($normalized === '') {
            return null;
        }

        if (
            str_contains($normalized, 'nao tenho')
            || str_contains($normalized, 'nao')
            || str_contains($normalized, 'sem')
            || str_contains($normalized, 'indisponivel')
        ) {
            return false;
        }

        if (
            str_contains($normalized, 'sim')
            || str_contains($normalized, 'tenho')
            || str_contains($normalized, 'possuo')
            || str_contains($normalized, 'disponivel')
            || str_contains($normalized, 'consigo')
        ) {
            return true;
        }

        return null;
    }

    private function parseBooleanFromWholeMessage(string $messageBody, string $keyword): ?bool
    {
        $normalized = $this->normalizeFreeText($messageBody);

        if (!str_contains($normalized, $keyword)) {
            return null;
        }

        $segments = preg_split('/\r\n|\r|\n|,|;/', $normalized) ?: [];

        foreach ($segments as $segment) {
            if (!str_contains($segment, $keyword)) {
                continue;
            }

            $decision = $this->parseBooleanValue($segment);

            if ($decision !== null) {
                return $decision;
            }
        }

        return null;
    }

    private function extractPhoneFromMessage(string $messageBody): string
    {
        if (preg_match('/(\+?\d[\d\-\s\(\)]{9,})/', $messageBody, $matches) !== 1) {
            return '';
        }

        return $this->normalizePhoneDigits($matches[1]);
    }

    private function normalizePhoneDigits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    /**
     * @param list<string> $lines
     */
    private function extractPossibleName(array $lines): ?string
    {
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_contains($line, ':') || str_contains($line, '-')) {
                continue;
            }

            if (preg_match('/^[A-Za-zÀ-ÿ ]{5,}$/u', $line) === 1 && str_word_count($line) >= 2) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     */
    private function extractPossibleCity(array $lines): ?string
    {
        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || !str_contains($line, '/')) {
                continue;
            }

            [$city] = $this->splitCityAndState($line);

            if ($city !== null) {
                return $city;
            }
        }

        return null;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function splitCityAndState(string $value): array
    {
        $value = trim($value);

        if (preg_match('/^(.+?)\s*\/\s*([A-Za-z]{2})$/u', $value, $matches) === 1) {
            return [trim($matches[1]), mb_strtoupper($matches[2])];
        }

        return [$value !== '' ? $value : null, null];
    }

    private function extractState(string $value): ?string
    {
        if (preg_match('/\b([A-Za-z]{2})\b/u', $value, $matches) !== 1) {
            return null;
        }

        return mb_strtoupper($matches[1]);
    }

    private function requireSession(int $campaignRecipientId): array
    {
        $session = $this->triageModel->findSessionByCampaignRecipientId($campaignRecipientId);

        return $session ?? [];
    }

    private function buildQualificationPromptMessage(): string
    {
        return "Perfeito. Para seguirmos com sua priorizacao na W13, me envie por favor:\n\n- Nome completo\n- Cidade atual\n- UF\n- Telefone\n- Possui notebook?\n- Possui cabo console?\n- Tem disponibilidade imediata?\n- Pode retirar equipamento em base, se necessario?\n\nAssim validamos seu perfil para a atividade.";
    }

    private function buildNotInterestedMessage(): string
    {
        return "Sem problemas. Obrigado pelo retorno.\n\nVamos manter seu contato em nossa base para futuras oportunidades na sua regiao.\n\nEquipe W13 Tecnologia";
    }

    private function buildDetailsMessage(): string
    {
        return "Claro. Seguem os detalhes:\n\n- Empresa: W13 Tecnologia\n- Tipo de atividade: atendimento tecnico de campo\n- Valor: R\$200 por atividade concluida\n- Tempo medio por site: ate 2h\n- Equipamentos necessarios: notebook e cabo console\n- Atendimento com retirada de equipamento em base, conforme localidade\n\nSe tiver interesse, responda com:\nSIM\n\nEquipe W13 Tecnologia";
    }

    private function buildQualificationAckMessage(): string
    {
        return "Recebido.\n\nSeu perfil sera validado pela equipe W13 para essa oportunidade.\nSe aprovado, entraremos em contato com a programacao do atendimento.\n\nObrigado pelo retorno.\n\nEquipe W13 Tecnologia";
    }

    private function buildInitialOfferRetryMessage(): string
    {
        return "Nao consegui identificar sua opcao.\n\nResponda com:\n1 - SIM, tenho interesse\n2 - NAO tenho interesse\n3 - Talvez, preciso de mais detalhes";
    }

    private function buildDetailsRetryMessage(): string
    {
        return "Se quiser seguir, responda somente com:\nSIM\n\nSe nao tiver interesse, responda com:\n2";
    }

    private function buildQualificationRetryMessage(): string
    {
        return "Para validar seu perfil, preciso receber estes dados na mesma resposta:\n\n- Nome completo\n- Cidade atual\n- UF\n- Telefone\n- Possui notebook?\n- Possui cabo console?\n- Tem disponibilidade imediata?\n- Pode retirar equipamento em base, se necessario?";
    }

    private function buildOperatorFallbackMessage(): string
    {
        return "Recebi sua mensagem e vou encaminhar o atendimento para um operador da W13 continuar por aqui.";
    }

    private function buildApprovalAcceptedMessage(): string
    {
        return "Perfeito.\n\nVoce sera incluido na programacao da W13.\nEm breve nossa equipe enviara os dados da atividade, orientacoes e procedimento de campo.\n\nEquipe W13 Tecnologia";
    }

    private function buildApprovalDeclinedMessage(): string
    {
        return "Tudo certo. Obrigado pelo retorno.\n\nVamos manter seu contato na base da W13 para proximas oportunidades.\n\nEquipe W13 Tecnologia";
    }
}
