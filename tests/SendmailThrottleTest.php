<?php

declare(strict_types=1);

use Onlime\SendmailWrapper\Message;
use Onlime\SendmailWrapper\SendmailThrottle;
use Onlime\SendmailWrapper\ThrottleStatus;

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

it('updates the throttle record in a transaction', function () {
    $pdo = throttleDb();
    runThrottle($pdo);
    $pdo->exec($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? "CREATE TRIGGER fail_update BEFORE UPDATE ON throttle FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'update failed'"
        : "CREATE TRIGGER fail_update BEFORE UPDATE ON throttle BEGIN SELECT RAISE(ABORT, 'update failed'); END");

    $result = runThrottle($pdo);

    expect($result['status'])->toBe(ThrottleStatus::Exception)
        ->and($result['log'][0][1])->toContain('update failed')
        ->and($pdo->inTransaction())->toBeFalse()
        ->and(throttleRow($pdo)['count_cur'])->toBe(1);
});

it('commits the throttle record before notifying the admin', function () {
    $pdo = throttleDb();
    $inTransaction = null;
    $throttle = new SendmailThrottle(
        testConfig(['throttle' => ['rcptMax' => 1]]),
        new Message(THROTTLE_MAIL),
        $pdo,
        log: fn () => null,
        mail: function () use ($pdo, &$inTransaction) {
            $inTransaction = $pdo->inTransaction();
        },
    );

    expect($throttle->run('web1', 2))->toBe(ThrottleStatus::QuotaReached)
        ->and($inTransaction)->toBeFalse();
});

it('retries on deadlocks', function (int $deadlocks, ThrottleStatus $status) {
    $pdo = new class($deadlocks) extends PDO
    {
        public function __construct(public int $deadlocks)
        {
            parent::__construct('sqlite::memory:');
            $this->exec(file_get_contents(__DIR__ . '/Fixtures/schema.sqlite.sql'));
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if (str_starts_with($query, 'INSERT INTO throttle') && $this->deadlocks-- > 0) {
                throw new class('Deadlock found when trying to get lock; try restarting transaction') extends PDOException
                {
                    protected $code = '40001';
                };
            }

            return parent::prepare($query, $options);
        }
    };

    $result = runThrottle($pdo);

    expect($result['status'])->toBe($status)
        ->and($pdo->inTransaction())->toBeFalse()
        ->and($pdo->query('SELECT COUNT(*) FROM throttle')->fetchColumn())->toBe($status === ThrottleStatus::Ok ? 1 : 0);
})->with([
    'once' => [1, ThrottleStatus::Ok],
    'four times' => [4, ThrottleStatus::Ok],
    'five times' => [5, ThrottleStatus::Exception],
]);

it('truncates the message meta information to the column sizes', function () {
    $pdo = throttleDb();
    runThrottle($pdo, data: "From: sender@example.com\nTo: " . str_repeat('x', 70000) . "\nSubject: " . str_repeat('s', 300)
        . "\nX-Meta-Client: " . str_repeat('1', 60) . "\nX-Meta-Site: " . str_repeat('s', 300) . "\n\nBody");

    $row = $pdo->query('SELECT * FROM messages')->fetch(PDO::FETCH_ASSOC);

    expect(mb_strlen($row['subject']))->toBe(255)
        ->and(mb_strlen($row['client']))->toBe(40)
        ->and(mb_strlen($row['site']))->toBe(255)
        ->and(strlen($row['to_addr']))->toBe(65535);
});

it('does not fail if the message cannot be logged', function () {
    $pdo = throttleDb();
    $pdo->exec('DROP TABLE messages');

    $result = runThrottle($pdo, rcptCount: 11);

    expect($result['status'])->toBe(ThrottleStatus::QuotaReached)
        ->and($result['mails'])->toHaveCount(1)
        ->and($result['log'][1][0])->toBe(LOG_WARNING)
        ->and($result['log'][1][1])->toStartWith('sendmail-throttle-php: Could not log message to database: ')
        ->and(throttleRow($pdo)['rcpt_cur'])->toBe(11);
});

it('decodes encoded-words but keeps raw UTF-8 in header values', function (string $subject, string $decoded) {
    $pdo = throttleDb();
    runThrottle($pdo, data: "From: sender@example.com\nTo: a@example.com\nSubject: $subject\n\nBody");

    expect($pdo->query('SELECT subject FROM messages')->fetchColumn())->toBe($decoded);
})->with([
    'raw UTF-8' => ['Hällo Wörld 🚀', 'Hällo Wörld 🚀'],
    'Q encoding' => ['=?UTF-8?Q?H=C3=A4llo?= World', 'Hällo World'],
    'B encoding' => ['=?utf-8?b?SMOkbGxv?=', 'Hällo'],
    'adjacent encoded-words' => ['=?UTF-8?Q?H=C3=A4?= =?UTF-8?Q?llo?= World', 'Hällo World'],
    'mixed raw and encoded' => ['Wörld =?UTF-8?Q?H=C3=A4llo?=', 'Wörld Hällo'],
    'ISO-8859-1' => ['=?ISO-8859-1?Q?M=FCller?=', 'Müller'],
    'not encoded' => ['=?invalid', '=?invalid'],
]);

it('truncates TEXT columns without breaking multibyte characters', function () {
    $pdo = throttleDb();
    runThrottle($pdo, data: "From: sender@example.com\nTo: " . str_repeat('ä', 40000) . "\n\nBody");

    $toAddr = $pdo->query('SELECT to_addr FROM messages')->fetchColumn();

    expect(strlen($toAddr))->toBe(65534)
        ->and(mb_check_encoding($toAddr, 'UTF-8'))->toBeTrue();
});
