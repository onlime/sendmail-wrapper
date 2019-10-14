#!/usr/bin/php
<?php
/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */

require_once 'app/SendmailWrapper.php';

$sendmailWrapper = new SendmailWrapper();
$status = $sendmailWrapper->run();
exit($status);
