<?php

declare(strict_types=1);

use Onlime\SendmailWrapper\ThrottleStatus;

/*
 * Tests requiring a real MySQL/MariaDB server, as SQLite does not lock records (SELECT ... FOR UPDATE) and
 * does not enforce column sizes and character sets. All other throttle tests run against MySQL as well,
 * if TEST_MYSQL_DSN is set (see README).
 */

beforeEach(function () {
    if (! getenv('TEST_MYSQL_DSN')) {
        $this->markTestSkipped('TEST_MYSQL_DSN not set');
    }
});

it('counts concurrently sent messages exactly', function () {
    $pdo = throttleDb();
    $start = tempDir() . '/start';
    $processes = $outputs = [];
    foreach (range(1, 20) as $i) {
        $processes[] = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/throttle.php', 'web1', '2', '10', $start],
            [1 => ['pipe', 'w']],
            $pipes,
        );
        $outputs[] = $pipes[1];
    }
    touch($start);
    $statuses = array_map(stream_get_contents(...), $outputs);
    array_map(proc_close(...), $processes);

    expect(array_count_values($statuses))->toEqual([
        ThrottleStatus::Ok->value => 10,
        ThrottleStatus::QuotaReached->value => 1,
        ThrottleStatus::OverQuota->value => 9,
    ])
        ->and($pdo->query('SELECT COUNT(*) FROM throttle')->fetchColumn())->toBe(1)
        ->and(throttleRow($pdo))->toMatchArray(['count_cur' => 20, 'rcpt_cur' => 40, 'status' => 2])
        ->and($pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn())->toBe(20);
});

it('locks the throttle record until the counters are updated', function () {
    $pdo = throttleDb();
    runThrottle($pdo);
    $other = mysqlConnection('sendmailwrapper_test');
    $other->beginTransaction();
    $other->query("SELECT * FROM throttle WHERE username = 'web1' FOR UPDATE");
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

    $result = runThrottle($pdo);
    $other->rollBack();

    expect($result['status'])->toBe(ThrottleStatus::Exception)
        ->and($result['log'][0][1])->toContain('Lock wait timeout exceeded')
        ->and(throttleRow($pdo)['count_cur'])->toBe(1);
});

it('does not block other users while a throttle record is locked', function () {
    $pdo = throttleDb();
    runThrottle($pdo, username: 'web1');
    runThrottle($pdo, username: 'web2');
    $other = mysqlConnection('sendmailwrapper_test');
    $other->beginTransaction();
    $other->query("SELECT * FROM throttle WHERE username = 'web1' FOR UPDATE");
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

    $result = runThrottle($pdo, username: 'web2');
    $other->rollBack();

    expect($result['status'])->toBe(ThrottleStatus::Ok)
        ->and(throttleRow($pdo, 'web2')['count_cur'])->toBe(2);
});

it('migrates the database of version 1.1', function () {
    $pdo = throttleDb();
    // schema of version 1.1
    $pdo->exec(<<<'SQL'
        ALTER TABLE throttle DROP INDEX idx_username;
        ALTER DATABASE sendmailwrapper_test CHARACTER SET utf8mb3;
        ALTER TABLE throttle CONVERT TO CHARACTER SET utf8mb3;
        ALTER TABLE messages CONVERT TO CHARACTER SET utf8mb3;
        SQL);
    $data = "From: sender@example.com\nTo: a@example.com\nSubject: Hällo 🚀\n\nBody";

    $before = runThrottle($pdo, data: $data);
    $pdo->exec(mysqlSql('schema/migrations/20261107-utf8mb4.sql'));
    $pdo->exec(mysqlSql('schema/migrations/20261107-add-username-index.sql'));
    $after = runThrottle($pdo, data: $data);

    expect($before['status'])->toBe(ThrottleStatus::Ok)
        ->and($before['log'][1][1])->toContain('Could not log message to database')
        ->and($after['status'])->toBe(ThrottleStatus::Ok)
        ->and($after['log'])->toHaveCount(1)
        ->and($pdo->query('SELECT subject FROM messages')->fetchAll(PDO::FETCH_COLUMN))->toBe(['Hällo 🚀'])
        ->and($pdo->query("SELECT DISTINCT character_set_name FROM information_schema.columns
            WHERE table_schema = 'sendmailwrapper_test' AND character_set_name IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN))
        ->toBe(['utf8mb4'])
        ->and($pdo->query("SHOW INDEX FROM throttle WHERE Key_name = 'idx_username'")->fetchAll())->toHaveCount(1);
});
