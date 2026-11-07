<?php

declare(strict_types=1);

/*
 * Run the throttle in a separate process, for concurrency tests against MySQL (see tests/MysqlTest.php):
 * php throttle.php <username> <recipient-count> <count-max> <start-file>
 * Waits until <start-file> exists, so that all processes run at the same time, and prints the throttle status.
 */

use Onlime\SendmailWrapper\Config;
use Onlime\SendmailWrapper\Message;
use Onlime\SendmailWrapper\SendmailThrottle;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $username, $rcptCount, $countMax, $startFile] = $argv;

$config = parse_ini_file(dirname(__DIR__, 2) . '/config.ini', true, INI_SCANNER_TYPED);
$config['throttle']['countMax'] = (int) $countMax;
$config['db'] = [
    'dsn' => getenv('TEST_MYSQL_DSN') . ';dbname=sendmailwrapper_test',
    'user' => getenv('TEST_MYSQL_USER') ?: 'root',
    'pass' => (string) getenv('TEST_MYSQL_PASSWORD'),
];

while (! file_exists($startFile)) {
    usleep(1000);
}

$throttle = new SendmailThrottle(
    new Config($config),
    new Message("From: sender@example.com\nTo: a@example.com\n\nBody\n"),
    log: fn () => null,
    mail: fn () => null,
);

echo $throttle->run($username, (int) $rcptCount)->value;
