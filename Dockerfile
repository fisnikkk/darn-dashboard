FROM php:8.4-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /app
COPY . .

# Railway sets PORT env var; shell form expands it
CMD php -S 0.0.0.0:${PORT:-8080} -t /app
