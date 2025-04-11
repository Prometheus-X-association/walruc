FROM php:8.2-fpm

# Set PHP memory limit
ENV PHP_MEMORY_LIMIT=256M

# Install required dependencies and PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libfreetype-dev \
    libjpeg-dev \
    libpng-dev \
    libzip-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j "$(nproc)" \
    gd \
    mysqli \
    opcache \
    pdo_mysql \
    zip \
  && pecl install APCu \
  && docker-php-ext-enable apcu \
  && apt-get clean \
  && rm -rf /var/lib/apt/lists/*

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Use the www-data user
USER www-data
