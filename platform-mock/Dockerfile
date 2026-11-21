FROM php:8.3-cli-alpine

RUN apk add --no-cache curl

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
COPY src/ src/
COPY public/ public/

RUN composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress

COPY entrypoint.sh ./
RUN chmod +x /app/entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/app/entrypoint.sh"]
