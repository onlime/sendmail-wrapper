#!/usr/bin/php -d auto_prepend_file=
<?php
/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
require_once __DIR__ . '/app/SendmailWrapper.php';

$status = (new SendmailWrapper())->run();
exit($status);
