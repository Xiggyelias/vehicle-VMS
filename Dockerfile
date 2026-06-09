FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --optimize-autoloader \
    --classmap-authoritative \
    --no-interaction \
    --no-progress \
    --prefer-dist

FROM php:8.2-apache-bookworm

RUN docker-php-ext-install pdo_mysql mysqli

RUN a2enmod rewrite headers expires

WORKDIR /var/www/html

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY docker/apache-prod.conf /etc/apache2/conf-available/vrs-prod.conf
COPY docker/entrypoint.sh /usr/local/bin/vrs-entrypoint

RUN chmod +x /usr/local/bin/vrs-entrypoint \
    && a2enconf vrs-prod \
    && mkdir -p logs uploads uploads/secure \
    && chown -R www-data:www-data logs uploads

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health-check.php || exit 1

ENTRYPOINT ["vrs-entrypoint"]
CMD ["apache2-foreground"]
