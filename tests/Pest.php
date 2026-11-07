<?php

declare(strict_types=1);

use Onlime\SendmailWrapper\Config;
use Onlime\SendmailWrapper\Message;
use Onlime\SendmailWrapper\SendmailThrottle;
use Onlime\SendmailWrapper\ThrottleStatus;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->in('.');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Create a temporary directory, removed after the test run.
 */
function tempDir(): string
{
    $dir = sys_get_temp_dir() . '/sendmail-wrapper-tests-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($dir)));

    return $dir;
}

/**
 * Default configuration from config.ini (ignoring any local config files), with overrides.
 */
function testConfig(array $overrides = []): Config
{
    $values = parse_ini_file(dirname(__DIR__) . '/config.ini', true, INI_SCANNER_TYPED);

    return new Config(array_replace_recursive($values, $overrides));
}

/**
 * Command line of a fake command (test double for sendmail and the throttle command),
 * recording its arguments and STDIN to $dir/$name.args and $dir/$name.stdin.
 */
function fakeCommand(string $dir, string $name, int $exitCode = 0): string
{
    return sprintf('%s/Fixtures/bin/fake-command %s/%s %d', __DIR__, $dir, $name, $exitCode);
}

/**
 * Arguments and STDIN a fake command has been called with, or null if it has not been called.
 *
 * @return array{args: list<string>, stdin: string}|null
 */
function fakeCommandCall(string $dir, string $name): ?array
{
    if (! is_file("$dir/$name.args")) {
        return null;
    }

    return [
        'args' => file("$dir/$name.args", FILE_IGNORE_NEW_LINES),
        'stdin' => file_get_contents("$dir/$name.stdin"),
    ];
}

const THROTTLE_MAIL = "From: =?UTF-8?Q?M=C3=BCller?= <sender@example.com>\nTo: a@example.com, b@example.com\n"
    . "Subject: =?UTF-8?B?SMOkbGxv?=\nX-Meta-MsgID: 20261107120000.abc@example.com\nX-Meta-Site: www.example.com\n"
    . "X-Meta-Client: 192.0.2.1\nX-Meta-Script: /var/www/index.php\n\nBody\n";

/**
 * Fresh throttle database: MySQL/MariaDB if TEST_MYSQL_DSN is set (see README), in-memory SQLite otherwise.
 */
function throttleDb(): PDO
{
    if (getenv('TEST_MYSQL_DSN')) {
        $pdo = mysqlConnection();
        $pdo->exec('DROP DATABASE IF EXISTS sendmailwrapper_test');
        $pdo->exec(mysqlSql('schema/schema.mysql.sql'));

        return $pdo;
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(file_get_contents(__DIR__ . '/Fixtures/schema.sqlite.sql'));

    return $pdo;
}

/**
 * @return array{status: ThrottleStatus, log: list<array{int, string}>, mails: list<list<string>>}
 */
function runThrottle(
    PDO $pdo,
    int $rcptCount = 2,
    string $now = '2026-11-07 12:00:00',
    string $username = 'web1',
    string $data = THROTTLE_MAIL,
    array $config = [],
): array {
    $log = $mails = [];
    $throttle = new SendmailThrottle(
        testConfig(array_replace_recursive(['throttle' => ['countMax' => 3, 'rcptMax' => 10]], $config)),
        new Message($data),
        $pdo,
        ['SUDO_UID' => '1001', 'SUDO_GID' => '1002', 'SUDO_COMMAND' => '/usr/sbin/sendmail-throttle 2'],
        new DateTimeImmutable($now),
        function (int $priority, string $message) use (&$log) {
            $log[] = [$priority, $message];
        },
        function (string ...$args) use (&$mails) {
            $mails[] = $args;
        },
    );

    return ['status' => $throttle->run($username, $rcptCount), 'log' => $log, 'mails' => $mails];
}

function throttleRow(PDO $pdo, string $username = 'web1'): array
{
    $stmt = $pdo->prepare('SELECT * FROM throttle WHERE username = ?');
    $stmt->execute([$username]);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Connection to the MySQL/MariaDB server configured by TEST_MYSQL_DSN, TEST_MYSQL_USER and TEST_MYSQL_PASSWORD.
 */
function mysqlConnection(?string $dbname = null): PDO
{
    return new PDO(
        getenv('TEST_MYSQL_DSN') . ($dbname ? ";dbname=$dbname" : ''),
        getenv('TEST_MYSQL_USER') ?: 'root',
        (string) getenv('TEST_MYSQL_PASSWORD'),
    );
}

/**
 * SQL file (schema or migration), using the sendmailwrapper_test database instead of sendmailwrapper.
 */
function mysqlSql(string $file): string
{
    return str_replace('`sendmailwrapper`', '`sendmailwrapper_test`', file_get_contents(dirname(__DIR__) . "/$file"));
}
