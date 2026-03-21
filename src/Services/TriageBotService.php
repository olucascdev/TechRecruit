<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use PDO;
use TechRecruit\Database;
use TechRecruit\Models\TriageModel;

final class TriageBotService
{
    public const AUTOMATION_TYPE = 'triage_w13';
    public const FLOW_VERSION = '0.4.0';

    /**
     * @var array<string, array{label:string, level:string, keywords:list<string>, options:list<string>}>
     */
    private const SERVICE_OPTIONS = [
        'vsat' => [
            'label' => 'VSAT',
            'level' => 'N3',
            'keywords' => ['vsat', 'satelital', 'satelite'],
            'options' => ['1'],
        ],
        'redes_firewall' => [
            'label' => 'Redes / Firewall',
            'level' => 'N2',
            'keywords' => ['redes', 'firewall', 'switch', 'roteador', 'router'],
            'options' => ['2'],
        ],
        'microinformatica' => [
            'label' => 'Microinformatica',
            'level' => 'N1',
            'keywords' => ['microinformatica', 'microinformatica', 'desktop', 'notebook', 'micro'],
            'options' => ['3'],
        ],
        'impressoras' => [
            'label' => 'Impressoras',
            'level' => 'N1',
            'keywords' => ['impressora', 'impressoras'],
            'options' => ['4'],
        ],
        'cabeamento' => [
            'label' => 'Cabeamento',
            'level' => 'N1',
            'keywords' => ['cabeamento', 'cabo', 'cabling', 'patch panel'],
            'options' => ['5'],
        ],
        'servidores' => [
            'label' => 'Servidores',
            'level' => 'N3',
            'keywords' => ['servidor', 'servidores', 'vmware', 'virtualizacao'],
            'options' => ['6'],
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const TOOL_KEYWORDS = [
        'multimetro' => ['multimetro'],
        'kit_ferramentas' => ['kit de ferramentas', 'ferramentas', 'toolkit'],
        'alicate_crimpagem' => ['alicate de crimpagem', 'crimpagem', 'crimpador'],
        'testador_rede' => ['testador de rede', 'testador', 'certificador'],
        'escada' => ['escada'],
    ];

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
        $currentStep = $this->normalizeWorkflowStep((string) ($session['current_step'] ?? 'initial_offer'));

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
            'prefilter' => $this->handlePreFilterStep($session, $messageBody),
            'field_readiness' => $this->handleFieldReadinessStep($session, $messageBody),
            'approval_confirmation' => $this->handleApprovalConfirmationStep($session, $messageBody, $parsedIntent),
            'waiting_validation' => $this->handoffToOperator(
                $session,
                $messageBody,
                'Candidato respondeu novamente enquanto aguardava validacao manual.'
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
            "W13 Tecnologia - Cadastro de Tecnicos de Campo\n\nOla, tudo bem?\nEstamos expandindo nossa operacao nacional e buscamos tecnicos parceiros na sua regiao para atendimentos em campo.\n\nRegiao alvo: %s\n\nVoce tem interesse em prestar servicos para a W13?\n\nResponda:\n1 - SIM\n2 - NAO\n3 - MAIS INFORMACOES",
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
            $reply = $this->buildPreFilterPromptMessage();
            $collectedData = $this->mergeCollectedData($session, [
                'interest_reply' => $messageBody,
                'last_prompt' => 'prefilter',
            ]);

            return $this->advanceSession(
                $session,
                [
                    'triage_status' => 'interested',
                    'current_step' => 'prefilter',
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
                    'metadata' => ['transition' => 'prefilter'],
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
            $reply = $this->buildPreFilterPromptMessage();
            $collectedData = $this->mergeCollectedData($session, [
                'details_confirmation_reply' => $messageBody,
                'last_prompt' => 'prefilter',
            ]);

            return $this->advanceSession(
                $session,
                [
                    'triage_status' => 'interested',
                    'current_step' => 'prefilter',
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
                    'metadata' => ['transition' => 'prefilter'],
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
            'Resposta invalida apos envio de mais informacoes.'
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handlePreFilterStep(array $session, string $messageBody): array
    {
        $preFilterData = $this->extractPreFilterData($messageBody);

        if ($this->isPreFilterReplyValid($preFilterData, $messageBody)) {
            $reply = $this->buildFieldReadinessPromptMessage();
            $collectedData = $this->mergeCollectedData($session, [
                'prefilter' => $preFilterData,
                'prefilter_raw_reply' => $messageBody,
                'last_prompt' => 'field_readiness',
            ]);

            return $this->advanceSession(
                $session,
                [
                    'triage_status' => 'interested',
                    'current_step' => 'field_readiness',
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
                        'transition' => 'field_readiness',
                        'captured_fields' => array_keys(array_filter([
                            'city' => $preFilterData['city'] ?? null,
                            'state' => $preFilterData['state'] ?? null,
                            'mei_active' => $preFilterData['mei_active'] ?? null,
                            'has_notebook' => $preFilterData['has_notebook'] ?? null,
                            'has_console_cable' => $preFilterData['has_console_cable'] ?? null,
                            'services' => ($preFilterData['service_keys'] ?? []) !== [] ? 'ok' : null,
                            'immediate_availability' => $preFilterData['immediate_availability'] ?? null,
                        ], static fn (mixed $value): bool => $value !== null && $value !== [])),
                    ],
                ]
            );
        }

        return $this->handleInvalidStepReply(
            $session,
            $messageBody,
            'prefilter',
            $this->buildPreFilterRetryMessage(),
            'Pre-filtro enviado em formato insuficiente.'
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handleFieldReadinessStep(array $session, string $messageBody): array
    {
        $collectedData = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
        $preFilterData = is_array($collectedData['prefilter'] ?? null) ? $collectedData['prefilter'] : [];
        $fieldReadinessData = $this->extractFieldReadinessData($messageBody);

        if ($this->isFieldReadinessReplyValid($fieldReadinessData, $messageBody)) {
            $classification = $this->buildW13Classification($preFilterData, $fieldReadinessData);
            $reply = $this->buildFieldReadinessAckMessage($classification);
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'field_readiness' => $fieldReadinessData,
                'field_readiness_raw_reply' => $messageBody,
                'classification' => $classification,
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
                    'collected_data' => $updatedCollectedData,
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
                        'classification_status' => $classification['status'] ?? null,
                        'technical_level' => $classification['technical_level'] ?? null,
                        'field_level' => $classification['field_level'] ?? null,
                        'premium_candidate' => $classification['premium_candidate'] ?? false,
                    ],
                ]
            );
        }

        return $this->handleInvalidStepReply(
            $session,
            $messageBody,
            'field_readiness',
            $this->buildFieldReadinessRetryMessage(),
            'Qualificacao tecnica e de seguranca enviada em formato insuficiente.'
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
            "Confirma disponibilidade para a programacao?\n\nResponda:\n1 - CONFIRMO\n2 - NAO POSSO",
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

    private function normalizeWorkflowStep(string $step): string
    {
        return match ($step) {
            'qualification' => 'prefilter',
            default => $step,
        };
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
            $normalized === '3'
            || str_contains($normalized, 'mais informacoes')
            || str_contains($normalized, 'mais detalhes')
            || str_contains($normalized, 'preciso de informacoes')
        ) {
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
    private function extractPreFilterData(string $messageBody): array
    {
        $data = [
            'city' => null,
            'state' => null,
            'mei_active' => null,
            'has_notebook' => null,
            'has_console_cable' => null,
            'service_keys' => [],
            'service_labels' => [],
            'immediate_availability' => null,
        ];

        $lines = preg_split('/\r\n|\r|\n/', trim($messageBody)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (($data['service_keys'] === []) && preg_match('/^\s*(servicos?|especialidades?)\s*[:\-]/iu', $line) === 1) {
                $services = $this->parseServiceCategories($line);
                $data['service_keys'] = $services;
                $data['service_labels'] = $this->serviceLabels($services);
                continue;
            }

            if (preg_match('/^\s*[-*]?\s*([^:]+?)\s*[:\-]\s*(.+)\s*$/u', $line, $matches) === 1) {
                $label = $this->normalizeFreeText($matches[1]);
                $value = trim($matches[2]);

                if ($this->containsAny($label, ['cidade', 'cidade atual', 'cidade/uf', 'regiao'])) {
                    [$city, $state] = $this->splitCityAndState($value);
                    $data['city'] = $data['city'] ?? $city;
                    $data['state'] = $data['state'] ?? $state;
                    continue;
                }

                if ($this->containsAny($label, ['uf', 'estado'])) {
                    [, $state] = $this->splitCityAndState($value);
                    $data['state'] = $data['state'] ?? $state ?? (mb_strtoupper(trim($value)) ?: null);
                    continue;
                }

                if ($this->containsAny($label, ['mei', 'mei ativo', 'cnpj'])) {
                    $decision = $this->parseBooleanValue($value);
                    $data['mei_active'] = $decision ?? $data['mei_active'];
                    continue;
                }

                if ($this->containsAny($label, ['notebook'])) {
                    $decision = $this->parseBooleanValue($value);
                    $data['has_notebook'] = $decision ?? $data['has_notebook'];
                    continue;
                }

                if ($this->containsAny($label, ['cabo console', 'console'])) {
                    $decision = $this->parseBooleanValue($value);
                    $data['has_console_cable'] = $decision ?? $data['has_console_cable'];
                    continue;
                }

                if ($this->containsAny($label, ['servicos', 'especialidades', 'servico'])) {
                    $services = $this->parseServiceCategories($value);
                    $data['service_keys'] = $services;
                    $data['service_labels'] = $this->serviceLabels($services);
                    continue;
                }

                if ($this->containsAny($label, ['disponibilidade', 'disponibilidade imediata'])) {
                    $decision = $this->parseBooleanValue($value);
                    $data['immediate_availability'] = $decision ?? $data['immediate_availability'];
                }

                continue;
            }

            if ($data['city'] === null && str_contains($line, '/')) {
                [$city, $state] = $this->splitCityAndState($line);
                $data['city'] = $data['city'] ?? $city;
                $data['state'] = $data['state'] ?? $state;
            }

            if ($data['service_keys'] === []) {
                $services = $this->parseServiceCategories($line);

                if ($services !== []) {
                    $data['service_keys'] = $services;
                    $data['service_labels'] = $this->serviceLabels($services);
                }
            }
        }

        if ($data['city'] === null || $data['state'] === null) {
            [$city, $state] = $this->splitCityAndState($messageBody);
            $data['city'] = $data['city'] ?? $city;
            $data['state'] = $data['state'] ?? $state;
        }

        if ($data['mei_active'] === null) {
            $data['mei_active'] = $this->parseBooleanFromWholeMessage($messageBody, ['mei', 'cnpj']);
        }

        if ($data['has_notebook'] === null) {
            $data['has_notebook'] = $this->parseBooleanFromWholeMessage($messageBody, ['notebook']);
        }

        if ($data['has_console_cable'] === null) {
            $data['has_console_cable'] = $this->parseBooleanFromWholeMessage($messageBody, ['cabo console', 'console']);
        }

        if ($data['service_keys'] === []) {
            $data['service_keys'] = $this->parseServiceCategories($messageBody);
            $data['service_labels'] = $this->serviceLabels($data['service_keys']);
        }

        if ($data['immediate_availability'] === null) {
            $data['immediate_availability'] = $this->parseBooleanFromWholeMessage($messageBody, ['disponibilidade', 'imediata']);
        }

        return $data;
    }

    private function isPreFilterReplyValid(array $preFilterData, string $messageBody): bool
    {
        $filledFields = 0;

        if (($preFilterData['city'] ?? null) !== null) {
            $filledFields++;
        }

        if (($preFilterData['state'] ?? null) !== null) {
            $filledFields++;
        }

        if (($preFilterData['mei_active'] ?? null) !== null) {
            $filledFields++;
        }

        if (($preFilterData['has_notebook'] ?? null) !== null) {
            $filledFields++;
        }

        if (($preFilterData['has_console_cable'] ?? null) !== null) {
            $filledFields++;
        }

        if (($preFilterData['service_keys'] ?? []) !== []) {
            $filledFields++;
        }

        if (($preFilterData['immediate_availability'] ?? null) !== null) {
            $filledFields++;
        }

        if (
            ($preFilterData['city'] ?? null) !== null
            && ($preFilterData['state'] ?? null) !== null
            && ($preFilterData['mei_active'] ?? null) !== null
            && ($preFilterData['service_keys'] ?? []) !== []
            && ($preFilterData['immediate_availability'] ?? null) !== null
        ) {
            return true;
        }

        return $filledFields >= 5 && mb_strlen(trim($messageBody)) >= 45;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFieldReadinessData(string $messageBody): array
    {
        $data = [
            'has_aso' => null,
            'has_nr10' => null,
            'has_nr35' => null,
            'has_complete_toolkit' => null,
            'tool_items' => [],
        ];

        $lines = preg_split('/\r\n|\r|\n/', trim($messageBody)) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\s*[-*]?\s*([^:]+?)\s*[:\-]\s*(.+)\s*$/u', $line, $matches) === 1) {
                $label = $this->normalizeFreeText($matches[1]);
                $value = trim($matches[2]);

                if ($this->containsAny($label, ['aso'])) {
                    $decision = $this->parseBooleanValue($value);
                    $data['has_aso'] = $decision ?? $data['has_aso'];
                    continue;
                }

                if ($this->containsAny($label, ['nr10'])) {
                    $decision = $this->parseBooleanValue($value);
                    $data['has_nr10'] = $decision ?? $data['has_nr10'];
                    continue;
                }

                if ($this->containsAny($label, ['nr35'])) {
                    $decision = $this->parseBooleanValue($value);
                    $data['has_nr35'] = $decision ?? $data['has_nr35'];
                    continue;
                }

                if ($this->containsAny($label, ['ferramental', 'ferramentas', 'kit'])) {
                    $decision = $this->parseBooleanValue($value);
                    $data['has_complete_toolkit'] = $decision ?? $data['has_complete_toolkit'];
                    $data['tool_items'] = array_values(array_unique(array_merge(
                        $data['tool_items'],
                        $this->extractToolItems($value)
                    )));
                }

                continue;
            }

            $data['tool_items'] = array_values(array_unique(array_merge(
                $data['tool_items'],
                $this->extractToolItems($line)
            )));
        }

        if ($data['has_aso'] === null) {
            $data['has_aso'] = $this->parseBooleanFromWholeMessage($messageBody, ['aso']);
        }

        if ($data['has_nr10'] === null) {
            $data['has_nr10'] = $this->parseBooleanFromWholeMessage($messageBody, ['nr10']);
        }

        if ($data['has_nr35'] === null) {
            $data['has_nr35'] = $this->parseBooleanFromWholeMessage($messageBody, ['nr35']);
        }

        if ($data['has_complete_toolkit'] === null) {
            $data['has_complete_toolkit'] = $this->parseBooleanFromWholeMessage($messageBody, ['ferramental', 'ferramentas', 'kit']);
        }

        return $data;
    }

    private function isFieldReadinessReplyValid(array $fieldReadinessData, string $messageBody): bool
    {
        $filledFields = 0;

        foreach (['has_aso', 'has_nr10', 'has_nr35', 'has_complete_toolkit'] as $key) {
            if (($fieldReadinessData[$key] ?? null) !== null) {
                $filledFields++;
            }
        }

        if ($filledFields === 4) {
            return true;
        }

        return $filledFields >= 3 && mb_strlen(trim($messageBody)) >= 40;
    }

    /**
     * @param array<string, mixed> $preFilterData
     * @param array<string, mixed> $fieldReadinessData
     * @return array<string, mixed>
     */
    private function buildW13Classification(array $preFilterData, array $fieldReadinessData): array
    {
        $serviceKeys = array_values(array_filter(
            array_map(static fn (mixed $value): string => (string) $value, $preFilterData['service_keys'] ?? [])
        ));
        $serviceLabels = $this->serviceLabels($serviceKeys);
        $technicalLevel = $this->resolveTechnicalLevel($serviceKeys);
        $fieldLevel = $this->resolveFieldLevel($fieldReadinessData);
        $fieldLevelLabel = $this->fieldLevelLabel($fieldLevel);
        $status = $this->resolveClassificationStatus($preFilterData, $fieldReadinessData, $technicalLevel);
        $statusLabel = $this->classificationStatusLabel($status);
        $premiumCandidate = $this->isPremiumCandidate($preFilterData, $fieldReadinessData, $technicalLevel, $fieldLevel);

        $missingRequirements = [];

        if (($preFilterData['mei_active'] ?? null) !== true) {
            $missingRequirements[] = 'MEI ativo';
        }

        if (($preFilterData['immediate_availability'] ?? null) !== true) {
            $missingRequirements[] = 'disponibilidade imediata';
        }

        if (($preFilterData['has_notebook'] ?? null) !== true) {
            $missingRequirements[] = 'notebook';
        }

        if (($preFilterData['has_console_cable'] ?? null) !== true) {
            $missingRequirements[] = 'cabo console';
        }

        if (($fieldReadinessData['has_aso'] ?? null) !== true) {
            $missingRequirements[] = 'ASO';
        }

        if (($fieldReadinessData['has_nr10'] ?? null) !== true) {
            $missingRequirements[] = 'NR10';
        }

        if (($fieldReadinessData['has_nr35'] ?? null) !== true) {
            $missingRequirements[] = 'NR35';
        }

        if (($fieldReadinessData['has_complete_toolkit'] ?? null) !== true) {
            $missingRequirements[] = 'ferramental completo';
        }

        return [
            'status' => $status,
            'status_label' => $statusLabel,
            'technical_level' => $technicalLevel,
            'technical_level_label' => $technicalLevel ?? 'Nao classificado',
            'field_level' => $fieldLevel,
            'field_level_label' => $fieldLevelLabel,
            'service_keys' => $serviceKeys,
            'service_labels' => $serviceLabels,
            'premium_candidate' => $premiumCandidate,
            'ready_for_field' => $status === 'approved',
            'missing_requirements' => $missingRequirements,
        ];
    }

    private function resolveClassificationStatus(
        array $preFilterData,
        array $fieldReadinessData,
        ?string $technicalLevel
    ): string {
        if (($preFilterData['mei_active'] ?? null) !== true) {
            return 'rejected';
        }

        if ($technicalLevel === null || ($preFilterData['service_keys'] ?? []) === []) {
            return 'bank';
        }

        if (($preFilterData['immediate_availability'] ?? null) !== true) {
            return 'bank';
        }

        if (
            ($fieldReadinessData['has_aso'] ?? null) === true
            && ($fieldReadinessData['has_nr10'] ?? null) === true
            && ($fieldReadinessData['has_nr35'] ?? null) === true
            && ($fieldReadinessData['has_complete_toolkit'] ?? null) === true
        ) {
            return 'approved';
        }

        return 'pending';
    }

    private function isPremiumCandidate(
        array $preFilterData,
        array $fieldReadinessData,
        ?string $technicalLevel,
        string $fieldLevel
    ): bool {
        return ($preFilterData['mei_active'] ?? null) === true
            && ($preFilterData['immediate_availability'] ?? null) === true
            && $technicalLevel !== null
            && $fieldLevel === 'complete'
            && ($fieldReadinessData['has_complete_toolkit'] ?? null) === true;
    }

    private function resolveTechnicalLevel(array $serviceKeys): ?string
    {
        $rank = [
            'N1' => 1,
            'N2' => 2,
            'N3' => 3,
        ];
        $resolved = null;
        $resolvedRank = 0;

        foreach ($serviceKeys as $serviceKey) {
            $service = self::SERVICE_OPTIONS[$serviceKey] ?? null;

            if ($service === null) {
                continue;
            }

            $serviceLevel = $service['level'];
            $serviceRank = $rank[$serviceLevel] ?? 0;

            if ($serviceRank > $resolvedRank) {
                $resolved = $serviceLevel;
                $resolvedRank = $serviceRank;
            }
        }

        return $resolved;
    }

    private function resolveFieldLevel(array $fieldReadinessData): string
    {
        $score = 0;

        foreach (['has_aso', 'has_nr10', 'has_nr35'] as $key) {
            if (($fieldReadinessData[$key] ?? null) === true) {
                $score++;
            }
        }

        return match (true) {
            $score === 3 => 'complete',
            $score >= 1 => 'partial',
            default => 'restricted',
        };
    }

    private function classificationStatusLabel(string $status): string
    {
        return match ($status) {
            'approved' => 'Aprovado',
            'pending' => 'Pendente',
            'rejected' => 'Reprovado',
            default => 'Banco',
        };
    }

    private function fieldLevelLabel(string $fieldLevel): string
    {
        return match ($fieldLevel) {
            'complete' => 'Completo',
            'partial' => 'Parcial',
            default => 'Restrito',
        };
    }

    /**
     * @return list<string>
     */
    private function parseServiceCategories(string $value): array
    {
        $normalized = $this->normalizeFreeText($value);
        $resolved = [];

        foreach (self::SERVICE_OPTIONS as $key => $service) {
            foreach ($service['options'] as $option) {
                if (preg_match('/(^|[^0-9])' . preg_quote($option, '/') . '([^0-9]|$)/', $normalized) === 1) {
                    $resolved[] = $key;
                    continue 2;
                }
            }

            foreach ($service['keywords'] as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    $resolved[] = $key;
                    continue 2;
                }
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param list<string> $serviceKeys
     * @return list<string>
     */
    private function serviceLabels(array $serviceKeys): array
    {
        $labels = [];

        foreach ($serviceKeys as $serviceKey) {
            $service = self::SERVICE_OPTIONS[$serviceKey] ?? null;

            if ($service !== null) {
                $labels[] = $service['label'];
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @return list<string>
     */
    private function extractToolItems(string $messageBody): array
    {
        $normalized = $this->normalizeFreeText($messageBody);
        $items = [];

        foreach (self::TOOL_KEYWORDS as $toolKey => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    $items[] = $toolKey;
                    continue 2;
                }
            }
        }

        return array_values(array_unique($items));
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
            || str_contains($normalized, 'nao possui')
            || str_contains($normalized, 'nao')
            || str_contains($normalized, 'sem')
            || str_contains($normalized, 'inativo')
            || str_contains($normalized, 'vencido')
            || str_contains($normalized, 'invalido')
        ) {
            return false;
        }

        if (
            str_contains($normalized, 'sim')
            || str_contains($normalized, 'tenho')
            || str_contains($normalized, 'possuo')
            || str_contains($normalized, 'ativo')
            || str_contains($normalized, 'regular')
            || str_contains($normalized, 'disponivel')
            || str_contains($normalized, 'completo')
            || str_contains($normalized, 'ok')
        ) {
            return true;
        }

        return null;
    }

    /**
     * @param list<string> $keywords
     */
    private function parseBooleanFromWholeMessage(string $messageBody, array $keywords): ?bool
    {
        $normalized = $this->normalizeFreeText($messageBody);

        foreach ($keywords as $keyword) {
            if (!str_contains($normalized, $keyword)) {
                continue;
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
        }

        return null;
    }

    private function normalizePhoneDigits(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
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

        if (preg_match('/\b([A-Za-z]{2})\b/u', $value, $matches) === 1) {
            $state = mb_strtoupper($matches[1]);
            $city = trim(preg_replace('/\b' . preg_quote($matches[1], '/') . '\b/u', '', $value) ?? '');

            return [$city !== '' ? $city : null, $state];
        }

        return [$value !== '' ? $value : null, null];
    }

    private function requireSession(int $campaignRecipientId): array
    {
        $session = $this->triageModel->findSessionByCampaignRecipientId($campaignRecipientId);

        return $session ?? [];
    }

    private function buildPreFilterPromptMessage(): string
    {
        return "Perfeito! Vamos fazer seu pre-cadastro na W13.\n\nPor favor, envie:\n- Cidade / UF\n- Possui MEI ativo? (obrigatorio)\n- Possui notebook?\n- Possui cabo console?\n- Quais servicos voce atende?\n1 - VSAT\n2 - Redes / Firewall\n3 - Microinformatica\n4 - Impressoras\n5 - Cabeamento\n6 - Servidores\n- Tem disponibilidade imediata?\n\nResponda idealmente assim:\nCidade/UF: Sao Mateus/ES\nMEI ativo: sim\nNotebook: sim\nCabo console: nao\nServicos: 2, 3, 5\nDisponibilidade imediata: sim";
    }

    private function buildFieldReadinessPromptMessage(): string
    {
        return "Otimo. Agora preciso validar sua aptidao para atividades em campo.\n\nVoce possui:\n- ASO valido?\n- NR10?\n- NR35?\n\nPossui ferramental completo para atendimento?\nExemplos: multimetro, kit de ferramentas, alicate de crimpagem, testador de rede, escada.\n\nEnvie idealmente assim:\nASO: sim\nNR10: sim\nNR35: nao\nFerramental completo: sim\nFerramentas: multimetro, kit de ferramentas, alicate de crimpagem";
    }

    private function buildNotInterestedMessage(): string
    {
        return "Sem problemas. Obrigado pelo retorno.\n\nVamos manter seu contato em nossa base para futuras oportunidades na sua regiao.\n\nEquipe W13 Tecnologia";
    }

    private function buildDetailsMessage(): string
    {
        return "Claro. Seguem mais informacoes sobre a base tecnica W13:\n\n- Atendimentos em campo em operacao nacional\n- Acionamentos por WhatsApp\n- Necessidade de padrao tecnico e documental\n- Prioridade para tecnicos com MEI, disponibilidade e seguranca regularizada\n\nSe quiser seguir com o pre-cadastro, responda:\nSIM";
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function buildFieldReadinessAckMessage(array $classification): string
    {
        $statusLabel = (string) ($classification['status_label'] ?? 'Pendente');
        $technicalLevel = (string) ($classification['technical_level_label'] ?? 'Nao classificado');
        $fieldLevel = (string) ($classification['field_level_label'] ?? 'Restrito');
        $serviceLabels = $classification['service_labels'] ?? [];
        $servicesLine = is_array($serviceLabels) && $serviceLabels !== []
            ? implode(', ', array_map(static fn (mixed $value): string => (string) $value, $serviceLabels))
            : 'Nao informado';

        return "Recebido.\n\nSua classificacao preliminar na W13 ficou assim:\n- Status: {$statusLabel}\n- Nivel tecnico: {$technicalLevel}\n- Nivel de campo: {$fieldLevel}\n- Especialidades: {$servicesLine}\n\nNossa equipe vai validar seu perfil e seguir com a etapa documental para liberar operacao.\n\nEquipe W13 Tecnologia";
    }

    private function buildInitialOfferRetryMessage(): string
    {
        return "Nao consegui identificar sua opcao.\n\nResponda com:\n1 - SIM\n2 - NAO\n3 - MAIS INFORMACOES";
    }

    private function buildDetailsRetryMessage(): string
    {
        return "Se quiser seguir para o pre-cadastro, responda somente com:\nSIM\n\nSe nao tiver interesse, responda com:\n2";
    }

    private function buildPreFilterRetryMessage(): string
    {
        return "Para seguir com o pre-cadastro W13, preciso receber estes dados na mesma resposta:\n\n- Cidade / UF\n- MEI ativo\n- Possui notebook\n- Possui cabo console\n- Quais servicos atende\n- Disponibilidade imediata";
    }

    private function buildFieldReadinessRetryMessage(): string
    {
        return "Para validar sua aptidao de campo, preciso destes dados na mesma resposta:\n\n- ASO valido\n- NR10\n- NR35\n- Ferramental completo\n- Lista resumida das ferramentas";
    }

    private function buildOperatorFallbackMessage(): string
    {
        return "Recebi sua mensagem e vou encaminhar o atendimento para um operador da W13 continuar por aqui.";
    }

    private function buildApprovalAcceptedMessage(): string
    {
        return "Perfeito.\n\nVoce foi confirmado na programacao da W13.\nEm breve nossa equipe enviara os dados da atividade, orientacoes e procedimento de campo.\n\nEquipe W13 Tecnologia";
    }

    private function buildApprovalDeclinedMessage(): string
    {
        return "Tudo certo. Obrigado pelo retorno.\n\nVamos manter seu contato na base da W13 para proximas oportunidades.\n\nEquipe W13 Tecnologia";
    }
}
