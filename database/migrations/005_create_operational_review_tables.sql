ALTER TABLE `recruit_candidate_portals`
    MODIFY COLUMN `status` ENUM(
        'draft',
        'link_sent',
        'in_progress',
        'submitted',
        'under_review',
        'correction_requested',
        'approved',
        'rejected',
        'expired'
    ) NOT NULL DEFAULT 'draft';

ALTER TABLE `recruit_candidate_documents`
    MODIFY COLUMN `review_status` ENUM(
        'pending',
        'approved',
        'rejected',
        'correction_requested'
    ) NOT NULL DEFAULT 'pending';

CREATE TABLE IF NOT EXISTS `recruit_review_pendencies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `candidate_id` INT UNSIGNED NOT NULL,
    `portal_id` INT UNSIGNED NOT NULL,
    `document_id` INT UNSIGNED NULL,
    `title` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `status` ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
    `created_by` VARCHAR(100) NOT NULL,
    `resolved_by` VARCHAR(100) NULL,
    `resolved_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_review_pendencies_candidate_id` (`candidate_id`),
    KEY `idx_recruit_review_pendencies_portal_id` (`portal_id`),
    KEY `idx_recruit_review_pendencies_document_id` (`document_id`),
    KEY `idx_recruit_review_pendencies_status` (`status`),
    CONSTRAINT `fk_recruit_review_pendencies_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_review_pendencies_portal`
        FOREIGN KEY (`portal_id`) REFERENCES `recruit_candidate_portals` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_review_pendencies_document`
        FOREIGN KEY (`document_id`) REFERENCES `recruit_candidate_documents` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_review_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `candidate_id` INT UNSIGNED NOT NULL,
    `portal_id` INT UNSIGNED NOT NULL,
    `document_id` INT UNSIGNED NULL,
    `action` ENUM(
        'note',
        'approve',
        'reject',
        'request_correction',
        'document_approve',
        'document_reject',
        'document_request_correction',
        'pendency_resolved',
        'status_sync'
    ) NOT NULL,
    `message` TEXT NULL,
    `created_by` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_review_history_candidate_id` (`candidate_id`),
    KEY `idx_recruit_review_history_portal_id` (`portal_id`),
    KEY `idx_recruit_review_history_document_id` (`document_id`),
    KEY `idx_recruit_review_history_created_at` (`created_at`),
    CONSTRAINT `fk_recruit_review_history_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_review_history_portal`
        FOREIGN KEY (`portal_id`) REFERENCES `recruit_candidate_portals` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_review_history_document`
        FOREIGN KEY (`document_id`) REFERENCES `recruit_candidate_documents` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
