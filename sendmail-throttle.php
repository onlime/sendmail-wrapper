#!/usr/bin/php -d auto_prepend_file=
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
$status = (new SendmailThrottle())->run($username, $rcptCount);
exit($status);
