FROM php:8.2-apache

# Установка расширений PHP для работы с MySQL, SQLite и cURL
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_sqlite curl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Настройка рабочей директории
WORKDIR /var/www/html

EXPOSE 80
