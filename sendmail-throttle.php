#!/usr/bin/php
<?php
/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */

require_once 'app/SendmailThrottle.php';

// extract main parameters
$username  = $_SERVER['SUDO_USER'];
$rcptCount = (int) @$argv[1];

// do throttling
$sendmailThrottle = new SendmailThrottle();
$status = $sendmailThrottle->run($username, $rcptCount);
exit($status);
