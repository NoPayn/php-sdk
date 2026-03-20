FROM php:8.2-cli

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-scripts

COPY src/ src/
COPY demo/ demo/

EXPOSE 3000

WORKDIR /app/demo
CMD ["php", "-S", "0.0.0.0:3000", "router.php"]
