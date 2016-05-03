#!/usr/bin/env php -n
<?php
/**
 * Sendmail Wrapper by Onlime Webhosting
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright  Copyright (c) 2007-2014 Onlime Webhosting (http://www.onlime.ch)
 */

require_once 'app/SendmailThrottle.php';

// extract main parameters
$username  = $_SERVER['SUDO_USER'];
$rcptCount = (int) @$argv[1];

// do throttling
$sendmailThrottle = new SendmailThrottle();
$status = $sendmailThrottle->run($username, $rcptCount);
exit($status);
