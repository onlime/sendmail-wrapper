SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';


CREATE SCHEMA IF NOT EXISTS `sendmailwrapper` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
USE `sendmailwrapper`;

-- -----------------------------------------------------
-- Table `sendmail_throttle`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `throttle` (
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


SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
