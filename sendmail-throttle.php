#!/usr/bin/php -d auto_prepend_file=
<?php

declare(strict_types=1);

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */

use Onlime\SendmailWrapper\Message;
use Onlime\SendmailWrapper\SendmailThrottle;

$config = require __DIR__ . '/bootstrap.php';

// extract main parameters
$username = (string) getenv('SUDO_USER');
$rcptCount = (int) ($_SERVER['argv'][1] ?? 0);

$throttle = new SendmailThrottle($config, Message::fromStdin(), env: getenv());

exit($throttle->run($username, $rcptCount)->value);
