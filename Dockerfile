# Use PHP CLI with needed extensions
FROM php:8.1-cli

# Install required extensions
RUN docker-php-ext-install sockets mysqli

# Set working directory
WORKDIR /app

# Copy all your code to the container
COPY . .

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies (like Ratchet)
RUN composer install

# Open WebSocket port
EXPOSE 8080

# Start the WebSocket server
CMD ["php", "websocket-server.php"]
