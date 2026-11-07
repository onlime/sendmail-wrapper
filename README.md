# sendmail-wrapper

A powerful sendmail wrapper to log and throttle emails sent by PHP

## Advantages

- Lets you monitor any mail traffic from PHP scripts
- Allows throttling (limiting) emails sent by PHP's `mail()` function
- Throttle by sent email and/or recipient count per day
- Logs both to syslog and database with message metadata
- Logs common mail headers like `From`, `To`, `Cc`, `Bcc`, `Subject`
- Fixes `Return-Path` header on the fly for users who did not correctly set it
- Highly secured setup, customers cannot access the logging/throttling database
- Standalone PHP application without any external library dependencies (no Composer `vendor/` required in production)
- Built for shared webhosting environments where PHP runs in PHP-FPM (FastCGI Process Manager) mode
- No cronjobs required, sendmail-wrapper will reset counters automatically every day

## Requirements

- PHP 8.2+ (CLI, `/usr/bin/php`) with extensions `mbstring`, `pdo_mysql` and `posix`
- sendmail compatible MTA: Exim, Postfix,...
- sudo 1.9.10+ (or 1.8+, see [Setup sudo](#setup-sudo))
- MySQL 5.7+ or MariaDB

> Currently, sendmail-wrapper is tested and actively used by [Onlime GmbH](https://www.onlime.ch/) on shared webhosting environments with PHP 8.2, 8.3, 8.4 and 8.5.
>
> `prepend.php` is prepended to every PHP request of every PHP version installed on your server and therefore still supports PHP 7.4+.

## Installation

### Initial Setup

Clone repository from GitHub:

```bash
$ git clone https://github.com/onlime/sendmail-wrapper.git /opt/sendmail-wrapper
```

Setup system user for sendmail-wrapper:

```bash
$ adduser --system --home /no/home --no-create-home --uid 6000 --group --disabled-password --disabled-login sendmailwrapper
$ adduser sendmailwrapper customers
```

### Quick Install

The installer script **install.sh** will correctly set up permissions and symlink the wrapper scripts:

```bash
$ cd /opt/sendmail-wrapper/
$ ./install.sh
```

If you wish to run this manually, check the following instructions...

### Manual Install

Set correct permissions:

```bash
$ chown root:root *.php *.ini
$ chown root:sendmailwrapper sendmail-throttle.php config.private.ini
$ chmod 440 config.private.ini
$ chmod 444 config.ini config.local.ini
$ chmod 555 sendmail-wrapper.php prepend.php
$ chmod 550 sendmail-throttle.php
$ chmod 400 schema/*.sql
```

> **IMPORTANT:**
> Never make the `sendmailwrapper` user the owner of any file. A file owner can always change its permissions and modify it, and `sendmail-wrapper.php` and `prepend.php` are executed by all your customers.

Create symlinks:

```bash
$ ln -sf /opt/sendmail-wrapper/sendmail-wrapper.php /usr/sbin/sendmail-wrapper
$ ln -sf /opt/sendmail-wrapper/sendmail-throttle.php /usr/sbin/sendmail-throttle
$ install -o root -g root -m 444 prepend.php /var/www/shared/prepend.php
```

### Setup sudo

Add the following lines to your `/etc/sudoers` (or a file in `/etc/sudoers.d/`), restricting the only argument to the recipient count:

```
www-data        ALL = (sendmailwrapper) NOPASSWD:/usr/sbin/sendmail-throttle ^[0-9]+$
%customers      ALL = (sendmailwrapper) NOPASSWD:/usr/sbin/sendmail-throttle ^[0-9]+$
```

Regular expressions in sudoers require sudo 1.9.10+ (Debian 12+). On older versions, use the wildcard `[0-9]*` instead (which also matches additional arguments, which are rejected by `sendmail-throttle`).

> **NOTE:**
> Don't use a regular expression like `^[0-9]{1,6}$`: commas separate commands in sudoers, so the rule would silently be split.

### Setup PHP

Add/modify the following in your php.ini:

```ini
sendmail_path = /usr/sbin/sendmail-wrapper -t -i
auto_prepend_file = /var/www/shared/prepend.php
```

> **NOTE:**
> It is recommended to put the default `-t -i` options in the `sendmail_path` directive of your php.ini instead of appending them directly to the `sendmailCmd` config option in your `config.local.ini`.
>
> This way, it won't break any projects that use [Symfony Mailer](https://github.com/symfony/mailer/) component which actually checks for `-bs` or `-t` in `sendmail_path`. (see [SendmailTransport.php](https://github.com/symfony/mailer/blob/6.2/Transport/SendmailTransport.php#L57-L59))

### Setup MySQL

Import the sendmailwrapper database schema:

```bash
$ mysql -u root -p < schema/schema.mysql.sql
```

Create a MySQL user with the following permissions:

```sql
CREATE USER sendmailwrapper@'localhost' IDENTIFIED BY '********';
GRANT SELECT, INSERT, UPDATE ON sendmailwrapper.throttle TO sendmailwrapper@'localhost';
GRANT INSERT ON sendmailwrapper.messages TO sendmailwrapper@'localhost';
```

## Configuration

Default configuration can be found in **config.ini**:

```ini
[global]
defaultTZ = Europe/Zurich
adminTo = hostmaster@example.com
adminFrom = hostmaster@example.com

[syslog]
stringLengthLimit = 120
stringCutSuffix = "..."

[wrapper]
sendmailCmd = "/usr/sbin/sendmail"
throttleCmd ="sudo -u sendmailwrapper /usr/sbin/sendmail-throttle"
throttleOn = true
defaultHost = "example.com"
syslogPrefix = sendmail-wrapper-php
xHeaderPrefix = "X-Example-"

[throttle]
countMax = 1000
rcptMax = 1000
syslogPrefix = sendmail-throttle-php
adminSubject = "Sendmail limit exceeded"
ignoreExceptions = false

[db]
dsn = "mysql:host=localhost;dbname=sendmailwrapper;charset=utf8mb4"
user = sendmailwrapper
pass = "xxxxxxxxxxxxxxxxxxxxx"
```

`throttle.ignoreExceptions = true` lets messages pass if throttling fails, e.g. if the database server is not available. Otherwise, such messages are rejected.

You should not change any of the above values. Create your own `config.local.ini` instead to overwrite some values, e.g.:

```ini
[global]
adminTo = hostmaster@mydomain.com
adminFrom = hostmaster@mydomain.com

[wrapper]
defaultHost = "mydomain.com"
xHeaderPrefix = "X-MyCompany-"
```

Never put your database password in any of the above configuration files. Use another configuration file called `config.private.ini` instead, e.g.:

```ini
[db]
pass = "mySuper-SecurePassword/826.4287+foo"
```

## Development

Install the development dependencies (only required for development, never in production) and run the [Pest](https://pestphp.com/) test suite and [Pint](https://laravel.com/docs/pint) code style fixer:

```bash
$ composer install
$ composer test
$ composer lint
```

The tests use fake sendmail/throttle commands and an in-memory SQLite database, so no MTA, sudo or MySQL server is required.

## Upgrading

### Upgrade from 1.1.x to 2.0.x

- PHP 8.2+ is required for the PHP CLI (`/usr/bin/php`) that runs sendmail-wrapper. `ext-imap` is no longer required, but `ext-posix` is.
- Re-run `install.sh` (or update your permissions manually, see [Manual Install](#manual-install)): all files must be owned by root.
- Convert your database to utf8mb4 (during low traffic, as converting the `messages` table blocks writes while running), and add `charset=utf8mb4` to your DSN if you have overridden `db.dsn` in your `config.local.ini`:
  ```bash
  $ mysql -u root -p < schema/migrations/20261107-utf8mb4.sql
  ```
- Optionally, restrict the sudoers rules with a regular expression, see [Setup sudo](#setup-sudo).
- `messages.sender_host` now contains the hostname of the server running sendmail-wrapper (`gethostname()`), instead of the client host seen by the MySQL server (`USER()`).
- sendmail-wrapper now returns the exit status of sendmail, so `mail()` returns `false` if the MTA failed to accept a message.

### Upgrade from 1.0.x to 1.1.x

To avoid problems with projects that use [Symfony Mailer](https://github.com/symfony/mailer/) (Laravel's [Mail](https://laravel.com/docs/10.x/mail) component also uses Symfony Mailer under the hood!), we have moved the default sendmail command-line options `-t -i` from Sendmail-wrapper's configuration `config.ini` to the recommended `sendmail_path` directive in `php.ini`.
If you stick with our default configuration, you need to update your `php.ini`:

```diff
- sendmail_path = /usr/sbin/sendmail-wrapper
+ sendmail_path = /usr/sbin/sendmail-wrapper -t -i
```

If you don't care about Symfony Mailer or any other mailer components that check for `-t` existence in `sendmail_path`, you can keep the old `php.ini` configuration and add this to your `config.local.ini`:

```ini
sendmailCmd = "/usr/sbin/sendmail -t -i"
```
