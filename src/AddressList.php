<?php

declare(strict_types=1);

namespace Onlime\SendmailWrapper;

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * RFC 5322 address list (To, Cc, Bcc header values), replacing ext-imap's imap_rfc822_parse_adrlist()
 * (ext-imap has been unbundled from PHP 8.4).
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
final class AddressList
{
    /**
     * Count the addresses in an address list.
     *
     * As this is used for throttling, it errs on the side of counting too many rather than too few:
     * Malformed lists like "a@example.com b@example.com" (missing comma) count each address.
     */
    public static function count(string $list): int
    {
        // neutralize quoted strings, which may contain commas, colons, semicolons, "@" and parentheses
        $list = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '""', $list);

        // remove comments, which may be nested
        do {
            $list = preg_replace('/\((?:[^()\\\\]|\\\\.)*\)/s', ' ', $list, -1, $count);
        } while ($count);

        // neutralize angle-addr, which may contain commas and colons (obsolete route syntax)
        $list = preg_replace('/<[^<>]*>/', '<>', $list);

        // groups ("name: a@example.com, b@example.com;"): drop the display name, the semicolon ends the group
        $list = preg_replace('/[^,:;]*:/', ',', $list);

        $total = 0;
        foreach (preg_split('/[,;]/', $list) as $address) {
            if (trim($address) !== '') {
                $total += max(1, preg_match_all('/<>|\S@\S/', $address));
            }
        }

        return $total;
    }
}
