CREATE TABLE IF NOT EXISTS `recruit_whatsapp_inbound` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id` INT UNSIGNED NOT NULL,
    `campaign_recipient_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `source_contact` VARCHAR(255) NOT NULL,
    `message_body` TEXT NOT NULL,
    `parsed_intent` ENUM('interested', 'not_interested', 'opt_out', 'unknown') NOT NULL DEFAULT 'unknown',
    `received_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_whatsapp_inbound_campaign_id` (`campaign_id`),
    KEY `idx_recruit_whatsapp_inbound_candidate_id` (`candidate_id`),
    KEY `idx_recruit_whatsapp_inbound_received_at` (`received_at`),
    CONSTRAINT `fk_recruit_whatsapp_inbound_campaign`
        FOREIGN KEY (`campaign_id`) REFERENCES `recruit_campaigns` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_whatsapp_inbound_campaign_recipient`
        FOREIGN KEY (`campaign_recipient_id`) REFERENCES `recruit_campaign_recipients` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_whatsapp_inbound_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_opt_outs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `candidate_id` INT UNSIGNED NOT NULL,
    `contact_value` VARCHAR(255) NOT NULL,
    `source` ENUM('campaign_reply', 'manual') NOT NULL DEFAULT 'campaign_reply',
    `reason` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_recruit_opt_out_candidate_contact` (`candidate_id`, `contact_value`),
    KEY `idx_recruit_opt_outs_candidate_id` (`candidate_id`),
    KEY `idx_recruit_opt_outs_created_at` (`created_at`),
    CONSTRAINT `fk_recruit_opt_outs_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_message_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id` INT UNSIGNED NOT NULL,
    `campaign_recipient_id` INT UNSIGNED NULL,
    `candidate_id` INT UNSIGNED NULL,
    `direction` ENUM('outbound', 'inbound', 'system') NOT NULL DEFAULT 'system',
    `event_type` ENUM('sent', 'failed', 'reply', 'opt_out', 'paused', 'resumed', 'cancelled', 'completed') NOT NULL,
    `message_body` TEXT NULL,
    `metadata` JSON NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_message_logs_campaign_id` (`campaign_id`),
    KEY `idx_recruit_message_logs_candidate_id` (`candidate_id`),
    KEY `idx_recruit_message_logs_created_at` (`created_at`),
    CONSTRAINT `fk_recruit_message_logs_campaign`
        FOREIGN KEY (`campaign_id`) REFERENCES `recruit_campaigns` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_message_logs_campaign_recipient`
        FOREIGN KEY (`campaign_recipient_id`) REFERENCES `recruit_campaign_recipients` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_message_logs_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
