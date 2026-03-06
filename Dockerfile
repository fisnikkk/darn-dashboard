FROM php:8.4-cli

RUN apt-get update && apt-get install -y libzip-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo_mysql zip

WORKDIR /app
COPY . .
RUN chmod +x start.sh

ENTRYPOINT ["/app/start.sh"]
