#!/bin/sh
# Runs the full suite on the MariaDB lane (thai_lottery_test) using a temporary copy of phpunit.xml.
# phpunit.xml itself is not modified. The copy is removed when the script exits.
set -u
cd /home/user/thai-lottery-ainul-islam || exit 1
trap 'rm -f phpunit.mariadb.xml' EXIT
python3 - <<'PY'
s = open('phpunit.xml').read()
s = s.replace('<env name="DB_CONNECTION" value="sqlite"/>',
  '<env name="DB_CONNECTION" value="mysql"/>\n        <env name="DB_HOST" value="127.0.0.1"/>\n'
  '        <env name="DB_PORT" value="3306"/>\n        <env name="DB_USERNAME" value="lotto"/>\n'
  '        <env name="DB_PASSWORD" value="lotto_test_pw"/>', 1)
s = s.replace('<env name="DB_DATABASE" value="database/thai_lottery_test"/>',
  '<env name="DB_DATABASE" value="thai_lottery_test"/>', 1)
open('phpunit.mariadb.xml', 'w').write(s)
PY
php vendor/bin/phpunit -c phpunit.mariadb.xml "$@"
