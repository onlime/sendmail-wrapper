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
ln -sf /opt/sendmail-wrapper/sendmail-wrapper.php /usr/sbin/sendmail-wrapper
ln -sf /opt/sendmail-wrapper/sendmail-throttle.php /usr/sbin/sendmail-throttle
ln -sf /opt/sendmail-wrapper/php_set_envs.php /usr/sbin/php-set-environment
```

## Configure sudo

Add the following lines to your /etc/sudoers:

```
www-data        ALL = (sendmailwrapper) NOPASSWD:/usr/sbin/sendmail-throttle
%customers      ALL = (sendmailwrapper) NOPASSWD:/usr/sbin/sendmail-throttle
```

## Configure PHP

Add/modify the following in your php.ini:

```ini
sendmail_path = /usr/sbin/sendmail-wrapper
auto_prepend_file = /usr/sbin/php-set-environment
```
