FROM php:8.4-apache-bookworm

# PDO SQLite, mbstring and curl are already included in the official image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev \
    && docker-php-ext-install -j"$(nproc)" intl \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY src ./src
COPY public ./public
COPY tests ./tests
COPY scripts ./scripts
COPY docker/healthcheck.php ./docker/healthcheck.php

RUN mkdir -p /var/www/html/data \
    && chown www-data:www-data /var/www/html/data \
    && chmod 0770 /var/www/html/data \
    && php -r 'require "src/bootstrap.php"; \Lab1\Application::router(":memory:");' \
    && apache2ctl configtest

ENV DB_PATH=/var/www/html/data/products.sqlite

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["php", "docker/healthcheck.php"]

CMD ["apache2-foreground"]
