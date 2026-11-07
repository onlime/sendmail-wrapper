# CHANGELOG

## 2.0.0 (unreleased)

Breaking changes, see [Upgrading](README.md#upgrade-from-11x-to-20x) notes for details.

- Dropped support for PHP < 8.2 (`prepend.php` still supports PHP 7.4+, as it is prepended to all PHP versions installed on a server).
- Replaced `ext-imap` (unbundled from PHP 8.4) by a built-in RFC 5322 address list parser for recipient counting. `ext-posix` is now required.
- Modernized code base: `Onlime\SendmailWrapper` namespace in `src/` with a standalone autoloader (no Composer `vendor/` required in production), strict types, final classes with constructor promotion and injected dependencies, `ThrottleStatus` enum, readonly `Config` and `Message` classes.
- Added [Pest](https://pestphp.com/) test suite and GitHub Actions workflow running tests on PHP 8.2 to 8.5.
- Security: Folded header lines are now unfolded before parsing. Previously, recipients on continuation lines of `To`/`Cc`/`Bcc` headers were not counted, which allowed bypassing the recipient limit.
- Security: Fixed race condition in throttle counters. Concurrently sent messages overwrote each other's counter updates (bypassing the limits) and could create duplicate throttle records. The throttle record is now locked (`SELECT ... FOR UPDATE`) and updated in a transaction, retried on deadlocks.
- Security: `X-Meta-*` headers passed to the throttle command can no longer be spoofed by the sender's own headers (log tampering).
- Security: `sendmail-throttle` validates its arguments (exactly one numeric recipient count) and requires being run via sudo.
- Throttling never fails because the message could not be logged to the database anymore (e.g. subject longer than 255 characters, or emoji in utf8mb3 tables), which previously blocked the message (or sent it without logging it, if `throttle.ignoreExceptions` was enabled). Values are truncated to their column sizes and logging errors are reported to syslog.
- Switched database schema to utf8mb4, with migration `schema/migrations/20261107-utf8mb4.sql`.
- Fixed `rcpt_cur` being reset to 1 instead of the recipient count on the first message of a new day.
- A new user's first message is now checked against the limits as well.
- Reaching the limit with the first message of a new day is now reported to the admin again.
- Return the exit status of sendmail, so that `mail()` returns `false` if the MTA failed to accept the message.
- Failures of the throttle command itself (e.g. command not found, PHP fatal error) are treated as exception, respecting `throttle.ignoreExceptions`.
- Detect an envelope sender passed as joined argument (e.g. `-fuser@example.com`), avoiding an additional `-r` argument.
- Fixed raw (unencoded) UTF-8 header values being logged as question marks to the `messages` table.
- Fixed TypeError on messages without body.
- Timestamps are supplied by PHP instead of MySQL `NOW()`, avoiding timezone mismatches. `messages.sender_host` now contains the hostname of the server running sendmail-wrapper (`gethostname()`).

## 1.1.1 (unreleased)

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
