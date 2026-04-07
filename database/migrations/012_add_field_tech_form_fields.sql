-- Campos adicionais para candidatos vindos do formulário de técnico de campo

ALTER TABLE `recruit_candidates`
    ADD COLUMN `source` VARCHAR(50) NULL DEFAULT NULL AFTER `source_batch_id`,
    ADD COLUMN `birth_date` DATE NULL DEFAULT NULL AFTER `source`,
    ADD COLUMN `rg` VARCHAR(50) NULL DEFAULT NULL AFTER `birth_date`,
    ADD COLUMN `cnpj` VARCHAR(18) NULL DEFAULT NULL AFTER `rg`,
    ADD COLUMN `company_name` VARCHAR(255) NULL DEFAULT NULL AFTER `cnpj`,
    ADD COLUMN `issues_invoice` TINYINT(1) NULL DEFAULT NULL AFTER `company_name`,
    ADD COLUMN `full_address` TEXT NULL DEFAULT NULL AFTER `issues_invoice`,
    ADD COLUMN `equipment_list` TEXT NULL DEFAULT NULL AFTER `full_address`,
    ADD COLUMN `transport_modes` VARCHAR(255) NULL DEFAULT NULL AFTER `equipment_list`,
    ADD COLUMN `availability_days` VARCHAR(255) NULL DEFAULT NULL AFTER `transport_modes`,
    ADD COLUMN `service_cities` TEXT NULL DEFAULT NULL AFTER `availability_days`,
    ADD COLUMN `bank_name` VARCHAR(100) NULL DEFAULT NULL AFTER `service_cities`,
    ADD COLUMN `bank_agency` VARCHAR(20) NULL DEFAULT NULL AFTER `bank_name`,
    ADD COLUMN `bank_account` VARCHAR(30) NULL DEFAULT NULL AFTER `bank_agency`,
    ADD COLUMN `bank_holder_name` VARCHAR(255) NULL DEFAULT NULL AFTER `bank_account`,
    ADD COLUMN `bank_holder_doc` VARCHAR(18) NULL DEFAULT NULL AFTER `bank_holder_name`,
    ADD COLUMN `pix_key` VARCHAR(255) NULL DEFAULT NULL AFTER `bank_holder_doc`;
