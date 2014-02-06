-- -----------------------------------------------------
-- Table `sendmail_throttle`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `sendmail_throttle` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(30) NOT NULL DEFAULT '',
  `create_ts` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `count_max` INT NOT NULL DEFAULT 0 COMMENT 'maximum sent emails per time period',
  `count_cur` INT NOT NULL DEFAULT 1 COMMENT 'email count of current time period',
  `count_tot` INT NOT NULL DEFAULT 1 COMMENT 'total email count',
  `rcpt_max` INT NOT NULL DEFAULT 0 COMMENT 'maximum recipient count per time period',
  `rcpt_cur` INT NOT NULL DEFAULT 1 COMMENT 'recipient count of current time period',
  `rcpt_tot` INT NOT NULL DEFAULT 1 COMMENT 'total recipient count',
  `status` TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`))
ENGINE = InnoDB;
