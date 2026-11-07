<?php

declare(strict_types=1);

use Onlime\SendmailWrapper\Message;
use Onlime\SendmailWrapper\SendmailThrottle;
use Onlime\SendmailWrapper\ThrottleStatus;

const THROTTLE_MAIL = "From: =?UTF-8?Q?M=C3=BCller?= <sender@example.com>\nTo: a@example.com, b@example.com\n"
    . "Subject: =?UTF-8?B?SMOkbGxv?=\nX-Meta-MsgID: 20261107120000.abc@example.com\nX-Meta-Site: www.example.com\n"
    . "X-Meta-Client: 192.0.2.1\nX-Meta-Script: /var/www/index.php\n\nBody\n";

function throttleDb(): PDO
{
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

it('creates a throttle record for new users with the configured limits', function () {
    $pdo = throttleDb();

    expect(runThrottle($pdo)['status'])->toBe(ThrottleStatus::Ok)
        ->and(throttleRow($pdo))->toMatchArray([
            'updated_ts' => '2026-11-07 12:00:00',
            'count_max' => 3, 'count_cur' => 1, 'count_tot' => 1,
            'rcpt_max' => 10, 'rcpt_cur' => 2, 'rcpt_tot' => 2,
            'status' => 0, 'blocked' => 0,
        ]);
});

it('logs the message meta information to the messages table', function () {
    $pdo = throttleDb();
    runThrottle($pdo);

    expect($pdo->query('SELECT * FROM messages')->fetchAll(PDO::FETCH_ASSOC))->toHaveCount(1)
        ->and($pdo->query('SELECT * FROM messages')->fetch(PDO::FETCH_ASSOC))->toMatchArray([
            'throttle_id' => throttleRow($pdo)['id'],
            'username' => 'web1',
            'uid' => 1001,
            'gid' => 1002,
            'rcpt_count' => 2,
            'status' => 0,
            'msgid' => '20261107120000.abc@example.com',
            'from_addr' => 'Müller <sender@example.com>',
            'to_addr' => 'a@example.com, b@example.com',
            'cc_addr' => '',
            'bcc_addr' => '',
            'subject' => 'Hällo',
            'site' => 'www.example.com',
            'client' => '192.0.2.1',
            'sender_host' => gethostname(),
            'script' => '/var/www/index.php',
        ]);
});

it('increments the counters on the same day', function () {
    $pdo = throttleDb();
    runThrottle($pdo, now: '2026-11-07 08:00:00');
    runThrottle($pdo, rcptCount: 3, now: '2026-11-07 23:59:59');

    expect(throttleRow($pdo))->toMatchArray([
        'updated_ts' => '2026-11-07 23:59:59',
        'count_cur' => 2, 'count_tot' => 2,
        'rcpt_cur' => 5, 'rcpt_tot' => 5,
    ]);
});

it('resets the current counters on a new day', function () {
    $pdo = throttleDb();
    runThrottle($pdo, now: '2026-11-07 23:59:59');
    runThrottle($pdo, rcptCount: 3, now: '2026-11-08 00:00:00');

    expect(throttleRow($pdo))->toMatchArray([
        'updated_ts' => '2026-11-08 00:00:00',
        'count_cur' => 1, 'count_tot' => 2,
        'rcpt_cur' => 3, 'rcpt_tot' => 5,
    ]);
});

it('checks the limits on the first message of a new user', function () {
    $pdo = throttleDb();
    $result = runThrottle($pdo, rcptCount: 11);

    expect($result['status'])->toBe(ThrottleStatus::QuotaReached)
        ->and($result['mails'])->toHaveCount(1)
        ->and(throttleRow($pdo))->toMatchArray(['rcpt_cur' => 11, 'status' => ThrottleStatus::QuotaReached->value]);
});

it('reports reaching the limit again on a new day', function () {
    $pdo = throttleDb();
    expect(runThrottle($pdo, rcptCount: 11, now: '2026-11-07 12:00:00')['status'])->toBe(ThrottleStatus::QuotaReached)
        ->and(runThrottle($pdo, rcptCount: 1, now: '2026-11-07 13:00:00')['status'])->toBe(ThrottleStatus::OverQuota);

    $result = runThrottle($pdo, rcptCount: 11, now: '2026-11-08 12:00:00');

    expect($result['status'])->toBe(ThrottleStatus::QuotaReached)
        ->and($result['mails'])->toHaveCount(1);
});

it('reports reaching the message limit to the admin only once', function () {
    $pdo = throttleDb();
    foreach (range(1, 3) as $i) {
        expect(runThrottle($pdo, rcptCount: 1)['status'])->toBe(ThrottleStatus::Ok);
    }

    $reached = runThrottle($pdo, rcptCount: 1);
    $over = runThrottle($pdo, rcptCount: 1);

    expect($reached['status'])->toBe(ThrottleStatus::QuotaReached)
        ->and($reached['mails'])->toBe([[
            'hostmaster@example.com',
            'Sendmail limit exceeded',
            $reached['log'][0][1],
            'From: hostmaster@example.com',
        ]])
        ->and($over['status'])->toBe(ThrottleStatus::OverQuota)
        ->and($over['mails'])->toBe([])
        ->and(throttleRow($pdo))->toMatchArray(['count_cur' => 5, 'status' => ThrottleStatus::OverQuota->value]);
});

it('reports reaching the recipient limit', function () {
    $pdo = throttleDb();
    runThrottle($pdo, rcptCount: 5);
    expect(runThrottle($pdo, rcptCount: 5)['status'])->toBe(ThrottleStatus::Ok)
        ->and(runThrottle($pdo, rcptCount: 1)['status'])->toBe(ThrottleStatus::QuotaReached);
});

it('blocks blocked users without logging to syslog or notifying the admin', function () {
    $pdo = throttleDb();
    runThrottle($pdo);
    $pdo->exec("UPDATE throttle SET blocked = 1, count_cur = 99 WHERE username = 'web1'");

    $result = runThrottle($pdo);

    expect($result['status'])->toBe(ThrottleStatus::Blocked)
        ->and($result['log'])->toBe([])
        ->and($result['mails'])->toBe([])
        ->and($pdo->query('SELECT status FROM messages ORDER BY id DESC')->fetchColumn())->toBe(ThrottleStatus::Blocked->value);
});

it('logs the throttle status to syslog', function () {
    $result = runThrottle(throttleDb());

    expect($result['log'])->toBe([[
        LOG_INFO,
        'sendmail-throttle-php: user=web1 (1001:1002), rcpts=2, status=0, command=/usr/sbin/sendmail-throttle 2, '
        . 'count_max=3, count_cur=1, count_tot=1, rcpt_max=10, rcpt_cur=2, rcpt_tot=2',
    ]]);
});

it('reports database errors as exception', function () {
    $result = runThrottle(new PDO('sqlite::memory:'));

    expect($result['status'])->toBe(ThrottleStatus::Exception)
        ->and($result['log'][0][0])->toBe(LOG_WARNING)
        ->and($result['log'][0][1])->toStartWith('sendmail-throttle-php: PDOException: ');
});
