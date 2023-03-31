<?php

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
    $value = str_replace($sanitizeChars, '', @$_SERVER[$key]);
    putenv("$key=$value");
}
