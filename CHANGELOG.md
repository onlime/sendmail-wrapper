# CHANGELOG

## 1.0.3 (2019-10-14)

- Fix for PHP setup where extensions are not compiled-in, loaded as modules. Standard php.ini is now loaded.
- Code style: replaced traditional syntax array literals
- Code smells: fixes various minor code smells
- Pushed minimum required PHP version to 7.2
- Improved array to object conversion in ConfigLoader by using json_encode/decode trick.

## 1.0.2 (2015-11-04)

- workaround for duplicate headers that should not appear more than once (@onlime)

## 1.0.1 (2014-03-26)

- strip to/cc/bcc content to 120 characters in syslog, configurable (@onlime)
- added ext-mbstring, ext-pdo requirements to composer.json (@onlime)

## 1.0.0 (2014-03-24)

- initial release
