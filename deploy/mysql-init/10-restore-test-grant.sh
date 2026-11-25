#!/bin/sh
# Runs once, when MySQL initialises an empty data volume.
# The weekly `db:restore-test` restores the newest backup into a scratch database
# (BACKUP_RESTORE_DATABASE, default home_helper_restore_test) and drops it again,
# so the app user needs rights on that one database too, and nothing more.
set -e
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
GRANT ALL PRIVILEGES ON \`home_helper_restore_test\`.* TO '$MYSQL_USER'@'%';
FLUSH PRIVILEGES;
SQL
