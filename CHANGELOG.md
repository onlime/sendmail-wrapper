# CHANGELOG

## 1.0.5 (unreleased)

- Fix for newline after `whoami` shell output, which broke message logging to syslog.

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
