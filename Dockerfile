FROM php:8.4-cli

RUN docker-php-ext-install pdo_mysql

WORKDIR /app
COPY . .
RUN chmod +x start.sh

ENTRYPOINT ["/app/start.sh"]
