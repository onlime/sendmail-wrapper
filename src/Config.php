<?php

declare(strict_types=1);

namespace Onlime\SendmailWrapper;

use RuntimeException;

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * Configuration loaded from config.ini, overridden by the optional config.local.ini and config.private.ini.
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
final readonly class Config
{
    public function __construct(
        private array $values = [],
    ) {}

    /**
     * Load config.ini from $dir, merged with config.local.ini and config.private.ini, if readable.
     * (config.private.ini is only readable by the sendmailwrapper user, not by the wrapper running as customer)
     */
    public static function load(string $dir): self
    {
        $values = self::parse("$dir/config.ini")
            ?? throw new RuntimeException("Could not read configuration file $dir/config.ini");

        foreach (["$dir/config.local.ini", "$dir/config.private.ini"] as $file) {
            if (is_readable($file)) {
                $values = array_replace_recursive($values, self::parse($file) ?? []);
            }
        }

        return new self($values);
    }

    /**
     * Get a configuration value by "section.key" notation.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->get($key, $default);
    }

    private static function parse(string $file): ?array
    {
        return is_readable($file) ? (parse_ini_file($file, true, INI_SCANNER_TYPED) ?: null) : null;
    }
}
