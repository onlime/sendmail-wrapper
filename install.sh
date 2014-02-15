#!/bin/bash

########### CONFIGURATION ##################
INSTALL_DIR=/opt/sendmail-wrapper
WRAPPER_BIN=/usr/sbin/sendmail-wrapper
THROTTLE_BIN=/usr/sbin/sendmail-throttle
PHP_AUTO_PREPEND=/var/www/shared/prepend.php
############################################

cd ${INSTALL_DIR}

chmod 700 .git

#chattr -i sendmail-*.php
chown sendmailwrapper:sendmailwrapper *.php *.ini
chmod 400 config.private.ini
chmod 444 config.ini config.local.ini
chmod 555 sendmail-wrapper.php prepend.php
chmod 500 sendmail-throttle.php
chmod 400 throttle.sql
#chattr +i sendmail-*.php

ln -sf ${INSTALL_DIR}/sendmail-wrapper.php ${WRAPPER_BIN}
ln -sf ${INSTALL_DIR}/sendmail-throttle.php ${THROTTLE_BIN}
/bin/cp -a prepend.php ${PHP_AUTO_PREPEND}
