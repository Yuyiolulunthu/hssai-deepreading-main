#!/bin/sh
set -e

# ---- 1. Apache 聽 Railway 指定的埠（本機測試時預設 80）----
PORT="${PORT:-80}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \*:[0-9]+>#<VirtualHost *:${PORT}>#" /etc/apache2/sites-available/000-default.conf

# ---- 2. 持久磁碟初始化（Railway volume 掛在 /var/www/html/data）----
mkdir -p /var/www/html/data
[ -f /var/www/html/data/.htaccess ] || cp /opt/data-seed/.htaccess /var/www/html/data/.htaccess
chown -R www-data:www-data /var/www/html/data

# ---- 3. 由環境變數產生 config.php（金鑰不進版控、不進映像）----
php /var/www/html/deploy/railway/make_config.php /var/www/html/config.php
chown www-data:www-data /var/www/html/config.php
chmod 640 /var/www/html/config.php

# 保險：啟動時再確保只有 mpm_prefork（Apache 載入兩個 MPM 會直接拒絕啟動）
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

MPMS=$(ls /etc/apache2/mods-enabled/ | grep '^mpm_.*\.load' | tr '\n' ' ')
DISK=$(df -h /var/www/html/data | tail -1 | awk '{print $2" 總量，可用 "$4}')
echo "讀伴啟動：port ${PORT}"
echo "  已載入的 MPM：${MPMS}"
echo "  data 掛載點：${DISK}"
exec "$@"
