<?php

declare(strict_types=1);

/**
 * Sendmail Wrapper by Onlime GmbH webhosting services
 * https://github.com/onlime/sendmail-wrapper
 *
 * Standalone PSR-4 autoloader, so that no Composer vendor/ directory is required in production.
 * Only ever load files by absolute path! Relative paths would be resolved through include_path
 * (and therefore the caller's working directory) first.
 *
 * @copyright Copyright (c) Onlime GmbH (https://www.onlime.ch)
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Onlime\\SendmailWrapper\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
