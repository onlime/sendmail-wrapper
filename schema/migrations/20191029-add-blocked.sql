-- 2019-10-29
ALTER TABLE `sendmailwrapper`.`throttle` ADD `blocked` TINYINT NOT NULL DEFAULT 0 AFTER `status`;
