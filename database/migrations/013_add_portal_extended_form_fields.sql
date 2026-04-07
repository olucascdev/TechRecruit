ALTER TABLE `recruit_candidate_portal_profiles`
    ADD COLUMN `rg` VARCHAR(60) NULL AFTER `birth_date`,
    ADD COLUMN `company_name` VARCHAR(255) NULL AFTER `rg`,
    ADD COLUMN `issues_invoice` TINYINT(1) NULL AFTER `company_name`,
    ADD COLUMN `full_address` TEXT NULL AFTER `issues_invoice`,
    ADD COLUMN `equipment_list` TEXT NULL AFTER `full_address`,
    ADD COLUMN `transport_modes` VARCHAR(255) NULL AFTER `equipment_list`,
    ADD COLUMN `availability_days` VARCHAR(255) NULL AFTER `transport_modes`,
    ADD COLUMN `service_cities` TEXT NULL AFTER `availability_days`,
    ADD COLUMN `bank_holder_name` VARCHAR(255) NULL AFTER `bank_account`,
    ADD COLUMN `bank_holder_doc` VARCHAR(18) NULL AFTER `bank_holder_name`;
