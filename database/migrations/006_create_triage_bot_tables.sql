ALTER TABLE `recruit_campaigns`
    ADD COLUMN `automation_type` ENUM('broadcast', 'triage_w13') NOT NULL DEFAULT 'broadcast'
    AFTER `channel`;

ALTER TABLE `recruit_whatsapp_inbound`
    MODIFY COLUMN `parsed_intent` ENUM('interested', 'not_interested', 'needs_details', 'opt_out', 'unknown') NOT NULL DEFAULT 'unknown';

CREATE TABLE IF NOT EXISTS `recruit_triage_sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id` INT UNSIGNED NOT NULL,
    `campaign_recipient_id` INT UNSIGNED NOT NULL,
    `candidate_id` INT UNSIGNED NOT NULL,
    `flow_version` VARCHAR(20) NOT NULL DEFAULT '0.3.0',
    `triage_status` ENUM(
        'sent',
        'no_response',
        'interested',
        'not_interested',
        'needs_details',
        'awaiting_validation',
        'approved',
        'rejected_unavailable'
    ) NOT NULL DEFAULT 'no_response',
    `current_step` ENUM(
        'initial_offer',
        'details_followup',
        'qualification',
        'waiting_validation',
        'approval_confirmation',
        'completed',
        'operator_fallback'
    ) NOT NULL DEFAULT 'initial_offer',
    `automation_status` ENUM('active', 'waiting_operator', 'completed') NOT NULL DEFAULT 'active',
    `needs_operator` TINYINT(1) NOT NULL DEFAULT 0,
    `invalid_reply_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `fallback_reason` VARCHAR(255) NULL,
    `collected_data` JSON NULL,
    `last_inbound_message` TEXT NULL,
    `last_outbound_message` TEXT NULL,
    `last_interaction_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_recruit_triage_sessions_campaign_recipient_id` (`campaign_recipient_id`),
    KEY `idx_recruit_triage_sessions_campaign_id` (`campaign_id`),
    KEY `idx_recruit_triage_sessions_candidate_id` (`candidate_id`),
    KEY `idx_recruit_triage_sessions_triage_status` (`triage_status`),
    KEY `idx_recruit_triage_sessions_automation_status` (`automation_status`),
    CONSTRAINT `fk_recruit_triage_sessions_campaign`
        FOREIGN KEY (`campaign_id`) REFERENCES `recruit_campaigns` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_triage_sessions_campaign_recipient`
        FOREIGN KEY (`campaign_recipient_id`) REFERENCES `recruit_campaign_recipients` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT `fk_recruit_triage_sessions_candidate`
        FOREIGN KEY (`candidate_id`) REFERENCES `recruit_candidates` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_triage_answers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `triage_session_id` INT UNSIGNED NOT NULL,
    `step_key` VARCHAR(50) NOT NULL,
    `message_direction` ENUM('inbound', 'outbound', 'system') NOT NULL DEFAULT 'inbound',
    `message_body` TEXT NOT NULL,
    `normalized_payload` JSON NULL,
    `created_by` VARCHAR(100) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_triage_answers_session_id` (`triage_session_id`),
    KEY `idx_recruit_triage_answers_step_key` (`step_key`),
    KEY `idx_recruit_triage_answers_created_at` (`created_at`),
    CONSTRAINT `fk_recruit_triage_answers_session`
        FOREIGN KEY (`triage_session_id`) REFERENCES `recruit_triage_sessions` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
