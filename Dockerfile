# Use PHP CLI with needed extensions
FROM php:8.1-cli

# Install system libraries for gd
RUN apt-get update && apt-get install -y \
    libjpeg-dev libpng-dev libfreetype6-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install sockets mysqli gd

# Set working directory
WORKDIR /app

# Copy your app code
COPY . .

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies
RUN composer install

# Expose WebSocket port
EXPOSE 8080

# Start WebSocket server
CMD ["php", "websocket-server.php"]
