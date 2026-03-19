CREATE TABLE IF NOT EXISTS `recruit_candidate_portals` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `candidate_id` INT UNSIGNED NOT NULL,
    `access_token` CHAR(64) NOT NULL,
    `status` ENUM(
        'draft',
        'link_sent',
        'in_progress',
        'submitted',
        'under_review',
        'approved',
        'rejected',
        'expired'
    ) NOT NULL DEFAULT 'draft',
    `terms_version` VARCHAR(30) NOT NULL DEFAULT 'portal-v1',
    `terms_accepted` TINYINT(1) NOT NULL DEFAULT 0,
    `terms_accepted_at` TIMESTAMP NULL DEFAULT NULL,
    `last_accessed_at` TIMESTAMP NULL DEFAULT NULL,
    `submitted_at` TIMESTAMP NULL DEFAULT NULL,
    `created_by` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_recruit_candidate_portals_candidate_id` (`candidate_id`),
    UNIQUE KEY `uniq_recruit_candidate_portals_access_token` (`access_token`),
    KEY `idx_recruit_candidate_portals_status` (`status`),
    CONSTRAINT `fk_recruit_candidate_portals_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_candidate_portal_profiles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portal_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `cpf` VARCHAR(14) NULL,
    `birth_date` DATE NULL,
    `whatsapp` VARCHAR(30) NULL,
    `email` VARCHAR(255) NULL,
    `state` CHAR(2) NULL,
    `city` VARCHAR(150) NULL,
    `region` VARCHAR(100) NULL,
    `availability` VARCHAR(150) NULL,
    `experience_summary` TEXT NULL,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_recruit_candidate_portal_profiles_portal_id` (`portal_id`),
    UNIQUE KEY `uniq_recruit_candidate_portal_profiles_candidate_id` (`candidate_id`),
    KEY `idx_recruit_candidate_portal_profiles_candidate_id` (`candidate_id`),
    CONSTRAINT `fk_recruit_candidate_portal_profiles_portal`
        FOREIGN KEY (`portal_id`) REFERENCES `recruit_candidate_portals` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_candidate_portal_profiles_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_candidate_documents` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `portal_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `document_type` ENUM(
        'documento_identidade',
        'comprovante_residencia',
        'curriculo',
        'certificado_tecnico',
        'outro'
    ) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_path` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NULL,
    `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
    `review_status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_candidate_documents_portal_id` (`portal_id`),
    KEY `idx_recruit_candidate_documents_candidate_id` (`candidate_id`),
    KEY `idx_recruit_candidate_documents_document_type` (`document_type`),
    CONSTRAINT `fk_recruit_candidate_documents_portal`
        FOREIGN KEY (`portal_id`) REFERENCES `recruit_candidate_portals` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_candidate_documents_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
