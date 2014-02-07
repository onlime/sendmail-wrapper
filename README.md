sendmail-wrapper
================

A powerful sendmail wrapper to log and throttle emails send by PHP

# Installation

## Initial Setup

Clone repository from GitHub:

```bash
$ cd /opt/
$ git clone https://github.com/onlime/sendmail-wrapper.git sendmail-wrapper
```

Setup system user for sendmail-wrapper:

```bash
$ adduser --system --home /no/home --no-create-home --uid 6000 --group --disabled-password --disabled-login sendmailwrapper
$ adduser sendmailwrapper customers
```

Set correct permissions:

```bash
$ cd /opt/sendmail-wrapper/
$ chown sendmailwrapper:sendmailwrapper sendmail-*
$ chmod 755 sendmail-wrapper.php
$ chmod 511 sendmail-throttle.php
$ chmod 400 throttle.sql
$ chattr +i sendmail-*.php
```

Create symlinks:

```
$ ln -sf /opt/sendmail-wrapper/sendmail-wrapper.php /usr/sbin/sendmail-wrapper
$ ln -sf /opt/sendmail-wrapper/sendmail-throttle.php /usr/sbin/sendmail-throttle
$ ln -sf /opt/sendmail-wrapper/php_set_envs.php /usr/sbin/php-set-environment
```

## Setup sudo

Add the following lines to your /etc/sudoers:

```
www-data        ALL = (sendmailwrapper) NOPASSWD:/usr/sbin/sendmail-throttle
%customers      ALL = (sendmailwrapper) NOPASSWD:/usr/sbin/sendmail-throttle
```

## Setup PHP

Add/modify the following in your php.ini:

```ini
sendmail_path = /usr/sbin/sendmail-wrapper
auto_prepend_file = /usr/sbin/php-set-environment
```

## Setup MySQL

Import the sendmailwrapper database schema:

```bash
$ mysql -u root -p < throttle.sql
```

Create a MySQL user with the following permissions:

```sql
GRANT USAGE ON *.* TO sendmailwrapper@'localhost' IDENTIFIED BY '********';
GRANT SELECT, INSERT, UPDATE ON sendmailwrapper.throttle TO sendmailwrapper@'localhost';
```

# Configuration

Configure sendmail-wrapper.php:

```php
// throttle and sendmail commands
define('SENDMAIL_CMD'   , '/usr/sbin/sendmail -t -i');
define('THROTTLE_CMD'   , 'sudo -u sendmailwrapper /usr/sbin/sendmail-throttle');
// turn on recipient throttling
define('THROTTLE_ON'    , true);
// default host (infrastructure domain)
define('DEFAULT_HOST'   , 'example.com');
// syslog prefix
define('SYSLOG_PREFIX'  , 'sendmail-wrapper-php');
// extra header prefix
define('X_HEADER_PREFIX', 'X-Example-');
// default timezone
define('DEFAULT_TZ'     , 'Europe/Zurich');
```

Configure sendmail-throttle.php:

```php
// database configuration
define('DB_DSN',  'mysql:host=localhost;dbname=sendmailwrapper');
define('DB_USER', 'sendmailwrapper');
define('DB_PASS', 'xxxxxxxxxxxxxxx');

// throttle configuration
define('COUNT_MAX', 1000);
define('RCPT_MAX',  1000);

// syslog configuration
define('SYSLOG_PREFIX', 'sendmail-throttle-php');

// system administrator report
define('ADMIN_TO'     , 'info@example.com');
define('ADMIN_FROM'   , 'info@example.com');
define('ADMIN_SUBJECT', 'Sendmail limit exceeded');
```
