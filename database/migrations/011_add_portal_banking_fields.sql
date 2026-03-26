ALTER TABLE `recruit_candidate_portal_profiles`
    ADD COLUMN `secondary_phone` VARCHAR(30) NULL AFTER `whatsapp`,
    ADD COLUMN `service_region` VARCHAR(255) NULL AFTER `region`,
    ADD COLUMN `bank_name` VARCHAR(120) NULL AFTER `service_region`,
    ADD COLUMN `bank_agency` VARCHAR(40) NULL AFTER `bank_name`,
    ADD COLUMN `bank_account` VARCHAR(60) NULL AFTER `bank_agency`;

ALTER TABLE `recruit_candidate_documents`
    MODIFY COLUMN `document_type` ENUM(
        'documento_identidade',
        'comprovante_residencia',
        'cartao_mei',
        'comprovante_bancario',
        'aso',
        'nr10',
        'nr35',
        'curriculo',
        'certificado_tecnico',
        'outro'
    ) NOT NULL;
