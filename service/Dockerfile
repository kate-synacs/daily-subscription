FROM php:8.3-cli-alpine

RUN apk add --no-cache \
    bash \
    curl \
    libpq \
    postgresql-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql \
    && apk del $PHPIZE_DEPS postgresql-dev

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-progress

COPY . .

RUN chmod +x /app/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/app/entrypoint.sh"]
