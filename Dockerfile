# ---- Stage 1: build the Angular frontend into public/app ----
FROM node:22-alpine AS frontend
WORKDIR /src/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN mkdir -p ../public && npm run build:deploy

# ---- Stage 2: PHP + Apache runtime ----
FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers setenvif expires \
    && sed -ri 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-enabled/insquire.conf

WORKDIR /var/www/html
COPY . .
COPY --from=frontend /src/public/app ./public/app
RUN rm -rf frontend/node_modules web ollama macos \
    && mkdir -p storage/rubrics \
    && chown -R www-data:www-data storage

EXPOSE 80
