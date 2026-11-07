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
use Onlime\SendmailWrapper\ThrottleStatus;

$config = require __DIR__ . '/bootstrap.php';

// must be run via sudo (as sendmailwrapper user), with the recipient count as only argument
$username = getenv('SUDO_USER');
$args = array_slice($_SERVER['argv'], 1);
if (! $username || count($args) !== 1 || ! ctype_digit($args[0])) {
    fwrite(STDERR, "Usage: sudo -u sendmailwrapper sendmail-throttle <recipient-count>\n");
    exit(ThrottleStatus::Exception->value);
}

$throttle = new SendmailThrottle($config, Message::fromStdin(), env: getenv());

exit($throttle->run($username, (int) $args[0])->value);
