FROM php:8.3-cli

# System dependencies (apcu via pecl only if needed, keep fast)
RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libpq-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql intl zip opcache \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

# Copy everything else
COPY . .

# Generate JWT keys if missing
RUN mkdir -p config/jwt \
    && if [ ! -f config/jwt/private.pem ]; then \
        openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 2>/dev/null \
        && openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem 2>/dev/null; \
    fi

# Build .env.local.php for prod (avoids needing real DB at runtime for cache warmup)
RUN composer dump-env prod || true

# Create var directories
RUN mkdir -p var/cache var/log \
    && chmod -R 777 var

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
