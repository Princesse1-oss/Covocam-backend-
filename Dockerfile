FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libpq-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql intl zip opcache \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN echo "upload_max_filesize = 10M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 12M" >> /usr/local/etc/php/conf.d/uploads.ini \
    && echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN for i in 1 2 3; do composer install --no-dev --optimize-autoloader --no-scripts --no-autoloader && break || echo "Attempt $i failed, retrying..." && sleep 10; done

ARG CACHEBUST
RUN echo "Cache bust at ${CACHEBUST:-now}" > /tmp/bust
COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p config/jwt var/cache var/log \
    && if [ ! -f config/jwt/private.pem ]; then \
        openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 2>/dev/null \
        && openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem 2>/dev/null; \
    fi \
    && chmod -R 777 var

RUN echo 'APP_ENV=prod' > .env \
    && echo 'APP_SECRET=override-me' >> .env \
    && echo 'DATABASE_URL=postgresql://localhost/covocam_db' >> .env \
    && echo 'JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem' >> .env \
    && echo 'JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem' >> .env \
    && echo 'JWT_PASSPHRASE=' >> .env \
    && echo 'CORS_ALLOW_ORIGIN=*' >> .env \
    && echo 'MAILER_DSN=smtp://andriadongmo%40gmail.com:fvmnkbwttzkjzjzy@smtp.gmail.com:465?encryption=ssl&auth_mode=login' >> .env \
    && echo 'MAILER_SENDER_EMAIL=andriadongmo@gmail.com' >> .env \
    && echo 'CAMPAY_APP_CODE=override-me' >> .env \
    && echo 'CAMPAY_APP_PASSWORD=override-me' >> .env \
    && echo 'PAYMENT_MODE=simulation' >> .env \
    && echo 'PLATFORM_COMMISSION_RATE=0.10' >> .env \
    && echo 'ADMIN_PHONE=237600000000' >> .env \
    && echo 'FRONTEND_URL=https://covocam-frontend.vercel.app' >> .env \
    && echo 'TRUSTED_PROXIES=10.0.0.0/8' >> .env \
    && echo 'DEFAULT_URI=http://localhost' >> .env

COPY docker-start.sh /usr/local/bin/docker-start.sh
RUN chmod +x /usr/local/bin/docker-start.sh

EXPOSE 8000

CMD ["docker-start.sh"]
