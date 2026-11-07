<?php

declare(strict_types=1);

use Onlime\SendmailWrapper\AddressList;

it('counts the addresses in an address list', function (string $list, int $count) {
    expect(AddressList::count($list))->toBe($count);
})->with([
    'empty' => ['', 0],
    'whitespace and empty elements' => [' , ,, ', 0],
    'single address' => ['a@example.com', 1],
    'multiple addresses' => ['a@example.com, b@example.com,c@example.com', 3],
    'empty elements' => ['a@example.com,, b@example.com,', 2],
    'name-addr' => ['Doe <john@example.com>, Jane Doe <jane@example.com>', 2],
    'quoted display name with comma' => ['"Doe, John" <john@example.com>', 1],
    'quoted display name with special chars' => ['"Doe: \"John\"; <x@y>, (z)" <john@example.com>, b@example.com', 2],
    'quoted local part' => ['"john,doe"@example.com', 1],
    'comment with comma' => ['john@example.com (Doe, John), jane@example.com', 2],
    'nested comments' => ['john@example.com (a (b, c) d), (x) jane@example.com (y)', 2],
    'encoded display name' => ['=?UTF-8?Q?M=C3=BCller=2C_Hans?= <hans@example.com>', 1],
    'group' => ['Team: a@example.com, b@example.com;, c@example.com', 3],
    'empty group' => ['undisclosed-recipients:;', 0],
    'obsolete route' => ['<@route1,@route2:john@example.com>', 1],
    'local part only' => ['john', 1],
    'missing comma' => ['a@example.com b@example.com c@example.com', 3],
    'missing comma after name-addr' => ['Doe <john@example.com> jane@example.com', 2],
    'unclosed angle bracket' => ['Doe <john@example.com, jane@example.com', 2],
]);

it('counts like imap_rfc822_parse_adrlist() for valid address lists', function (string $list) {
    expect(AddressList::count($list))->toBe(count(imap_rfc822_parse_adrlist($list, 'example.com')));
})->with([
    'a@example.com',
    'a@example.com, b@example.com, c@example.com',
    'Doe <john@example.com>, "Doe, Jane" <jane@example.com>',
    'john@example.com (Doe, John), jane@example.com',
    '"john,doe"@example.com, x@example.com',
    'john',
])->skip(! function_exists('imap_rfc822_parse_adrlist'), 'ext-imap not available');
