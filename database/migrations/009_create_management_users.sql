CREATE TABLE IF NOT EXISTS `recruit_management_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'manager') NOT NULL DEFAULT 'manager',
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `last_login_at` TIMESTAMP NULL DEFAULT NULL,
    `created_by` VARCHAR(100) NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_recruit_management_users_email` (`email`),
    KEY `idx_recruit_management_users_role` (`role`),
    KEY `idx_recruit_management_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
