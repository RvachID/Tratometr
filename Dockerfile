FROM php:8.1-cli

# Устанавливаем зависимости
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    libzip-dev \
    libonig-dev \
    zip \
    libpng-dev \
    libpq-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libxml2-dev \
    && docker-php-ext-install pdo pdo_mysql zip

# Устанавливаем Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Копируем проект
WORKDIR /app
COPY . .

# Устанавливаем зависимости
RUN composer install --no-dev --optimize-autoloader

# Открываем порт
EXPOSE 8080

# Запускаем встроенный сервер
CMD ["sh", "-c", "php yii migrate --interactive=0 > /app/migrate.log 2>&1 && php -S 0.0.0.0:8080 -t web"]


