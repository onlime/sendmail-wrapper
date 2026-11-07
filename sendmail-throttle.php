#!/usr/bin/php -d auto_prepend_file=
<?php

declare(strict_types=1);

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */

use Onlime\SendmailWrapper\SendmailThrottle;

require __DIR__ . '/bootstrap.php';

// extract main parameters
$username  = $_SERVER['SUDO_USER'];
$rcptCount = (int) @$argv[1];

// do throttling
$status = (new SendmailThrottle)->run($username, $rcptCount);
exit($status);
