#!/bin/bash

cd /opt/sendmail-wrapper/

chmod 700 .git
chown sendmailwrapper:sendmailwrapper sendmail-*
chmod 755 sendmail-wrapper.php
chmod 511 sendmail-throttle.php
chmod 400 throttle.sql
chattr +i sendmail-*.php

ln -sf /opt/sendmail-wrapper/sendmail-wrapper.php /usr/sbin/sendmail-wrapper
ln -sf /opt/sendmail-wrapper/sendmail-throttle.php /usr/sbin/sendmail-throttle
#ln -sf /opt/sendmail-wrapper/php_set_envs.php /usr/sbin/php-set-environment

