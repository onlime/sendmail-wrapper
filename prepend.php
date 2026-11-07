<?php

/**
 * PHP auto_prepend_file, passing some request information as environment variables
 * to child processes (e.g. sendmail-wrapper invoked by mail()).
 *
 * IMPORTANT: This file is prepended to every PHP request and PHP CLI run, under every PHP version
 * installed on the server. Keep it compatible with the oldest PHP version in use (PHP 7.4)!
 * Wrapped in a closure to not leak any variables into the global scope of the executed script.
 */
(function () {
    // environment variables that should be available in child processes
    $envVars = [
        'HTTP_HOST',
        'SCRIPT_NAME',
        'SCRIPT_FILENAME',
        'DOCUMENT_ROOT',
        'REMOTE_ADDR',
    ];

    // sanitizing environment variables for Bash ShellShock mitigation
    // (CVE-2014-6271, CVE-2014-7169, CVE-2014-7186, CVE-2014-7187, CVE-2014-6277)
    $sanitizeChars = str_split('(){};');
    foreach ($envVars as $key) {
        if (! isset($_SERVER[$key])) {
            // e.g. in PHP CLI: don't export empty variables
            continue;
        }
        $value = str_replace($sanitizeChars, '', $_SERVER[$key]);
        putenv("$key=$value");
    }
})();
