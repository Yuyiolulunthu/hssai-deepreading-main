# 讀伴 ・ 部署映像
# 使用 Apache + mod_php:與原 cPanel 環境行為一致,
# data/ 目錄的 .htaccess 保護在此仍然有效(nginx 直跑 PHP 則無效)。
FROM php:8.2-apache

# .htaccess 需要 AllowOverride,並啟用 rewrite/headers
RUN a2enmod rewrite headers && \
    printf '<Directory /var/www/html>\n\
    Options -Indexes +FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n' > /etc/apache2/conf-available/dupan.conf && \
    a2enconf dupan

# mbstring 讓中文截字走原生實作(專案有 polyfill,但原生較快)
# 需要 libonig-dev,官方映像未預裝,缺了會在編譯階段失敗
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev \
 && docker-php-ext-install -j"$(nproc)" mbstring \
 && rm -rf /var/lib/apt/lists/*

# 保險:mod_php 只能搭 mpm_prefork。基底映像版本不同時,上一步的 apt 可能連帶啟用 mpm_event,
# Apache 會以 "More than one MPM loaded" 拒絕啟動(2026-09-23 在 Railway 重建時遇過)。
# a2dismod 對 MPM 不一定生效,直接處理 mods-enabled 的連結最確實。
RUN rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* \
 && ln -sf ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
 && ln -sf ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# 上傳與 POST 上限(長文本、長 prompt)
RUN printf 'post_max_size = 32M\nupload_max_filesize = 32M\nmemory_limit = 256M\nmax_execution_time = 180\nexpose_php = Off\n' \
    > /usr/local/etc/php/conf.d/dupan.ini

# 不在映像中內建原始碼:以 volume 掛載,更新時 git pull 即可,不必重建映像
WORKDIR /var/www/html

# 保險:於 Apache 設定層封鎖 data/ 與 deploy/(不依賴 .htaccess)
# data/ 內含學生名單、統計、解析結果
RUN printf '<Directory /var/www/html/data>\n\
    Require all denied\n\
</Directory>\n<Directory /var/www/html/deploy>\n\
    Require all denied\n\
</Directory>\n' > /etc/apache2/conf-available/dupan-deny.conf && \
    a2enconf dupan-deny
