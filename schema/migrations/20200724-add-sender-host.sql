-- 2020-07-24
ALTER TABLE `sendmailwrapper`.`messages` ADD `sender_host` VARCHAR(255) NOT NULL DEFAULT 'localhost' AFTER `client`;
