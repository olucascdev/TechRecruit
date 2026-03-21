ALTER TABLE `recruit_triage_sessions`
    MODIFY COLUMN `flow_version` VARCHAR(20) NOT NULL DEFAULT '0.4.0';

ALTER TABLE `recruit_triage_sessions`
    MODIFY COLUMN `current_step` ENUM(
        'initial_offer',
        'details_followup',
        'qualification',
        'prefilter',
        'field_readiness',
        'waiting_validation',
        'approval_confirmation',
        'completed',
        'operator_fallback'
    ) NOT NULL DEFAULT 'initial_offer';

ALTER TABLE `recruit_candidate_portal_profiles`
    ADD COLUMN `cnpj` VARCHAR(18) NULL AFTER `cpf`,
    ADD COLUMN `pix_key` VARCHAR(255) NULL AFTER `cnpj`;

ALTER TABLE `recruit_candidate_documents`
    MODIFY COLUMN `document_type` ENUM(
        'documento_identidade',
        'comprovante_residencia',
        'cartao_mei',
        'aso',
        'nr10',
        'nr35',
        'curriculo',
        'certificado_tecnico',
        'outro'
    ) NOT NULL;
