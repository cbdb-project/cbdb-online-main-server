# Dev Container for cbdb-online-main-server
# PHP 8.4 (Debian Bookworm) – development only, not a production image
FROM mcr.microsoft.com/devcontainers/php:1-8.4-bookworm

RUN rm -f /etc/apt/sources.list.d/yarn.list || true

# System packages
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    zip \
    sqlite3 \
    mariadb-client \
    && rm -rf /var/lib/apt/lists/*

# Verify key tools exist
RUN php -v && composer --version
