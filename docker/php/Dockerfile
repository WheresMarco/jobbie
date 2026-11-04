FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install intl pcntl pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# The job list scans one narrow row per active posting in PHP. With every
# platform and Platsbanken that is over 70 000 rows, about 180 MB.
RUN echo 'memory_limit=512M' > "$PHP_INI_DIR/conf.d/memory.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
