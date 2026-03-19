ALTER TABLE `recruit_message_queue`
    ADD COLUMN `provider_message_custom_id` VARCHAR(100) NULL AFTER `payload`,
    ADD COLUMN `provider_message_id` VARCHAR(100) NULL AFTER `provider_message_custom_id`,
    ADD COLUMN `provider_waid` VARCHAR(100) NULL AFTER `provider_message_id`,
    ADD COLUMN `provider_message_state` VARCHAR(50) NULL AFTER `provider_waid`,
    ADD COLUMN `provider_last_event` VARCHAR(50) NULL AFTER `provider_message_state`,
    ADD COLUMN `provider_payload` JSON NULL AFTER `provider_last_event`,
    ADD COLUMN `delivered_at` TIMESTAMP NULL DEFAULT NULL AFTER `provider_payload`,
    ADD COLUMN `read_at` TIMESTAMP NULL DEFAULT NULL AFTER `delivered_at`,
    ADD KEY `idx_recruit_message_queue_provider_custom_id` (`provider_message_custom_id`),
    ADD KEY `idx_recruit_message_queue_provider_message_id` (`provider_message_id`),
    ADD KEY `idx_recruit_message_queue_provider_waid` (`provider_waid`);

ALTER TABLE `recruit_whatsapp_inbound`
    ADD COLUMN `contact_name` VARCHAR(255) NULL AFTER `source_contact`,
    ADD COLUMN `chat_type` VARCHAR(50) NULL AFTER `contact_name`,
    ADD COLUMN `provider_message_id` VARCHAR(100) NULL AFTER `chat_type`,
    ADD COLUMN `provider_waid` VARCHAR(100) NULL AFTER `provider_message_id`,
    ADD COLUMN `group_id` VARCHAR(100) NULL AFTER `provider_waid`,
    ADD COLUMN `message_type` VARCHAR(50) NULL AFTER `group_id`,
    ADD COLUMN `message_state` VARCHAR(50) NULL AFTER `message_type`,
    ADD COLUMN `context_type` TINYINT UNSIGNED NULL AFTER `message_state`,
    ADD COLUMN `context_waid` VARCHAR(100) NULL AFTER `context_type`,
    ADD COLUMN `received_unix_time` BIGINT UNSIGNED NULL AFTER `context_waid`,
    ADD COLUMN `provider_payload` JSON NULL AFTER `received_unix_time`,
    ADD KEY `idx_recruit_whatsapp_inbound_provider_message_id` (`provider_message_id`),
    ADD KEY `idx_recruit_whatsapp_inbound_provider_waid` (`provider_waid`);

ALTER TABLE `recruit_message_logs`
    MODIFY COLUMN `event_type` ENUM(
        'sent',
        'failed',
        'reply',
        'opt_out',
        'paused',
        'resumed',
        'cancelled',
        'completed',
        'delivered',
        'read',
        'status_update',
        'phonestate'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS `recruit_whatsgw_phone_states` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `phone_number` VARCHAR(50) NOT NULL,
    `w_instancia_id` INT UNSIGNED NULL,
    `state` VARCHAR(50) NOT NULL,
    `last_event_at` TIMESTAMP NULL DEFAULT NULL,
    `provider_payload` JSON NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_recruit_whatsgw_phone_states_phone_number` (`phone_number`),
    KEY `idx_recruit_whatsgw_phone_states_instance_id` (`w_instancia_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `recruit_whatsgw_webhook_events` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type` VARCHAR(50) NOT NULL,
    `phone_number` VARCHAR(50) NULL,
    `contact_phone_number` VARCHAR(50) NULL,
    `provider_message_id` VARCHAR(100) NULL,
    `provider_waid` VARCHAR(100) NULL,
    `process_status` ENUM('processed', 'ignored', 'failed') NOT NULL DEFAULT 'processed',
    `result_message` VARCHAR(255) NULL,
    `payload` JSON NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_recruit_whatsgw_webhook_events_event_type` (`event_type`),
    KEY `idx_recruit_whatsgw_webhook_events_contact_phone` (`contact_phone_number`),
    KEY `idx_recruit_whatsgw_webhook_events_provider_message_id` (`provider_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
