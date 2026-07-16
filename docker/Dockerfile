FROM dunglas/frankenphp:1-php8.4.23 AS build

WORKDIR /build

# build deps only
RUN apt-get update && apt-get install -y \
    build-essential \
    wget \
    git \
    unzip \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# build SQLite
ENV SQLITE_VERSION=3450000
ENV SQLITE_YEAR=2024
RUN cd /tmp \
    && wget https://www.sqlite.org/${SQLITE_YEAR}/sqlite-autoconf-${SQLITE_VERSION}.tar.gz \
    && tar xzf sqlite-autoconf-${SQLITE_VERSION}.tar.gz \
    && cd sqlite-autoconf-${SQLITE_VERSION} \
    && ./configure --prefix=/usr/local \
    && make -j$(nproc) \
    && make install \
    && ldconfig \
    && rm -rf /tmp/sqlite-autoconf-${SQLITE_VERSION}*

FROM dunglas/frankenphp:1-php8.4.23

WORKDIR /app

# runtime deps only
RUN apt-get update && apt-get install -y \
    tini \
    zip \
    unzip \
    curl \
    ca-certificates \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

# copy sqlite runtime only
COPY --from=build /usr/local/include/sqlite3.h /usr/local/include/
COPY --from=build /usr/local/include/sqlite3ext.h /usr/local/include/
COPY --from=build /usr/local/lib/pkgconfig/sqlite3.pc /usr/local/lib/pkgconfig/
COPY --from=build /usr/local/lib/libsqlite3.so* /usr/local/lib/
COPY --from=build /usr/local/bin/sqlite3 /usr/local/bin/
ENV PKG_CONFIG_PATH=/usr/local/lib/pkgconfig
RUN ldconfig

# PHP extensions
RUN install-php-extensions \
    pdo_sqlite \
    pdo_mysql \
    gd \
    mbstring \
    exif \
    pcntl \
    bcmath \
    opcache \
    && rm -rf /usr/local/include/sqlite3*.h /usr/local/lib/pkgconfig/sqlite3.pc

# composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# app code
COPY . /app

# permissions
RUN mkdir -p storage/framework/{sessions,views,cache} \
    storage/logs bootstrap/cache database \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R 775 storage bootstrap/cache database

# caddy / entrypoint
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80 443 443/udp

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint.sh"]
