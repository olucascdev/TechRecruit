CREATE TABLE IF NOT EXISTS `recruit_import_batches` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename` VARCHAR(255) NOT NULL,
    `total_rows` INT NOT NULL DEFAULT 0,
    `imported_rows` INT NOT NULL DEFAULT 0,
    `error_rows` INT NOT NULL DEFAULT 0,
    `status` ENUM('pending', 'processing', 'done', 'failed') NOT NULL DEFAULT 'pending',
    `created_by` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_import_batches_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_candidates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(255) NOT NULL,
    `cpf` VARCHAR(14) NULL,
    `status` ENUM(
        'imported',
        'queued',
        'message_sent',
        'responded',
        'not_interested',
        'interested',
        'awaiting_docs',
        'docs_sent',
        'under_review',
        'approved',
        'rejected',
        'awaiting_contract',
        'contract_signed',
        'closed'
    ) NOT NULL DEFAULT 'imported',
    `source_batch_id` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_recruit_candidates_cpf` (`cpf`),
    KEY `idx_recruit_candidates_status` (`status`),
    KEY `idx_recruit_candidates_source_batch_id` (`source_batch_id`),
    CONSTRAINT `fk_recruit_candidates_source_batch`
        FOREIGN KEY (`source_batch_id`) REFERENCES `recruit_import_batches` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_import_rows` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `batch_id` INT UNSIGNED NOT NULL,
    `row_number` INT NOT NULL,
    `raw_data` JSON NOT NULL,
    `status` ENUM('ok', 'error', 'duplicate') NOT NULL DEFAULT 'ok',
    `error_message` TEXT NULL,
    `candidate_id` INT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_import_rows_batch_id` (`batch_id`),
    KEY `idx_recruit_import_rows_candidate_id` (`candidate_id`),
    KEY `idx_recruit_import_rows_status` (`status`),
    CONSTRAINT `fk_recruit_import_rows_batch`
        FOREIGN KEY (`batch_id`) REFERENCES `recruit_import_batches` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_import_rows_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_candidate_contacts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `candidate_id` INT UNSIGNED NOT NULL,
    `type` ENUM('phone', 'whatsapp', 'email') NOT NULL,
    `value` VARCHAR(255) NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_candidate_contacts_candidate_id` (`candidate_id`),
    CONSTRAINT `fk_recruit_candidate_contacts_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_candidate_skills` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `candidate_id` INT UNSIGNED NOT NULL,
    `skill` VARCHAR(100) NOT NULL,
    `level` ENUM('junior', 'pleno', 'senior', 'nao_informado') NOT NULL DEFAULT 'nao_informado',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_candidate_skills_candidate_id` (`candidate_id`),
    CONSTRAINT `fk_recruit_candidate_skills_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_candidate_addresses` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `candidate_id` INT UNSIGNED NOT NULL,
    `state` CHAR(2) NOT NULL,
    `city` VARCHAR(150) NOT NULL,
    `region` VARCHAR(100) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_candidate_addresses_candidate_id` (`candidate_id`),
    CONSTRAINT `fk_recruit_candidate_addresses_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_candidate_status_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `candidate_id` INT UNSIGNED NOT NULL,
    `from_status` VARCHAR(50) NOT NULL,
    `to_status` VARCHAR(50) NOT NULL,
    `changed_by` VARCHAR(100) NOT NULL,
    `reason` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_candidate_status_history_candidate_id` (`candidate_id`),
    CONSTRAINT `fk_recruit_candidate_status_history_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
