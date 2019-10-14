# sendmail-wrapper

A powerful sendmail wrapper to log and throttle emails sent by PHP

## Advantages

- Lets you monitor any mail traffic from PHP scripts
- Allows throttling (limiting) emails sent by PHP's mail() function
- Throttle by sent email and/or recipient count per day
- Logs both to syslog and database with message metadata
- Logs common mail headers like From, To, Cc, Bcc, Subject
- Fixes Return-Path header on the fly for users who did not correctly set it
- Highly secured setup, customers cannot access the logging/throttling database
- Standalone PHP application without any external library dependencies
- Built for shared webhosting environment where PHP runs as cgi/FastCGI/suPHP
- No cronjobs required, sendmail-wrapper will reset counters automatically every day

## Requirements

- PHP 7.2+
- sendmail compatible MTA: Exim, Postfix,...
- sudo >= 1.7.5, 1.8+ recommended

## Installation

### Initial Setup

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

### Quick Install

The installer script **install.sh** will correctly setup permissions and symlink the wrapper scripts:

```bash
$ cd /opt/sendmail-wrapper/
$ ./install.sh
```

If you wish to run this manually, check the following instructions...

### Manual Install

Set correct permissions:

```bash
$ chown sendmailwrapper:sendmailwrapper *.php *.ini
$ chmod 400 config.private.ini
$ chmod 444 config.ini config.local.ini
$ chmod 555 sendmail-wrapper.php prepend.php
$ chmod 500 sendmail-throttle.php
$ chmod 400 schema/*.sql
```

Create symlinks:

```bash
$ ln -sf /opt/sendmail-wrapper/sendmail-wrapper.php /usr/sbin/sendmail-wrapper
$ ln -sf /opt/sendmail-wrapper/sendmail-throttle.php /usr/sbin/sendmail-throttle
$ /bin/cp -a prepend.php /var/www/shared/
```

### Setup sudo

Add the following lines to your /etc/sudoers:

```
www-data        ALL = (sendmailwrapper) NOPASSWD:/usr/sbin/sendmail-throttle [0-9]*
%customers      ALL = (sendmailwrapper) NOPASSWD:/usr/sbin/sendmail-throttle [0-9]*
```

### Setup PHP

Add/modify the following in your php.ini:

```ini
sendmail_path = /usr/sbin/sendmail-wrapper
auto_prepend_file = /var/www/shared/prepend.php
```

### Setup MySQL

Import the sendmailwrapper database schema:

```bash
$ mysql -u root -p < schema/schema.mysql.sql
```

Create a MySQL user with the following permissions:

```sql
GRANT USAGE ON *.* TO sendmailwrapper@'localhost' IDENTIFIED BY '********';
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

[wrapper]
sendmailCmd = "/usr/sbin/sendmail -t -i"
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

[db]
dsn = "mysql:host=localhost;dbname=sendmailwrapper"
user = sendmailwrapper
pass = "xxxxxxxxxxxxxxxxxxxxx"
```

You should not change any of the above values. Create your own **config.local.ini** instead to overwrite some values, e.g.:

```ini
[global]
adminTo = hostmaster@mydomain.com
adminFrom = hostmaster@mydomain.com

[wrapper]
defaultHost = "mydomain.com"
xHeaderPrefix = "X-MyCompany-"
```

Never put your database password in any of the above configuration files. Use another configuration file called **config.private.ini** instead, e.g.:

```ini
[db]
pass = "mySuper-SecurePassword/826.4287+foo"
```
