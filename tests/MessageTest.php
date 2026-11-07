<?php

declare(strict_types=1);

use Onlime\SendmailWrapper\Message;

it('parses headers with lowercase names and normalizes CRLF line breaks', function () {
    $message = new Message("From: a@example.com\r\nTo: b@example.com\r\nSubject: Hello: World\r\n\r\nBody\r\n");

    expect($message->headers())->toBe([
        'from' => 'a@example.com',
        'to' => 'b@example.com',
        'subject' => 'Hello: World',
    ])
        ->and($message->header('Subject'))->toBe('Hello: World')
        ->and($message->header('Cc'))->toBeNull()
        ->and($message->toString())->toBe("From: a@example.com\nTo: b@example.com\nSubject: Hello: World\n\nBody\n");
});

it('unfolds folded header lines', function () {
    $message = new Message("To: a@example.com,\n b@example.com,\n\t\"Doe: John\" <c@example.com>\nSubject: Long\n subject\n\nBody");

    expect($message->headers())->toBe([
        'to' => 'a@example.com, b@example.com, "Doe: John" <c@example.com>',
        'subject' => 'Long subject',
    ])->and($message->malformedHeaders())->toBe([]);
});

it('does not parse continuation lines as separate headers', function () {
    $message = new Message("To: a@example.com,\n X-Meta-Site: spoofed\n\nBody");

    expect($message->headers())->toBe(['to' => 'a@example.com, X-Meta-Site: spoofed']);
});

it('merges duplicate headers that must not appear more than once', function () {
    $message = new Message("To: a@example.com\nTo: b@example.com\nComments: one\nComments: two\n\nBody");

    expect($message->header('to'))->toBe('a@example.com, b@example.com')
        ->and($message->header('comments'))->toBe('two');
});

it('reports malformed header lines', function () {
    $message = new Message("From: a@example.com\nno colon here\n\nBody");

    expect($message->headers())->toBe(['from' => 'a@example.com'])
        ->and($message->malformedHeaders())->toBe(['no colon here']);
});

it('handles messages without body', function (string $data) {
    $message = new Message($data);

    expect($message->headers())->toBe(['from' => 'a@example.com', 'to' => 'b@example.com'])
        ->and($message->toString())->toBe("From: a@example.com\nTo: b@example.com\n\n");
})->with([
    'no separator' => "From: a@example.com\nTo: b@example.com",
    'trailing newline' => "From: a@example.com\nTo: b@example.com\n",
    'empty body' => "From: a@example.com\nTo: b@example.com\n\n",
]);

it('handles empty messages', function () {
    expect((new Message(''))->headers())->toBe([]);
});

it('keeps original header folding and body when re-assembling', function () {
    $data = "To: a@example.com,\n b@example.com\nSubject: Test\n\nLine 1\n\nLine 2\n";
    $message = new Message($data);
    $message->addHeader('X-Test', 'value');

    expect($message->toString())->toBe("To: a@example.com,\n b@example.com\nSubject: Test\nX-Test: value\n\nLine 1\n\nLine 2\n")
        ->and($message->header('x-test'))->toBe('value');
});

it('replaces existing headers by extra headers, case-insensitive', function () {
    $message = new Message("From: a@example.com\nx-meta-site: spoofed.example.com\nX-META-Client: 1.2.3.4\n\nBody");

    $data = $message->toString(['X-Meta-Site' => 'real.example.com', 'X-Meta-Client' => null]);

    expect($data)->toBe("From: a@example.com\nX-Meta-Site: real.example.com\nX-Meta-Client: \n\nBody")
        ->and((new Message($data))->header('x-meta-site'))->toBe('real.example.com')
        // extra headers are only added for this call
        ->and($message->header('x-meta-site'))->toBe('spoofed.example.com');
});

it('does not allow line breaks in added header values', function () {
    $message = new Message("From: a@example.com\n\nBody");
    $message->addHeader('X-Test', "foo\r\nBcc: injected@example.com");

    expect($message->toString(['X-Extra' => "bar\nTo: injected@example.com"]))
        ->toBe("From: a@example.com\nX-Test: foo  Bcc: injected@example.com\nX-Extra: bar To: injected@example.com\n\nBody");
});
