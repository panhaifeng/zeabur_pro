#!/bin/bash
set -e

PORT="${PORT:-8080}"

# Zeabur 会注入 PORT，Apache 默认 80，这里改成实际端口
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

mkdir -p /var/www/html/runtime \
    /var/www/html/public/uploads/avatar
chown -R www-data:www-data /var/www/html/runtime /var/www/html/public/uploads || true
chmod -R 775 /var/www/html/runtime /var/www/html/public/uploads || true

exec "$@"
