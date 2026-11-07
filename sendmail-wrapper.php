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
use Onlime\SendmailWrapper\SendmailWrapper;

$config = require __DIR__ . '/bootstrap.php';

$wrapper = new SendmailWrapper(
    $config,
    Message::fromStdin(),
    // get arguments from $_SERVER global (as register_argc_argv might be disabled)
    array_slice($_SERVER['argv'], 1),
    getenv(),
);

exit($wrapper->run());
