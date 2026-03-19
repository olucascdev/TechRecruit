CREATE TABLE IF NOT EXISTS `recruit_campaigns` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(150) NOT NULL,
    `channel` ENUM('whatsapp') NOT NULL DEFAULT 'whatsapp',
    `status` ENUM('draft', 'queued', 'sending', 'paused', 'completed', 'cancelled') NOT NULL DEFAULT 'draft',
    `message_template` TEXT NOT NULL,
    `segment_filters` JSON NOT NULL,
    `recipient_limit` INT UNSIGNED NULL,
    `audience_count` INT NOT NULL DEFAULT 0,
    `queued_count` INT NOT NULL DEFAULT 0,
    `created_by` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_campaigns_status` (`status`),
    KEY `idx_recruit_campaigns_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_campaign_recipients` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `candidate_name_snapshot` VARCHAR(255) NOT NULL,
    `candidate_status_snapshot` VARCHAR(50) NOT NULL,
    `destination_contact` VARCHAR(255) NOT NULL,
    `status` ENUM('queued', 'sent', 'responded', 'failed', 'opt_out', 'skipped') NOT NULL DEFAULT 'queued',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_recruit_campaign_recipient` (`campaign_id`, `candidate_id`),
    KEY `idx_recruit_campaign_recipients_campaign_id` (`campaign_id`),
    KEY `idx_recruit_campaign_recipients_candidate_id` (`candidate_id`),
    KEY `idx_recruit_campaign_recipients_status` (`status`),
    CONSTRAINT `fk_recruit_campaign_recipients_campaign`
        FOREIGN KEY (`campaign_id`) REFERENCES `recruit_campaigns` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_campaign_recipients_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_message_queue` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id` INT UNSIGNED NOT NULL,
    `campaign_recipient_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `channel` ENUM('whatsapp') NOT NULL DEFAULT 'whatsapp',
    `direction` ENUM('outbound') NOT NULL DEFAULT 'outbound',
    `destination_contact` VARCHAR(255) NOT NULL,
    `message_body` TEXT NOT NULL,
    `payload` JSON NULL,
    `status` ENUM('pending', 'processing', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    `attempt_count` INT NOT NULL DEFAULT 0,
    `scheduled_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` TIMESTAMP NULL DEFAULT NULL,
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_message_queue_campaign_id` (`campaign_id`),
    KEY `idx_recruit_message_queue_candidate_id` (`candidate_id`),
    KEY `idx_recruit_message_queue_status` (`status`),
    KEY `idx_recruit_message_queue_scheduled_at` (`scheduled_at`),
    CONSTRAINT `fk_recruit_message_queue_campaign`
        FOREIGN KEY (`campaign_id`) REFERENCES `recruit_campaigns` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_message_queue_campaign_recipient`
        FOREIGN KEY (`campaign_recipient_id`) REFERENCES `recruit_campaign_recipients` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_message_queue_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
