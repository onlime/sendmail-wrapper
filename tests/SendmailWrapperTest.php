<?php

declare(strict_types=1);

use Onlime\SendmailWrapper\Message;
use Onlime\SendmailWrapper\SendmailWrapper;

const MAIL = "From: sender@example.com\nTo: a@example.com, b@example.com\nCc: c@example.com\nSubject: Test\n\nBody\n";

/**
 * Run the wrapper with fake sendmail and throttle commands.
 *
 * @return array{status: int, sendmail: ?array, throttle: ?array, log: list<array{int, string}>}
 */
function runWrapper(
    string $data = MAIL,
    array $args = ['-t', '-i'],
    array $config = [],
    array $env = [],
    int $throttleExit = 0,
    int $sendmailExit = 0,
): array {
    $dir = tempDir();
    $log = [];
    $wrapper = new SendmailWrapper(
        testConfig(array_replace_recursive([
            'wrapper' => [
                'sendmailCmd' => fakeCommand($dir, 'sendmail', $sendmailExit),
                'throttleCmd' => fakeCommand($dir, 'throttle', $throttleExit),
                'defaultHost' => 'example.com',
                'xHeaderPrefix' => 'X-Test-',
            ],
        ], $config)),
        new Message($data),
        $args,
        $env,
        function (int $priority, string $message) use (&$log) {
            $log[] = [$priority, $message];
        },
    );

    return [
        'status' => $wrapper->run(),
        'sendmail' => fakeCommandCall($dir, 'sendmail'),
        'throttle' => fakeCommandCall($dir, 'throttle'),
        'log' => $log,
    ];
}

it('passes the message to sendmail with the envelope sender from the From header', function () {
    $result = runWrapper();

    expect($result['status'])->toBe(0)
        ->and($result['sendmail']['args'])->toBe(['-r', 'sender@example.com', '-t', '-i'])
        ->and($result['sendmail']['stdin'])->toMatch(
            '/^From: sender@example.com\nTo: a@example.com, b@example.com\nCc: c@example.com\nSubject: Test\n'
            . 'X-Test-MsgID: <\d{14}\.[0-9a-f]{12}@example\.com>\nX-Test-EnvSender: From\n\nBody\n$/'
        );
});

it('prefers the Return-Path header as envelope sender', function () {
    $result = runWrapper("Return-Path: bounce@example.com\n" . MAIL);

    expect($result['sendmail']['args'])->toBe(['-r', 'bounce@example.com', '-t', '-i'])
        ->and($result['sendmail']['stdin'])->toContain("X-Test-EnvSender: Return-Path\n");
});

it('does not add an envelope sender if none is given as bare address', function () {
    $result = runWrapper("From: Sender <sender@example.com>\nTo: a@example.com\n\nBody");

    expect($result['sendmail']['args'])->toBe(['-t', '-i'])
        ->and($result['sendmail']['stdin'])->not->toContain('X-Test-EnvSender');
});

it('does not add an envelope sender if passed as argument', function (array $args) {
    expect(runWrapper(args: $args)['sendmail']['args'])->toBe($args);
})->with([
    [['-t', '-i', '-f', 'other@example.com']],
    [['-r', 'other@example.com', '-t']],
]);

it('splits the configured sendmail command into arguments', function () {
    $dir = tempDir();
    runWrapper(config: ['wrapper' => ['sendmailCmd' => fakeCommand($dir, 'sendmail') . '  -oi   -odb ']]);

    expect(fakeCommandCall($dir, 'sendmail')['args'])->toBe(['-oi', '-odb', '-r', 'sender@example.com', '-t', '-i']);
});

it('never passes the envelope sender or arguments through a shell', function (string $from, array $args) {
    $cwd = getcwd();
    chdir($dir = tempDir());
    try {
        $result = runWrapper("From: $from\nTo: a@example.com\n\nBody", $args);
    } finally {
        chdir($cwd);
    }

    expect(filter_var($from, FILTER_VALIDATE_EMAIL))->toBe($from)
        ->and($result['sendmail']['args'])->toBe(['-r', $from, ...$args])
        ->and(glob("$dir/pwned*"))->toBe([]);
})->with([
    'backticks' => ['`touch${IFS}pwned1`@example.com', ['-t']],
    'pipe' => ['a|touch${IFS}pwned2@example.com', ['-t']],
    'ampersand' => ['a&touch${IFS}pwned3&@example.com', ['-t']],
    'arguments' => ['sender@example.com', ['-t', '-oi;touch pwned4', '$(touch pwned5)', '`touch pwned6`']],
]);

it('passes the message with meta information and the recipient count to the throttle command', function () {
    $result = runWrapper(
        "From: sender@example.com\nTo: a@example.com,\n b@example.com\nBcc: c@example.com\nX-Meta-Site: spoofed\n\nBody",
        env: ['HTTP_HOST' => 'www.example.com', 'REMOTE_ADDR' => '2001:db8::1', 'SCRIPT_FILENAME' => '/var/www/index.php'],
    );
    $throttled = new Message($result['throttle']['stdin']);

    expect($result['throttle']['args'])->toBe(['3'])
        ->and($throttled->header('x-meta-site'))->toBe('www.example.com')
        ->and($throttled->header('x-meta-client'))->toBe('2001:db8::1')
        ->and($throttled->header('x-meta-script'))->toBe('/var/www/index.php')
        ->and($throttled->header('x-meta-msgid'))->toMatch('/^\d{14}\.[0-9a-f]{12}@example\.com$/')
        ->and($throttled->header('x-test-msgid'))->toBe('<' . $throttled->header('x-meta-msgid') . '>')
        // meta information is only passed to the throttle command
        ->and($result['sendmail']['stdin'])->not->toContain('X-Meta-Client');
});

it('does not send the message if the throttle command reports a limit', function (int $status) {
    $result = runWrapper(throttleExit: $status);

    expect($result['status'])->toBe($status)
        ->and($result['sendmail'])->toBeNull();
})->with([1, 2, 3, 4]);

it('sends the message on throttle exceptions if configured to ignore them', function () {
    $result = runWrapper(config: ['throttle' => ['ignoreExceptions' => true]], throttleExit: 4);

    expect($result['status'])->toBe(0)
        ->and($result['sendmail'])->not->toBeNull()
        ->and($result['log'][0][1])->toEndWith('throttleStatus=4');
});

it('does not run the throttle command if throttling is disabled', function () {
    $result = runWrapper(config: ['wrapper' => ['throttleOn' => false]], throttleExit: 1);

    expect($result['status'])->toBe(0)
        ->and($result['throttle'])->toBeNull()
        ->and($result['sendmail'])->not->toBeNull();
});

it('logs the message to syslog', function () {
    $result = runWrapper(
        "From: sender@example.com\nTo: " . implode(', ', array_map(fn ($i) => "recipient$i@example.com", range(1, 10)))
        . "\nCc: c@example.com\nSubject: Hello World\n\nBody",
        env: ['HTTP_HOST' => 'www.example.com', 'REMOTE_ADDR' => '192.0.2.1', 'SCRIPT_FILENAME' => '/var/www/index.php'],
    );

    expect($result['log'])->toHaveCount(1)
        ->and($result['log'][0][0])->toBe(LOG_INFO)
        ->and($result['log'][0][1])->toMatch(sprintf(
            '/^sendmail-wrapper-php: uid=%s, msgid=\d{14}\.[0-9a-f]{12}@example\.com, from=sender@example\.com, '
            . 'to="recipient1@example\.com, .{93}\.\.\.", cc="c@example\.com", bcc="", subject="Hello World", '
            . 'site=www\.example\.com, client=192\.0\.2\.1, script=\/var\/www\/index\.php, throttleStatus=0$/',
            preg_quote(posix_getpwuid(posix_geteuid())['name'], '/'),
        ))
        ->and(mb_strlen(preg_replace('/.*to="([^"]*)".*/', '$1', $result['log'][0][1])))->toBe(120);
});

it('does not log blocked users to syslog', function () {
    expect(runWrapper(throttleExit: 3)['log'])->toBe([]);
});

it('logs malformed header lines to syslog', function () {
    $result = runWrapper("From: sender@example.com\nmalformed\nTo: a@example.com\n\nBody");

    expect($result['log'][0])->toBe([LOG_WARNING, 'sendmail-wrapper-php: Could not parse mail header line: malformed']);
});
