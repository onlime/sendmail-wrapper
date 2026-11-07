-- 2026-11-07: Convert to utf8mb4, to support 4-byte UTF-8 characters (e.g. emoji) in subjects, etc.
-- NOTE: Converting the messages table copies the whole table and blocks writes while running.
--       Purge old messages first and run it during low traffic.
ALTER DATABASE `sendmailwrapper` CHARACTER SET utf8mb4;
ALTER TABLE `sendmailwrapper`.`throttle` CONVERT TO CHARACTER SET utf8mb4;
ALTER TABLE `sendmailwrapper`.`messages` CONVERT TO CHARACTER SET utf8mb4;
