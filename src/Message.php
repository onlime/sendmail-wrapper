<?php

declare(strict_types=1);

namespace Onlime\SendmailWrapper;

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * A mail message (as passed to sendmail on STDIN), split into its header fields and body.
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
final class Message
{
    /**
     * Headers that can appear more than once, according to RFC 5322
     * https://datatracker.ietf.org/doc/html/rfc5322#section-3.6
     */
    private const MULTI_HEADERS = [
        'trace',
        'resent-date',
        'resent-from',
        'resent-sender',
        'resent-to',
        'resent-cc',
        'resent-bcc',
        'resent-msg-id',
        'comments',
        'keywords',
        'optional-field',
    ];

    /**
     * Raw header fields in original order, folded continuation lines kept with their field.
     *
     * @var list<string>
     */
    private array $fields;

    private string $body;

    public function __construct(string $data)
    {
        // normalize line breaks (get rid of Windows newlines)
        $data = str_replace("\r\n", "\n", $data);

        [$header, $this->body] = explode("\n\n", $data, 2) + [1 => ''];

        // split into header fields, a line starting with whitespace continues the previous field (folding)
        $this->fields = array_values(array_filter(
            preg_split('/\n(?![ \t])/', $header),
            fn (string $field) => trim($field) !== '',
        ));
    }

    public static function fromStdin(): self
    {
        return new self((string) stream_get_contents(STDIN));
    }

    /**
     * Parsed headers with lowercase names and unfolded values.
     *
     * Headers that should not appear more than once are merged, comma separated. This prevents
     * spammers from tampering with our recipient counting by e.g. using multiple To headers.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [];
        foreach ($this->fields as $field) {
            if (! str_contains($field, ':')) {
                continue;
            }
            [$name, $value] = explode(':', self::unfold($field), 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            if (isset($headers[$name]) && ! in_array($name, self::MULTI_HEADERS, true)) {
                $headers[$name] .= ', ' . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers()[strtolower($name)] ?? null;
    }

    /**
     * Header lines that could not be parsed (not containing a colon).
     *
     * @return list<string>
     */
    public function malformedHeaders(): array
    {
        return array_values(array_map(
            self::unfold(...),
            array_filter($this->fields, fn (string $field) => ! str_contains($field, ':')),
        ));
    }

    /**
     * Add a header to the message (appended after the original headers).
     */
    public function addHeader(string $name, string $value): void
    {
        $this->fields[] = self::field($name, $value);
    }

    /**
     * Re-assemble the message from its header and body parts.
     *
     * @param  array<string, ?string>  $extraHeaders  headers only added for this call, replacing any
     *                                                existing headers of the same name (case-insensitive)
     */
    public function toString(array $extraHeaders = []): string
    {
        $fields = $this->fields;
        if ($extraHeaders) {
            $names = array_map(strtolower(...), array_keys($extraHeaders));
            $fields = array_filter($fields, fn (string $field) => ! in_array(self::name($field), $names, true));
            foreach ($extraHeaders as $name => $value) {
                $fields[] = self::field($name, (string) $value);
            }
        }

        return implode("\n", $fields) . "\n\n" . $this->body;
    }

    private static function field(string $name, string $value): string
    {
        // never allow line breaks in header values, which would inject additional headers
        return $name . ': ' . str_replace(["\r", "\n"], ' ', $value);
    }

    private static function name(string $field): string
    {
        return strtolower(trim(strstr($field, ':', true) ?: ''));
    }

    private static function unfold(string $field): string
    {
        return preg_replace('/\n[ \t]+/', ' ', $field);
    }
}
