#!/bin/bash

########### CONFIGURATION ##################
INSTALL_DIR=/opt/sendmail-wrapper
WRAPPER_BIN=/usr/sbin/sendmail-wrapper
THROTTLE_BIN=/usr/sbin/sendmail-throttle
PHP_AUTO_PREPEND=/var/www/shared/prepend.php
############################################

cd ${INSTALL_DIR}

chmod 700 .git

# All code and configuration files must be owned by root. Never make sendmailwrapper the owner of any file,
# as a file owner can always change its permissions and modify it. Only grant group read access to the files
# that the sendmailwrapper user needs to read (sendmail-throttle runs as sendmailwrapper via sudo).
chown root:root *.php *.ini
chown root:sendmailwrapper sendmail-throttle.php config.private.ini
chmod 440 config.private.ini
chmod 444 config.ini config.local.ini
chmod 555 sendmail-wrapper.php prepend.php
chmod 550 sendmail-throttle.php
chmod 400 schema/*.sql

ln -sf ${INSTALL_DIR}/sendmail-wrapper.php ${WRAPPER_BIN}
ln -sf ${INSTALL_DIR}/sendmail-throttle.php ${THROTTLE_BIN}
install -o root -g root -m 444 prepend.php ${PHP_AUTO_PREPEND}
