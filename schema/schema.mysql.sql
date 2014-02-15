SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';

CREATE SCHEMA IF NOT EXISTS `sendmailwrapper` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci ;
USE `sendmailwrapper` ;

-- -----------------------------------------------------
-- Table `sendmailwrapper`.`throttle`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `sendmailwrapper`.`throttle` ;

CREATE TABLE IF NOT EXISTS `sendmailwrapper`.`throttle` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(30) NOT NULL DEFAULT '',
  `create_ts` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_ts` TIMESTAMP NULL DEFAULT NULL,
  `count_max` INT NOT NULL DEFAULT 0 COMMENT 'maximum sent emails per time period',
  `count_cur` INT NOT NULL DEFAULT 1 COMMENT 'email count of current time period',
  `count_tot` INT NOT NULL DEFAULT 1 COMMENT 'total email count',
  `rcpt_max` INT NOT NULL DEFAULT 0 COMMENT 'maximum recipient count per time period',
  `rcpt_cur` INT NOT NULL DEFAULT 1 COMMENT 'recipient count of current time period',
  `rcpt_tot` INT NOT NULL DEFAULT 1 COMMENT 'total recipient count',
  `status` TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`))
  ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `sendmailwrapper`.`messages`
-- -----------------------------------------------------
DROP TABLE IF EXISTS `sendmailwrapper`.`messages` ;

CREATE TABLE IF NOT EXISTS `sendmailwrapper`.`messages` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `throttle_id` INT NULL,
  `sent_ts` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'timestamp this email got throttled/sent',
  `username` VARCHAR(30) NULL COMMENT 'system user',
  `uid` INT NULL COMMENT 'system uid',
  `gid` INT NULL COMMENT 'system gid',
  `rcpt_count` INT NOT NULL DEFAULT 1 COMMENT 'number of recipients',
  `status` INT NOT NULL DEFAULT 0 COMMENT 'throttle status code (exit code)',
  `msgid` VARCHAR(255) NULL COMMENT 'sendmail-wrapper message ID',
  `from_addr` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'from address',
  `to_addr` TEXT NOT NULL DEFAULT '' COMMENT 'to address(es)',
  `cc_addr` TEXT NOT NULL DEFAULT '' COMMENT 'Cc address(es)',
  `bcc_addr` TEXT NOT NULL DEFAULT '' COMMENT 'Bcc address(es)',
  `subject` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'email subject',
  `site` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'site where this email was sent from',
  `client` VARCHAR(40) NULL COMMENT 'client IP that invoked the email generation',
  `script` VARCHAR(255) NULL COMMENT 'script that generated this email',
  PRIMARY KEY (`id`),
  INDEX `fk_messages_throttle_idx` (`throttle_id` ASC),
  CONSTRAINT `fk_messages_throttle`
  FOREIGN KEY (`throttle_id`)
  REFERENCES `sendmailwrapper`.`throttle` (`id`)
    ON DELETE SET NULL
    ON UPDATE CASCADE)
  ENGINE = InnoDB;


SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
