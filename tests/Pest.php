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
