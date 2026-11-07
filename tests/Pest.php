<?php

declare(strict_types=1);

use Onlime\SendmailWrapper\Config;

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
