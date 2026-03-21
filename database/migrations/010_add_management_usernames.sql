ALTER TABLE `recruit_management_users`
    ADD COLUMN `username` VARCHAR(60) NULL AFTER `email`;

UPDATE `recruit_management_users`
SET `username` = CONCAT('user', `id`)
WHERE `username` IS NULL
   OR TRIM(`username`) = '';

ALTER TABLE `recruit_management_users`
    MODIFY COLUMN `username` VARCHAR(60) NOT NULL AFTER `email`;

ALTER TABLE `recruit_management_users`
    ADD UNIQUE KEY `uniq_recruit_management_users_username` (`username`);
