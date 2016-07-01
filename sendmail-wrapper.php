#!/usr/bin/php -n
<?php
/**
 * Sendmail Wrapper by Onlime Webhosting
 * https://github.com/onlime/sendmail-wrapper
 *
 * @copyright  Copyright (c) 2007-2014 Onlime Webhosting (http://www.onlime.ch)
 */

require_once 'app/SendmailWrapper.php';

$sendmailWrapper = new SendmailWrapper();
$status = $sendmailWrapper->run();
exit($status);
