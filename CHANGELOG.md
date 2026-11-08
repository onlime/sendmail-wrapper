# CHANGELOG

## 1.1.1 (2026-11-08)

Security release, all users should upgrade immediately.

- Security: Fixed local privilege escalation to the `sendmailwrapper` user. Relative `require_once` paths were resolved through `include_path` (whose leading `.` is the caller's working directory) when running `sendmail-throttle` via sudo. All includes now use absolute `__DIR__` based paths.
- Security: Fixed shell command injection through a `From`/`Return-Path` header address (`FILTER_VALIDATE_EMAIL` accepts shell metacharacters) and through the original sendmail arguments, which were passed through a shell a second time. Sendmail and the throttle command are now executed via `proc_open()` with an argument array, without any shell.
- Security: `install.sh` no longer makes `sendmailwrapper` the owner of any file. All files are owned by root, only `sendmail-throttle.php` and `config.private.ini` grant group read access to `sendmailwrapper`. `prepend.php` is installed as `root:root 0444`. Re-run `install.sh` (or update your permissions manually, see README) after upgrading.
- Fixed `prepend.php` PHP 8.1+ deprecation notices on unset `$_SERVER` keys (e.g. in PHP CLI), no longer exporting empty environment variables or leaking variables into the global scope.
- Fixed PHP 8.1+ and 8.4 deprecations (implicitly nullable parameters, null passed to `trim()` and `mb_decode_mimeheader()`).

## 1.1.0 (2023-03-31)

- Breaking change: Moved sendmail command-line options `-t -i` from Sendmail-wrapper config (`sendmailCmd` in `config.ini`) to the recommended `sendmail_path` configuration in `php.ini` to avoid problems with Symfony Mailer. see [Upgrading](README.md#upgrading) notes for details.
- Dropping support for PHP < 7.4
- Code style: Added type declarations to function arguments and return values.
- Added laravel/pint as dev dependency and applied PHP style fixes by Pint.

## 1.0.7 (2022-02-27)

- Added config option `trottle.ignoreExceptions` to ignore exceptions while throttling, allowing fail safety if e.g. db server goes away.
- Added a limit to `explode()` to prevent the truncation of IPv6 addresses in the x-meta-client header. #7 by @skysky6
- Pushed minimum required PHP version to 7.3
- Minor code cleanup

## 1.0.6 (2020-07-24)

- Avoid PHP Notices on non-standard mail header lines that don't contain a column, reporting warning to syslog.
- Add `sender_host` to `messages` table, reporting the connected sendmail-wrapper hostname, in case the MySQL database is located on a remote host and sendmail-wrapper is deployed to multiple hosts.

## 1.0.5 (2019-10-29)

- Fix for newline after `whoami` shell output, which broke message logging to syslog.
- Introduce new configuration flag `throttle.blocked` to completely block a user without changing his limits and without reporting to admin.

## 1.0.4 (2019-10-18)

- Ensure auto_prepend_file (prepend.php) is not loaded in sendmail-wrapper as it would override the passed env vars
- Fix PHP Notices 'Only variables should be passed by reference' in SendmailThrottle message logging

## 1.0.3 (2019-10-14)

- Fix for PHP setup where extensions are not compiled-in, loaded as modules. Standard php.ini is now loaded. fixes #1, #4
- Code style: replaced traditional syntax array literals
- Code smells: fixes various minor code smells
- Pushed minimum required PHP version to 7.2
- Improved array to object conversion in ConfigLoader by using json_encode/decode trick.
- Removed DEFAULT values for TEXT columns in MySQL sendmailwrapper.messages table, fixes #5

## 1.0.2 (2015-11-04)

- workaround for duplicate headers that should not appear more than once (@onlime)

## 1.0.1 (2014-03-26)

- strip to/cc/bcc content to 120 characters in syslog, configurable (@onlime)
- added ext-mbstring, ext-pdo requirements to composer.json (@onlime)

## 1.0.0 (2014-03-24)

- initial release
