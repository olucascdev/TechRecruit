CREATE TABLE IF NOT EXISTS `recruit_form_field_settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `config_key` VARCHAR(120) NOT NULL,
    `config_kind` ENUM('field', 'section') NOT NULL,
    `label` VARCHAR(255) NOT NULL,
    `is_required` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_by` VARCHAR(120) NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_recruit_form_field_settings_key_kind` (`config_key`, `config_kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
