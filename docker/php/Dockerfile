FROM php:8.5-cli

COPY --from=ghcr.io/mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions pdo_pgsql pgsql redis pcntl intl zip opcache

ARG UID=1000
ARG GID=1000
RUN groupadd --non-unique --gid ${GID} app && useradd --non-unique --uid ${UID} --gid ${GID} --create-home app

WORKDIR /var/www/html
USER app

EXPOSE 8000

ENV PHP_CLI_SERVER_WORKERS=4

# Not `artisan serve`: it re-reads .env in a child process and drops the
# DB_HOST / REDIS_HOST overrides that compose.yaml passes to the container.
CMD ["sh", "-c", "cd public && exec php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]
