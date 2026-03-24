<?php

declare(strict_types=1);

namespace TechRecruit\Services;

use PDO;
use TechRecruit\Database;
use TechRecruit\Models\CandidateModel;
use TechRecruit\Models\TriageModel;
use Throwable;

final class TriageBotService
{
    public const AUTOMATION_TYPE = 'triage_w13';
    public const FLOW_VERSION = '1.0.0';

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
            'keywords' => ['microinformatica', 'micro', 'desktop', 'notebook'],
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
            'level' => 'N2',
            'keywords' => ['cabeamento', 'cabos', 'cabo'],
            'options' => ['5'],
        ],
        'servidores' => [
            'label' => 'Servidores',
            'level' => 'N3',
            'keywords' => ['servidor', 'servidores', 'virtualizacao', 'vmware'],
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

    private CandidateModel $candidateModel;

    private PortalService $portalService;

    public function __construct(
        ?TriageModel $triageModel = null,
        ?PDO $pdo = null,
        ?CandidateModel $candidateModel = null,
        ?PortalService $portalService = null
    )
    {
        $this->pdo = $pdo ?? Database::connect();
        $this->triageModel = $triageModel ?? new TriageModel($this->pdo);
        $this->candidateModel = $candidateModel ?? new CandidateModel($this->pdo);
        $this->portalService = $portalService ?? new PortalService($this->candidateModel, null, null, $this->pdo);
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

        $currentStep = $this->normalizeWorkflowStep((string) ($session['current_step'] ?? 'initial_offer'));
        $collectedData = is_array($session['collected_data'] ?? null)
            ? $session['collected_data']
            : $this->defaultCollectedData();
        $collectedData['flow_status'] = $collectedData['flow_status'] ?? 'lead_novo';
        $collectedData['last_prompt'] = $collectedData['last_prompt'] ?? $currentStep;

        $this->triageModel->updateSession((int) $session['id'], [
            'flow_version' => self::FLOW_VERSION,
            'collected_data' => $collectedData,
            'last_outbound_message' => $initialMessage,
            'last_interaction_at' => $now,
        ]);

        $this->triageModel->logAnswer(
            (int) $session['id'],
            $currentStep,
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
            $this->resolveInboundLogStepKey($currentStep, $session),
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

        return match ($currentStep) {
            'initial_offer' => $this->handleInitialOfferStep($session, $messageBody, $parsedIntent),
            'details_followup' => $this->handleDetailsFollowupStep($session, $messageBody, $parsedIntent),
            'prefilter' => $this->handlePreFilterStep($session, $messageBody, $parsedIntent),
            'field_readiness' => $this->handleFieldReadinessStep($session, $messageBody, $parsedIntent),
            'approval_confirmation' => $this->handleDocumentCollectionStep($session, $messageBody, $parsedIntent),
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
        return trim(
            "W13 Tecnologia - Cadastro de Técnicos de Campo\n\n" .
            "Olá, tudo bem?\n\n" .
            "Estamos expandindo nossa operação nacional e buscamos técnicos parceiros para atendimentos em campo.\n" .
            "\nVocê tem interesse em prestar serviços para a W13?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO\n" .
            "3 - MAIS INFORMAÇÕES"
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handleInitialOfferStep(array $session, string $messageBody, string $parsedIntent): array
    {
        $option = $this->parseMenuOption($messageBody, ['1', '2', '3']);

        if ($option === null) {
            if ($parsedIntent === 'interested') {
                $option = '1';
            } elseif ($parsedIntent === 'not_interested') {
                $option = '2';
            } elseif ($parsedIntent === 'needs_details') {
                $option = '3';
            }
        }

        if ($option === '1') {
            $reply = $this->buildPreFilterCityPromptMessage();
            $collectedData = $this->mergeCollectedData($session, [
                'flow_status' => 'interessado',
                'prefilter_progress' => 'city_state',
                'last_prompt' => 'prefilter_city',
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

        if ($option === '3') {
            $reply = $this->buildInfoCompanyMessage();
            $collectedData = $this->mergeCollectedData($session, [
                'flow_status' => 'mais_informacoes',
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
        $option = $this->parseMenuOption($messageBody, ['1', '2']);

        if ($option === null) {
            if ($parsedIntent === 'interested') {
                $option = '1';
            } elseif ($parsedIntent === 'not_interested') {
                $option = '2';
            }
        }

        if ($option === '1') {
            $reply = $this->buildPreFilterCityPromptMessage();
            $collectedData = $this->mergeCollectedData($session, [
                'flow_status' => 'interessado',
                'prefilter_progress' => 'city_state',
                'last_prompt' => 'prefilter_city',
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
            'details_followup',
            $this->buildDetailsRetryMessage(),
            'Resposta invalida apos envio de mais informacoes.'
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handlePreFilterStep(array $session, string $messageBody, string $parsedIntent): array
    {
        $collectedData = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : $this->defaultCollectedData();
        $preFilterData = is_array($collectedData['prefilter'] ?? null) ? $collectedData['prefilter'] : [];
        $progress = (string) ($collectedData['prefilter_progress'] ?? 'city_state');

        if ($progress === 'city_state') {
            [$city, $state] = $this->splitCityAndState($messageBody);

            if ($city === null || $state === null) {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'prefilter',
                    $this->buildPreFilterCityRetryMessage(),
                    'Cidade/UF invalida no pre-filtro.'
                );
            }

            $preFilterData['city'] = $city;
            $preFilterData['state'] = $state;
            $reply = $this->buildPreFilterMeiPromptMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'prefilter' => $preFilterData,
                'prefilter_progress' => 'mei',
                'last_prompt' => 'prefilter_mei',
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
                        'transition' => 'prefilter_mei',
                        'city' => $city,
                        'state' => $state,
                    ],
                ]
            );
        }

        if ($progress === 'mei') {
            $meiActive = $this->parseYesNoOption($messageBody, $parsedIntent);

            if ($meiActive === null) {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'prefilter',
                    $this->buildYesNoRetryMessage('MEI ativo'),
                    'Resposta invalida para MEI ativo.'
                );
            }

            $preFilterData['mei_active'] = $meiActive;
            $reply = $this->buildPreFilterNotebookPromptMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'prefilter' => $preFilterData,
                'prefilter_progress' => 'notebook',
                'last_prompt' => 'prefilter_notebook',
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
                        'transition' => 'prefilter_notebook',
                        'mei_active' => $meiActive,
                    ],
                ]
            );
        }

        if ($progress === 'notebook') {
            $hasNotebook = $this->parseYesNoOption($messageBody, $parsedIntent);

            if ($hasNotebook === null) {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'prefilter',
                    $this->buildYesNoRetryMessage('notebook proprio'),
                    'Resposta invalida para notebook.'
                );
            }

            $preFilterData['has_notebook'] = $hasNotebook;
            $reply = $this->buildPreFilterConsolePromptMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'prefilter' => $preFilterData,
                'prefilter_progress' => 'console',
                'last_prompt' => 'prefilter_console',
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
                        'transition' => 'prefilter_console',
                        'has_notebook' => $hasNotebook,
                    ],
                ]
            );
        }

        if ($progress === 'console') {
            $hasConsoleCable = $this->parseYesNoOption($messageBody, $parsedIntent);

            if ($hasConsoleCable === null) {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'prefilter',
                    $this->buildYesNoRetryMessage('cabo console'),
                    'Resposta invalida para cabo console.'
                );
            }

            $preFilterData['has_console_cable'] = $hasConsoleCable;
            $reply = $this->buildPreFilterServicesPromptMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'prefilter' => $preFilterData,
                'prefilter_progress' => 'services',
                'last_prompt' => 'prefilter_services',
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
                        'transition' => 'prefilter_services',
                        'has_console_cable' => $hasConsoleCable,
                    ],
                ]
            );
        }

        if ($progress === 'services') {
            $selectedServiceKeys = is_array($preFilterData['service_keys'] ?? null)
                ? $preFilterData['service_keys']
                : [];

            $typedServices = $this->parseServiceCategories($messageBody);

            if (count($typedServices) > 1) {
                $merged = array_values(array_unique(array_merge($selectedServiceKeys, $typedServices)));

                return $this->advanceToPreFilterAvailability($session, $messageBody, $preFilterData, $merged);
            }

            $option = $this->parseMenuOption($messageBody, ['1', '2', '3', '4', '5', '6', '9']);

            if ($option === null) {
                $normalizedServiceReply = $this->normalizeFreeText($messageBody);

                if (str_contains($normalizedServiceReply, 'conclu') || $normalizedServiceReply === 'fim') {
                    $option = '9';
                }
            }

            if ($option === '9') {
                if ($selectedServiceKeys === []) {
                    return $this->handleInvalidStepReply(
                        $session,
                        $messageBody,
                        'prefilter',
                        $this->buildPreFilterServicesRetryMessage(),
                        'Tentativa de finalizar servicos sem selecao.'
                    );
                }

                return $this->advanceToPreFilterAvailability($session, $messageBody, $preFilterData, $selectedServiceKeys);
            }

            if ($option === null && count($typedServices) === 1) {
                $selectedServiceKeys = array_values(array_unique(array_merge($selectedServiceKeys, $typedServices)));
            } elseif ($option !== null && in_array($option, ['1', '2', '3', '4', '5', '6'], true)) {
                $serviceFromOption = $this->parseServiceCategories($option);

                if ($serviceFromOption !== []) {
                    $selectedServiceKeys = array_values(array_unique(array_merge($selectedServiceKeys, $serviceFromOption)));
                }
            } else {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'prefilter',
                    $this->buildPreFilterServicesRetryMessage(),
                    'Resposta invalida para servicos atendidos.'
                );
            }

            $preFilterData['service_keys'] = $selectedServiceKeys;
            $preFilterData['service_labels'] = $this->serviceLabels($selectedServiceKeys);
            $reply = $this->buildPreFilterServicesPromptMessage($preFilterData['service_labels']);
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'prefilter' => $preFilterData,
                'prefilter_progress' => 'services',
                'last_prompt' => 'prefilter_services',
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
                        'transition' => 'prefilter_services',
                        'service_keys' => $selectedServiceKeys,
                    ],
                ]
            );
        }

        if ($progress === 'availability') {
            $immediateAvailability = $this->parseYesNoOption($messageBody, $parsedIntent);

            if ($immediateAvailability === null) {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'prefilter',
                    $this->buildYesNoRetryMessage('disponibilidade imediata'),
                    'Resposta invalida para disponibilidade imediata.'
                );
            }

            $preFilterData['immediate_availability'] = $immediateAvailability;
            $preFilterData['limited_profile'] = (($preFilterData['has_notebook'] ?? true) !== true)
                || (($preFilterData['has_console_cable'] ?? true) !== true);

            if (($preFilterData['mei_active'] ?? null) !== true) {
                $classification = $this->buildW13Classification($preFilterData, []);
                $reply = $this->buildRejectedMeiMessage();
                $updatedCollectedData = $this->mergeCollectedData($session, [
                    'prefilter' => $preFilterData,
                    'classification' => $classification,
                    'flow_status' => 'reprovado_mei',
                    'last_prompt' => 'completed',
                ]);

                return $this->advanceSession(
                    $session,
                    [
                        'triage_status' => 'rejected_unavailable',
                        'current_step' => 'completed',
                        'automation_status' => 'completed',
                        'needs_operator' => false,
                        'invalid_reply_count' => 0,
                        'fallback_reason' => null,
                        'collected_data' => $updatedCollectedData,
                        'last_inbound_message' => $messageBody,
                        'last_outbound_message' => $reply,
                        'last_interaction_at' => date('Y-m-d H:i:s'),
                    ],
                    [
                        'parsed_intent' => 'not_interested',
                        'triage_status' => 'rejected_unavailable',
                        'current_step' => 'completed',
                        'automation_status' => 'completed',
                        'needs_operator' => false,
                        'candidate_status' => 'rejected',
                        'auto_reply' => $reply,
                        'metadata' => [
                            'flow_status' => 'reprovado_mei',
                            'classification_status' => $classification['status'] ?? 'rejected',
                            'limited_profile' => $preFilterData['limited_profile'],
                        ],
                    ]
                );
            }

            if (($preFilterData['immediate_availability'] ?? null) !== true) {
                $classification = $this->buildW13Classification($preFilterData, []);
                $reply = $this->buildBankMessage();
                $updatedCollectedData = $this->mergeCollectedData($session, [
                    'prefilter' => $preFilterData,
                    'classification' => $classification,
                    'flow_status' => 'banco',
                    'last_prompt' => 'completed',
                ]);

                return $this->advanceSession(
                    $session,
                    [
                        'triage_status' => 'awaiting_validation',
                        'current_step' => 'completed',
                        'automation_status' => 'completed',
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
                        'triage_status' => 'awaiting_validation',
                        'current_step' => 'completed',
                        'automation_status' => 'completed',
                        'needs_operator' => false,
                        'candidate_status' => 'responded',
                        'auto_reply' => $reply,
                        'metadata' => [
                            'flow_status' => 'banco',
                            'classification_status' => $classification['status'] ?? 'bank',
                            'limited_profile' => $preFilterData['limited_profile'],
                        ],
                    ]
                );
            }

            $reply = $this->buildFieldReadinessAsoPromptMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'prefilter' => $preFilterData,
                'field_readiness_progress' => 'aso',
                'flow_status' => 'qualificacao_tecnica',
                'last_prompt' => 'field_readiness_aso',
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
                        'transition' => 'field_readiness_aso',
                        'limited_profile' => $preFilterData['limited_profile'],
                    ],
                ]
            );
        }

        return $this->handleInvalidStepReply(
            $session,
            $messageBody,
            'prefilter',
            $this->buildPreFilterCityPromptMessage(),
            'Etapa de pre-filtro inconsistente.'
        );
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $preFilterData
     * @param list<string> $serviceKeys
     * @return array<string, mixed>
     */
    private function advanceToPreFilterAvailability(
        array $session,
        string $messageBody,
        array $preFilterData,
        array $serviceKeys
    ): array {
        $preFilterData['service_keys'] = $serviceKeys;
        $preFilterData['service_labels'] = $this->serviceLabels($serviceKeys);
        $reply = $this->buildPreFilterAvailabilityPromptMessage();
        $updatedCollectedData = $this->mergeCollectedData($session, [
            'prefilter' => $preFilterData,
            'prefilter_progress' => 'availability',
            'last_prompt' => 'prefilter_availability',
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
                    'transition' => 'prefilter_availability',
                    'service_keys' => $serviceKeys,
                ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handleFieldReadinessStep(array $session, string $messageBody, string $parsedIntent): array
    {
        $collectedData = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : $this->defaultCollectedData();
        $preFilterData = is_array($collectedData['prefilter'] ?? null) ? $collectedData['prefilter'] : [];
        $fieldReadinessData = is_array($collectedData['field_readiness'] ?? null) ? $collectedData['field_readiness'] : [];
        $progress = (string) ($collectedData['field_readiness_progress'] ?? 'aso');

        if ($progress === 'aso') {
            $hasAso = $this->parseYesNoOption($messageBody, $parsedIntent);

            if ($hasAso === null) {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'field_readiness',
                    $this->buildYesNoRetryMessage('ASO valido'),
                    'Resposta invalida para ASO.'
                );
            }

            $fieldReadinessData['has_aso'] = $hasAso;
            $reply = $this->buildFieldReadinessNr10PromptMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'field_readiness' => $fieldReadinessData,
                'field_readiness_progress' => 'nr10',
                'last_prompt' => 'field_readiness_nr10',
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
                        'transition' => 'field_readiness_nr10',
                        'has_aso' => $hasAso,
                    ],
                ]
            );
        }

        if ($progress === 'nr10') {
            $hasNr10 = $this->parseYesNoOption($messageBody, $parsedIntent);

            if ($hasNr10 === null) {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'field_readiness',
                    $this->buildYesNoRetryMessage('NR10'),
                    'Resposta invalida para NR10.'
                );
            }

            $fieldReadinessData['has_nr10'] = $hasNr10;
            $reply = $this->buildFieldReadinessNr35PromptMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'field_readiness' => $fieldReadinessData,
                'field_readiness_progress' => 'nr35',
                'last_prompt' => 'field_readiness_nr35',
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
                        'transition' => 'field_readiness_nr35',
                        'has_nr10' => $hasNr10,
                    ],
                ]
            );
        }

        if ($progress === 'nr35') {
            $hasNr35 = $this->parseYesNoOption($messageBody, $parsedIntent);

            if ($hasNr35 === null) {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'field_readiness',
                    $this->buildYesNoRetryMessage('NR35'),
                    'Resposta invalida para NR35.'
                );
            }

            $fieldReadinessData['has_nr35'] = $hasNr35;
            $reply = $this->buildFieldReadinessToolkitPromptMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'field_readiness' => $fieldReadinessData,
                'field_readiness_progress' => 'toolkit',
                'last_prompt' => 'field_readiness_toolkit',
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
                        'transition' => 'field_readiness_toolkit',
                        'has_nr35' => $hasNr35,
                    ],
                ]
            );
        }

        if ($progress === 'toolkit') {
            $hasToolkit = $this->parseYesNoOption($messageBody, $parsedIntent);

            if ($hasToolkit === null) {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'field_readiness',
                    $this->buildYesNoRetryMessage('ferramental completo'),
                    'Resposta invalida para ferramental completo.'
                );
            }

            $fieldReadinessData['has_complete_toolkit'] = $hasToolkit;

            if ($hasToolkit) {
                $reply = $this->buildFieldReadinessToolkitDescriptionPromptMessage();
                $updatedCollectedData = $this->mergeCollectedData($session, [
                    'field_readiness' => $fieldReadinessData,
                    'field_readiness_progress' => 'toolkit_description',
                    'last_prompt' => 'field_readiness_toolkit_description',
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
                            'transition' => 'field_readiness_toolkit_description',
                            'has_complete_toolkit' => true,
                        ],
                    ]
                );
            }

            $fieldReadinessData['tool_description'] = null;
            $fieldReadinessData['tool_items'] = [];

            return $this->finalizeFieldReadiness($session, $messageBody, $preFilterData, $fieldReadinessData);
        }

        if ($progress === 'toolkit_description') {
            $toolDescription = trim($messageBody);

            if ($toolDescription === '') {
                return $this->handleInvalidStepReply(
                    $session,
                    $messageBody,
                    'field_readiness',
                    $this->buildFieldReadinessToolkitDescriptionRetryMessage(),
                    'Descricao de ferramental vazia.'
                );
            }

            $fieldReadinessData['tool_description'] = $toolDescription;
            $fieldReadinessData['tool_items'] = $this->extractToolItems($toolDescription);

            return $this->finalizeFieldReadiness($session, $messageBody, $preFilterData, $fieldReadinessData);
        }

        return $this->handleInvalidStepReply(
            $session,
            $messageBody,
            'field_readiness',
            $this->buildFieldReadinessAsoPromptMessage(),
            'Etapa de qualificacao tecnica inconsistente.'
        );
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $preFilterData
     * @param array<string, mixed> $fieldReadinessData
     * @return array<string, mixed>
     */
    private function finalizeFieldReadiness(
        array $session,
        string $messageBody,
        array $preFilterData,
        array $fieldReadinessData
    ): array {
        $classification = $this->buildW13Classification($preFilterData, $fieldReadinessData);
        $baseCollectedData = [
            'prefilter' => $preFilterData,
            'field_readiness' => $fieldReadinessData,
            'classification' => $classification,
        ];

        if (($classification['status'] ?? 'pending') === 'rejected') {
            $reply = $this->buildRejectedTechnicalMessage($classification);
            $updatedCollectedData = $this->mergeCollectedData($session, array_merge($baseCollectedData, [
                'flow_status' => 'reprovado_tecnico',
                'last_prompt' => 'completed',
            ]));

            return $this->advanceSession(
                $session,
                [
                    'triage_status' => 'rejected_unavailable',
                    'current_step' => 'completed',
                    'automation_status' => 'completed',
                    'needs_operator' => false,
                    'invalid_reply_count' => 0,
                    'fallback_reason' => null,
                    'collected_data' => $updatedCollectedData,
                    'last_inbound_message' => $messageBody,
                    'last_outbound_message' => $reply,
                    'last_interaction_at' => date('Y-m-d H:i:s'),
                ],
                [
                    'parsed_intent' => 'not_interested',
                    'triage_status' => 'rejected_unavailable',
                    'current_step' => 'completed',
                    'automation_status' => 'completed',
                    'needs_operator' => false,
                    'candidate_status' => 'rejected',
                    'auto_reply' => $reply,
                    'metadata' => [
                        'flow_status' => 'reprovado_tecnico',
                        'classification_status' => $classification['status'] ?? 'rejected',
                    ],
                ]
            );
        }

        if (($classification['status'] ?? 'pending') === 'bank') {
            $reply = $this->buildBankMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, array_merge($baseCollectedData, [
                'flow_status' => 'banco',
                'last_prompt' => 'completed',
            ]));

            return $this->advanceSession(
                $session,
                [
                    'triage_status' => 'awaiting_validation',
                    'current_step' => 'completed',
                    'automation_status' => 'completed',
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
                    'triage_status' => 'awaiting_validation',
                    'current_step' => 'completed',
                    'automation_status' => 'completed',
                    'needs_operator' => false,
                    'candidate_status' => 'responded',
                    'auto_reply' => $reply,
                    'metadata' => [
                        'flow_status' => 'banco',
                        'classification_status' => $classification['status'] ?? 'bank',
                    ],
                ]
            );
        }

        $portalDispatch = $this->buildPortalDispatchData((int) ($session['candidate_id'] ?? 0));
        $reply = (string) ($portalDispatch['message'] ?? $this->buildAnalysisQueueMessage());
        $updatedCollectedData = $this->mergeCollectedData($session, array_merge($baseCollectedData, [
            'docs_progress' => 'portal_sent',
            'documents' => [
                'requested_at' => date('Y-m-d H:i:s'),
                'confirmed' => false,
                'portal_generated' => (bool) ($portalDispatch['generated'] ?? false),
                'portal_url' => $portalDispatch['portal_url'] ?? null,
                'portal_short_url' => $portalDispatch['portal_short_url'] ?? null,
                'portal_dispatch_error' => $portalDispatch['error'] ?? null,
            ],
            'flow_status' => ($classification['status'] ?? 'pending') === 'approved'
                ? 'pre_aprovado'
                : 'pendente_documentacao',
            'last_prompt' => 'waiting_validation',
        ]));

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
                'candidate_status' => 'awaiting_docs',
                'auto_reply' => $reply,
                'metadata' => [
                    'transition' => 'waiting_validation',
                    'flow_status' => ($classification['status'] ?? 'pending') === 'approved' ? 'pre_aprovado' : 'pendente_documentacao',
                    'classification_status' => $classification['status'] ?? 'pending',
                    'technical_level' => $classification['technical_level'] ?? null,
                    'field_level' => $classification['field_level'] ?? null,
                    'premium_candidate' => $classification['premium_candidate'] ?? false,
                    'limited_profile' => $classification['limited_profile'] ?? false,
                    'portal_generated' => (bool) ($portalDispatch['generated'] ?? false),
                    'portal_short_url' => $portalDispatch['portal_short_url'] ?? null,
                ],
            ]
        );
    }

    /**
     * @return array{generated:bool,portal_url:?string,portal_short_url:?string,message:string,error:?string}
     */
    private function buildPortalDispatchData(int $candidateId): array
    {
        if ($candidateId < 1) {
            return [
                'generated' => false,
                'portal_url' => null,
                'portal_short_url' => null,
                'message' => $this->buildAnalysisQueueMessage(),
                'error' => 'Candidato invalido para geracao de portal.',
            ];
        }

        try {
            $portal = $this->portalService->generatePortalForCandidate($candidateId, 'triage_bot');
            $portalUrl = $this->portalService->buildPortalPublicUrl($portal);
            $portalShortUrl = $this->portalService->buildPortalShortUrl($portal);
            $fullName = $this->resolveCandidateFullName($candidateId);

            return [
                'generated' => true,
                'portal_url' => $portalUrl,
                'portal_short_url' => $portalShortUrl,
                'message' => $this->portalService->buildPortalLinkMessage($fullName, $portalShortUrl),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'generated' => false,
                'portal_url' => null,
                'portal_short_url' => null,
                'message' => $this->buildAnalysisQueueMessage(),
                'error' => trim($exception->getMessage()) !== '' ? $exception->getMessage() : 'Falha ao gerar portal.',
            ];
        }
    }

    private function resolveCandidateFullName(int $candidateId): string
    {
        $candidate = $this->candidateModel->findById($candidateId);

        if ($candidate === null) {
            return 'Tecnico';
        }

        $fullName = trim((string) ($candidate['full_name'] ?? ''));

        return $fullName !== '' ? $fullName : 'Tecnico';
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function handleDocumentCollectionStep(array $session, string $messageBody, string $parsedIntent): array
    {
        $option = $this->parseMenuOption($messageBody, ['1', '2']);
        $normalized = $this->normalizeFreeText($messageBody);

        if ($option === null) {
            if ($parsedIntent === 'interested' || str_contains($normalized, 'conclu') || str_contains($normalized, 'enviei')) {
                $option = '1';
            } elseif (str_contains($normalized, 'depois') || str_contains($normalized, 'ajuda')) {
                $option = '2';
            }
        }

        if ($option === '1') {
            $reply = $this->buildAnalysisQueueMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'documents' => [
                    'confirmed' => true,
                    'confirmed_at' => date('Y-m-d H:i:s'),
                ],
                'flow_status' => 'cadastro_em_analise',
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
                    'candidate_status' => 'docs_sent',
                    'auto_reply' => $reply,
                    'metadata' => [
                        'transition' => 'waiting_validation',
                        'flow_status' => 'cadastro_em_analise',
                        'documents_confirmed' => true,
                    ],
                ]
            );
        }

        if ($option === '2') {
            $reply = $this->buildDocumentCollectionReminderMessage();
            $updatedCollectedData = $this->mergeCollectedData($session, [
                'documents' => [
                    'confirmed' => false,
                    'last_reminder_at' => date('Y-m-d H:i:s'),
                ],
                'flow_status' => 'pendente_documentacao',
                'last_prompt' => 'document_collection',
            ]);

            return $this->advanceSession(
                $session,
                [
                    'triage_status' => 'awaiting_validation',
                    'current_step' => 'approval_confirmation',
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
                    'parsed_intent' => 'needs_details',
                    'candidate_status' => 'awaiting_docs',
                    'auto_reply' => $reply,
                    'metadata' => [
                        'flow_status' => 'pendente_documentacao',
                        'documents_confirmed' => false,
                    ],
                ]
            );
        }

        return $this->handleInvalidStepReply(
            $session,
            $messageBody,
            'approval_confirmation',
            $this->buildDocumentCollectionRetryMessage(),
            'Resposta invalida na coleta documental.'
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
                'metadata' => array_merge(
                    ['transition' => 'completed'],
                    is_array($result['metadata'] ?? null) ? $result['metadata'] : []
                ),
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
        $currentData = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : $this->defaultCollectedData();

        return array_replace_recursive($currentData, $newData);
    }

    private function normalizeWorkflowStep(string $step): string
    {
        return match ($step) {
            'qualification' => 'prefilter',
            default => $step,
        };
    }

    private function resolveInboundLogStepKey(string $step, array $session): string
    {
        $collectedData = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];

        return match ($step) {
            'prefilter' => 'prefilter_' . (string) ($collectedData['prefilter_progress'] ?? 'city_state'),
            'field_readiness' => 'field_readiness_' . (string) ($collectedData['field_readiness_progress'] ?? 'aso'),
            'approval_confirmation' => 'document_collection',
            default => $step,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultCollectedData(): array
    {
        return [
            'flow_status' => 'lead_novo',
            'last_prompt' => 'initial_offer',
            'prefilter_progress' => 'city_state',
            'field_readiness_progress' => 'aso',
            'docs_progress' => 'awaiting_confirmation',
            'prefilter' => [],
            'field_readiness' => [],
            'classification' => [],
            'documents' => [],
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

    private function parseYesNoOption(string $messageBody, string $parsedIntent = 'unknown'): ?bool
    {
        $option = $this->parseMenuOption($messageBody, ['1', '2']);

        if ($option === '1') {
            return true;
        }

        if ($option === '2') {
            return false;
        }

        if ($parsedIntent === 'interested') {
            return true;
        }

        if (in_array($parsedIntent, ['not_interested', 'opt_out'], true)) {
            return false;
        }

        $normalized = $this->normalizeFreeText($messageBody);

        if (
            str_contains($normalized, 'nao')
            || str_contains($normalized, 'nao tenho')
            || str_contains($normalized, 'nao possui')
            || str_contains($normalized, 'inativo')
            || str_contains($normalized, 'vencido')
        ) {
            return false;
        }

        if (
            str_contains($normalized, 'sim')
            || str_contains($normalized, 'tenho')
            || str_contains($normalized, 'possuo')
            || str_contains($normalized, 'ativo')
            || str_contains($normalized, 'regular')
        ) {
            return true;
        }

        return null;
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
        $status = $this->resolveClassificationStatus($preFilterData, $fieldReadinessData, $technicalLevel, $fieldLevel);
        $premiumCandidate = $this->isPremiumCandidate($preFilterData, $fieldReadinessData, $serviceKeys);
        $limitedProfile = (($preFilterData['has_notebook'] ?? true) !== true)
            || (($preFilterData['has_console_cable'] ?? true) !== true);

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

        $safetyScore = 0;

        foreach (['has_aso', 'has_nr10', 'has_nr35'] as $field) {
            if (($fieldReadinessData[$field] ?? null) === true) {
                $safetyScore++;
            }
        }

        return [
            'status' => $status,
            'status_label' => $this->classificationStatusLabel($status),
            'technical_level' => $technicalLevel,
            'technical_level_label' => $technicalLevel ?? 'Nao classificado',
            'field_level' => $fieldLevel,
            'field_level_label' => $this->fieldLevelLabel($fieldLevel),
            'service_keys' => $serviceKeys,
            'service_labels' => $serviceLabels,
            'premium_candidate' => $premiumCandidate,
            'limited_profile' => $limitedProfile,
            'ready_for_documentation' => in_array($status, ['approved', 'pending'], true),
            'safety_score' => $safetyScore,
            'missing_requirements' => $missingRequirements,
        ];
    }

    private function resolveClassificationStatus(
        array $preFilterData,
        array $fieldReadinessData,
        ?string $technicalLevel,
        string $fieldLevel
    ): string {
        if (($preFilterData['mei_active'] ?? null) !== true) {
            return 'rejected';
        }

        if (($preFilterData['immediate_availability'] ?? null) !== true) {
            return 'bank';
        }

        if ($technicalLevel === null) {
            return 'pending';
        }

        if ($fieldLevel === 'restricted') {
            return 'rejected';
        }

        if (
            $fieldLevel === 'complete'
            && ($fieldReadinessData['has_complete_toolkit'] ?? null) === true
        ) {
            return 'approved';
        }

        return 'pending';
    }

    /**
     * @param list<string> $serviceKeys
     */
    private function isPremiumCandidate(array $preFilterData, array $fieldReadinessData, array $serviceKeys): bool
    {
        return ($preFilterData['mei_active'] ?? null) === true
            && ($preFilterData['immediate_availability'] ?? null) === true
            && $serviceKeys !== []
            && ($fieldReadinessData['has_aso'] ?? null) === true
            && ($fieldReadinessData['has_nr10'] ?? null) === true
            && ($fieldReadinessData['has_nr35'] ?? null) === true
            && ($fieldReadinessData['has_complete_toolkit'] ?? null) === true;
    }

    /**
     * @param list<string> $serviceKeys
     */
    private function resolveTechnicalLevel(array $serviceKeys): ?string
    {
        foreach ($serviceKeys as $serviceKey) {
            if (in_array($serviceKey, ['vsat', 'servidores'], true)) {
                return 'N3';
            }
        }

        foreach ($serviceKeys as $serviceKey) {
            if (in_array($serviceKey, ['redes_firewall', 'cabeamento'], true)) {
                return 'N2';
            }
        }

        foreach ($serviceKeys as $serviceKey) {
            if (in_array($serviceKey, ['microinformatica', 'impressoras'], true)) {
                return 'N1';
            }
        }

        return null;
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

        if ($value === '') {
            return [null, null];
        }

        if (preg_match('/^(.+?)\s*[\/-]\s*([A-Za-z]{2})$/u', $value, $matches) === 1) {
            return [trim($matches[1]), mb_strtoupper($matches[2])];
        }

        if (preg_match('/^(.+?),\s*([A-Za-z]{2})$/u', $value, $matches) === 1) {
            return [trim($matches[1]), mb_strtoupper($matches[2])];
        }

        if (preg_match('/\b([A-Za-z]{2})\b/u', $value, $matches) === 1) {
            $state = mb_strtoupper($matches[1]);
            $city = trim(preg_replace('/\b' . preg_quote($matches[1], '/') . '\b/u', '', $value) ?? '');

            return [$city !== '' ? $city : null, $state];
        }

        return [null, null];
    }

    private function requireSession(int $campaignRecipientId): array
    {
        $session = $this->triageModel->findSessionByCampaignRecipientId($campaignRecipientId);

        return $session ?? [];
    }

    private function buildInfoCompanyMessage(): string
    {
        return "A W13 Tecnologia atua com atendimentos técnicos em campo em várias regiões do Brasil.\n\n" .
            "Buscamos parceiros para atividades como:\n" .
            "1 - VSAT\n" .
            "2 - Redes / Firewall\n" .
            "3 - Microinformática\n" .
            "4 - Impressoras\n" .
            "5 - Cabeamento\n" .
            "6 - Servidores\n\n" .
            "Deseja seguir com o pré-cadastro?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildPreFilterCityPromptMessage(): string
    {
        return "Perfeito! Vamos iniciar seu pré-cadastro na W13. ✅\n\n" .
            "Informe sua cidade e estado. 📍";
    }

    private function buildPreFilterMeiPromptMessage(): string
    {
        return "Você possui MEI ativo?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildPreFilterNotebookPromptMessage(): string
    {
        return "Você possui notebook próprio?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildPreFilterConsolePromptMessage(): string
    {
        return "Você possui cabo console?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    /**
     * @param list<string> $selectedServices
     */
    private function buildPreFilterServicesPromptMessage(array $selectedServices = []): string
    {
        $selectedLine = $selectedServices !== []
            ? "\nSelecionados até agora: " . implode(', ', $selectedServices) . "\n"
            : "\n";

        return "Selecione as opções: quais serviços você atende?\n" .
            "Você pode escolher vários itens. ✅\n" .
            "Escolha um por vez e, ao terminar, selecione: Concluir seleção." .
            $selectedLine .
            "\nSelecione:\n" .
            "1 - VSAT\n" .
            "2 - Redes / Firewall\n" .
            "3 - Microinformática\n" .
            "4 - Impressoras\n" .
            "5 - Cabeamento\n" .
            "6 - Servidores\n" .
            "9 - CONCLUIR SELEÇÃO";
    }

    private function buildPreFilterAvailabilityPromptMessage(): string
    {
        return "Você possui disponibilidade imediata para atendimentos?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildFieldReadinessAsoPromptMessage(): string
    {
        return "Agora vamos validar sua aptidão para campo.\n\n" .
            "Você possui ASO válido?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildFieldReadinessNr10PromptMessage(): string
    {
        return "Você possui NR10?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildFieldReadinessNr35PromptMessage(): string
    {
        return "Você possui NR35?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildFieldReadinessToolkitPromptMessage(): string
    {
        return "Você possui ferramental completo para atendimento em campo?\n\n" .
            "Digite:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildFieldReadinessToolkitDescriptionPromptMessage(): string
    {
        return "Descreva seu ferramental principal.\n\n" .
            "Exemplo: multímetro, crimpador, testador de rede, kit de chaves.";
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function buildDocumentCollectionPromptMessage(array $classification): string
    {
        return "Seu perfil foi pré-aprovado na W13. ✅\n\n" .
            "Para concluir seu cadastro, vamos enviar o link do portal com as instruções e documentos obrigatórios.\n\n" .
            "Responda:\n" .
            "1 - RECEBI O LINK\n" .
            "2 - PRECISO DE AJUDA";
    }

    private function buildDocumentCollectionReminderMessage(): string
    {
        return "Sem problema.\n\n" .
            "Seu cadastro ficou com pendência documental.\n" .
            "Envie os documentos obrigatórios e depois responda:\n" .
            "1 - DOCUMENTOS ENVIADOS";
    }

    private function buildAnalysisQueueMessage(): string
    {
        return "Recebemos suas informações.\n\n" .
            "Seu cadastro está em análise pela equipe da W13.\n" .
            "Assim que a validação for concluída, você receberá o retorno por este número.";
    }

    private function buildRejectedMeiMessage(): string
    {
        return "No momento, para atuar com a W13, é obrigatório possuir MEI ativo para formalização contratual e emissão de nota fiscal.\n\n" .
            "Quando sua situação estiver regularizada, teremos prazer em retomar seu cadastro.";
    }

    private function buildBankMessage(): string
    {
        return "Seu perfil foi direcionado para banco de talentos da W13 por indisponibilidade imediata.\n\n" .
            "Vamos manter seu contato para próximas oportunidades na sua região.";
    }

    /**
     * @param array<string, mixed> $classification
     */
    private function buildRejectedTechnicalMessage(array $classification): string
    {
        $missingRequirements = is_array($classification['missing_requirements'] ?? null)
            ? $classification['missing_requirements']
            : [];
        $missingLine = $missingRequirements !== []
            ? implode(', ', array_map(static fn (mixed $value): string => (string) $value, $missingRequirements))
            : 'critérios técnicos de campo';

        return "No momento seu perfil não avançou para ativação na W13.\n\n" .
            "Pontos pendentes: {$missingLine}.\n\n" .
            "Quando regularizar esses itens, podemos reavaliar seu cadastro.";
    }

    private function buildNotInterestedMessage(): string
    {
        return "Sem problemas. Agradecemos seu retorno.\n\n" .
            "Caso tenha interesse futuramente, estaremos à disposição.\n" .
            "Equipe W13 Tecnologia";
    }

    private function buildInitialOfferRetryMessage(): string
    {
        return "Não consegui identificar sua opção.\n\n" .
            "Responda com:\n" .
            "1 - SIM\n" .
            "2 - NÃO\n" .
            "3 - MAIS INFORMAÇÕES";
    }

    private function buildDetailsRetryMessage(): string
    {
        return "Responda com uma opção válida:\n\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildPreFilterCityRetryMessage(): string
    {
        return "Preciso da sua cidade e estado para continuar. 📍";
    }

    private function buildPreFilterServicesRetryMessage(): string
    {
        return "Selecione as opções: quais serviços você atende?\n" .
            "Você pode escolher vários itens. ✅\n" .
            "Escolha um por vez e, ao terminar, selecione: Concluir seleção.\n\n" .
            "Selecione:\n" .
            "1 - VSAT\n" .
            "2 - Redes / Firewall\n" .
            "3 - Microinformática\n" .
            "4 - Impressoras\n" .
            "5 - Cabeamento\n" .
            "6 - Servidores\n" .
            "9 - CONCLUIR SELEÇÃO";
    }

    private function buildFieldReadinessToolkitDescriptionRetryMessage(): string
    {
        return "Descreva brevemente seu ferramental principal para concluir a qualificação.";
    }

    private function buildDocumentCollectionRetryMessage(): string
    {
        return "Para continuar, responda:\n" .
            "1 - DOCUMENTOS ENVIADOS\n" .
            "2 - ENVIAR DEPOIS";
    }

    private function buildYesNoRetryMessage(string $subject): string
    {
        return "Resposta inválida para {$subject}.\n\n" .
            "Responda:\n" .
            "1 - SIM\n" .
            "2 - NÃO";
    }

    private function buildOperatorFallbackMessage(): string
    {
        return "Recebi sua mensagem e vou encaminhar o atendimento para um operador da W13 continuar por aqui.";
    }
}
