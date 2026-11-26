FROM php:8.3-apache

# Evitar prompts interactivos durante la instalación de paquetes
ENV DEBIAN_FRONTEND=noninteractive

# Configuración de Puppeteer para usar el Chromium instalado en el sistema
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    CHROME_PATH=/usr/bin/chromium

# 1. Dependencias del sistema, librerías para extensiones PHP y Chromium (Puppeteer)
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    curl \
    gnupg \
    ca-certificates \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    chromium \
    fonts-liberation \
    fonts-noto-color-emoji \
    libnss3 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libdrm2 \
    libxkbcommon0 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libgbm1 \
    libasound2 \
    libpango-1.0-0 \
    libpangocairo-1.0-0 \
    libxshmfence1 \
    xdg-utils \
    && rm -rf /var/lib/apt/lists/*

# 2. Extensiones PHP requeridas por Laravel 13, PhpWord, GD, Intl y MySQL
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        zip \
        gd \
        intl \
        mbstring \
        xml \
        exif \
        pcntl \
        bcmath \
        opcache

# 3. Instalar Node.js 22 LTS (para Vite 8 y scripts Puppeteer .cjs)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# 4. Instalar Composer 2
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 5. Límites PHP recomendados para OCR (Gemini), auditorías Puppeteer y subida de documentos/fotos
RUN { \
        echo "upload_max_filesize=20M"; \
        echo "post_max_size=25M"; \
        echo "max_execution_time=180"; \
        echo "memory_limit=512M"; \
    } > /usr/local/etc/php/conf.d/99-optimus.ini

# 6. Configurar Apache con DocumentRoot en /var/www/html/public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN a2enmod rewrite headers \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# 7. Instalar dependencias PHP (aprovechando caché de capas Docker)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# 8. Instalar dependencias Node.js (sin descargar Chromium duplicado)
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# 9. Copiar el código fuente de la aplicación y compilar assets Vite
COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && npm run build

# 10. Preparar directorios de Laravel y permisos para Apache (www-data)
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

# Adaptar puerto dinámico ($PORT en plataformas PaaS como Railway/Render) y arrancar Apache
CMD ["sh", "-c", "PORT=${PORT:-80}; sed -i \"s/Listen 80/Listen ${PORT}/\" /etc/apache2/ports.conf; sed -i \"s/:80>/:${PORT}>/\" /etc/apache2/sites-available/000-default.conf; php artisan migrate --force && php artisan optimize:clear || true; exec apache2-foreground"]