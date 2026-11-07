<?php

declare(strict_types=1);

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * Bootstrap: registers a standalone PSR-4 autoloader (no Composer vendor/ directory is required
 * in production) and returns the loaded configuration.
 *
 * Only ever load files by absolute path! Relative paths would be resolved through include_path
 * (and therefore the caller's working directory) first.
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */

use Onlime\SendmailWrapper\Config;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Onlime\\SendmailWrapper\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$config = Config::load(__DIR__);

// assure the default timezone is set
if (! ini_get('date.timezone')) {
    date_default_timezone_set($config->string('global.defaultTZ', 'UTC'));
}

return $config;
