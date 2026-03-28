# Dev Container for cbdb-online-main-server
# PHP 8.4 (Debian Bookworm) – development only, not a production image
FROM mcr.microsoft.com/devcontainers/php:8.4-bookworm

# System packages
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    zip \
    sqlite3 \
    mariadb-client \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions not yet present in the base image
RUN apt-get update && apt-get install -y --no-install-recommends \
    php8.4-intl \
    php8.4-bcmath \
    php8.4-zip \
    php8.4-xml \
    php8.4-pdo \
    php8.4-mysql \
    php8.4-sqlite3 \
    php8.4-mbstring \
    php8.4-curl \
    && rm -rf /var/lib/apt/lists/*

# Verify key tools exist
RUN php -v && composer --version
