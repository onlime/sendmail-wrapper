-- 2026-11-07: Index throttle.username, so that locking a user's throttle record (SELECT ... FOR UPDATE) does not
--             lock the whole table, blocking concurrently sent messages of all other users.
ALTER TABLE `sendmailwrapper`.`throttle` ADD INDEX `idx_username` (`username` ASC);
