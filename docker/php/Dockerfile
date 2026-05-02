FROM php:8.3-fpm-bookworm

ARG UID=1000
ARG GID=1000
ARG PYTHON_REQUIREMENTS=requirements-core.txt

ENV COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        antiword \
        ca-certificates \
        cmake \
        curl \
        docx2txt \
        ffmpeg \
        genisoimage \
        git \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        liblapack-dev \
        libmagickwand-dev \
        libopenblas-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libxml2-dev \
        libxslt1-dev \
        libzip-dev \
        libreoffice \
        libimage-exiftool-perl \
        poppler-utils \
        p7zip-full \
        python3 \
        python3-dev \
        python3-pip \
        tesseract-ocr \
        tesseract-ocr-eng \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        pdo_pgsql \
        sockets \
        xsl \
        zip \
    && pecl install redis imagick \
    && docker-php-ext-enable redis imagick \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-plos.ini

WORKDIR /var/www/html

COPY requirements-core.txt requirements-media.txt requirements-gpu.txt ./
RUN if [ -n "${PYTHON_REQUIREMENTS}" ]; then \
        pip3 install --no-cache-dir --break-system-packages -r "${PYTHON_REQUIREMENTS}"; \
    fi

COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts

RUN groupmod -o -g "${GID}" www-data \
    && usermod -o -u "${UID}" -g www-data www-data \
    && mkdir -p storage bootstrap/cache \
    && chown -R www-data:www-data vendor storage bootstrap/cache

USER www-data
