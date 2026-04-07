-- Campos adicionais para candidatos vindos do formulário de técnico de campo

ALTER TABLE `recruit_candidates`
    ADD COLUMN IF NOT EXISTS `source` VARCHAR(50) NULL DEFAULT NULL AFTER `source_batch_id`,
    ADD COLUMN IF NOT EXISTS `birth_date` DATE NULL DEFAULT NULL AFTER `source`,
    ADD COLUMN IF NOT EXISTS `rg` VARCHAR(50) NULL DEFAULT NULL AFTER `birth_date`,
    ADD COLUMN IF NOT EXISTS `cnpj` VARCHAR(18) NULL DEFAULT NULL AFTER `rg`,
    ADD COLUMN IF NOT EXISTS `company_name` VARCHAR(255) NULL DEFAULT NULL AFTER `cnpj`,
    ADD COLUMN IF NOT EXISTS `issues_invoice` TINYINT(1) NULL DEFAULT NULL AFTER `company_name`,
    ADD COLUMN IF NOT EXISTS `full_address` TEXT NULL DEFAULT NULL AFTER `issues_invoice`,
    ADD COLUMN IF NOT EXISTS `equipment_list` TEXT NULL DEFAULT NULL AFTER `full_address`,
    ADD COLUMN IF NOT EXISTS `transport_modes` VARCHAR(255) NULL DEFAULT NULL AFTER `equipment_list`,
    ADD COLUMN IF NOT EXISTS `availability_days` VARCHAR(255) NULL DEFAULT NULL AFTER `transport_modes`,
    ADD COLUMN IF NOT EXISTS `service_cities` TEXT NULL DEFAULT NULL AFTER `availability_days`,
    ADD COLUMN IF NOT EXISTS `bank_name` VARCHAR(100) NULL DEFAULT NULL AFTER `service_cities`,
    ADD COLUMN IF NOT EXISTS `bank_agency` VARCHAR(20) NULL DEFAULT NULL AFTER `bank_name`,
    ADD COLUMN IF NOT EXISTS `bank_account` VARCHAR(30) NULL DEFAULT NULL AFTER `bank_agency`,
    ADD COLUMN IF NOT EXISTS `bank_holder_name` VARCHAR(255) NULL DEFAULT NULL AFTER `bank_account`,
    ADD COLUMN IF NOT EXISTS `bank_holder_doc` VARCHAR(18) NULL DEFAULT NULL AFTER `bank_holder_name`,
    ADD COLUMN IF NOT EXISTS `pix_key` VARCHAR(255) NULL DEFAULT NULL AFTER `bank_holder_doc`;
