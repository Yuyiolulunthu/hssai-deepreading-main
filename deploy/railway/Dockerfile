# 讀伴 ・ Railway 部署映像
# 與 deploy/Dockerfile 的差別：原始碼「打包進映像」（Railway 沒有本機 volume 可掛），
# 執行期資料（data/）另外掛 Railway 的持久磁碟，由 entrypoint 初始化。
FROM php:8.2-apache

# .htaccess 需要 AllowOverride，並啟用 rewrite/headers
RUN a2enmod rewrite headers && \
    printf '<Directory /var/www/html>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/dupan.conf && \
    a2enconf dupan

# mbstring 需要 oniguruma（PHP 7.4 起），基底映像未內建
RUN apt-get update && \
    apt-get install -y --no-install-recommends libonig-dev && \
    docker-php-ext-install -j"$(nproc)" mbstring && \
    rm -rf /var/lib/apt/lists/*

# 上一步的 apt 會把 Debian 預設的 mpm_event 一併啟用，造成 "More than one MPM loaded"。
# a2dismod 對 MPM 有特殊處理、不一定生效，直接刪符號連結最確實。mod_php 必須搭 mpm_prefork。
RUN rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* && \
    ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load && \
    ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf && \
    echo "建置後的 MPM：$(ls /etc/apache2/mods-enabled/ | grep '^mpm_')"

# 上傳與 POST 上限（長文本、長 prompt）；與正式站一致
RUN printf 'post_max_size = 32M\nupload_max_filesize = 32M\nmemory_limit = 256M\nmax_execution_time = 180\nexpose_php = Off\n' \
    > /usr/local/etc/php/conf.d/dupan.ini

WORKDIR /var/www/html
COPY . /var/www/html

# data/ 不在版控裡；備一份 .htaccess 種子給第一次開機用（volume 掛上來時是空的）
RUN mkdir -p /opt/data-seed && \
    cp /var/www/html/deploy/railway/data-htaccess /opt/data-seed/.htaccess && \
    rm -rf /var/www/html/data

COPY deploy/railway/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
