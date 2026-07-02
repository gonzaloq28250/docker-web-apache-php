FROM php:8.3-apache-bookworm

LABEL description="Apache 2.4 + PHP 8.3 - Laragon replacement for local dev"
LABEL version="1.0"

ENV APACHE_DOCUMENT_ROOT=/var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libxpm-dev \
        libicu-dev \
        libonig-dev \
        libxml2-dev \
        libxslt1-dev \
        libzip-dev \
        libsqlite3-dev \
        libcurl4-openssl-dev \
        libsodium-dev \
        openssl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-xpm \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        mysqli \
        opcache \
        pdo_mysql \
        pdo_sqlite \
        soap \
        xsl \
        zip

RUN a2enmod \
        rewrite \
        ssl \
        headers \
        expires \
        deflate \
        vhost_alias \
        socache_shmcb \
        proxy \
        proxy_http \
        proxy_fcgi \
        include \
        negotiation \
        setenvif \
    && a2dissite 000-default

COPY config/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY config/apache/ssl.conf /etc/apache2/sites-available/ssl.conf
COPY config/apache/security.conf /etc/apache2/conf-available/security.conf

RUN a2ensite 000-default ssl \
    && a2enconf security

COPY config/php/php.ini /usr/local/etc/php/conf.d/zzz-laragon.ini

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
