FROM php:8.4-fpm

# 安装编译工具和依赖
RUN apt-get update && apt-get install -y \
    zip unzip git \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    wget \
    build-essential \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 从官网下载并编译安装 SQLite3（避免 Ubuntu 24.04 LTS apt 版本的坑）
# 使用 SQLite 3.45.0 或更新版本
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
    && cd /tmp \
    && rm -rf sqlite-autoconf-${SQLITE_VERSION}*

# 安装 PHP 扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_sqlite pdo_mysql mbstring exif pcntl bcmath gd

# 安装 Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# 安装 Laravel 依赖（开发环境）
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

# 复制代码
COPY . /var/www/html

# 完成 Composer 安装（執行 deploy.sh 以保持與伺服器相同流程）
RUN chmod +x deploy.sh && ./deploy.sh

# 给 storage 和 bootstrap/cache 权限
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# 确保 database 目录存在
RUN mkdir -p database && chown -R www-data:www-data database

EXPOSE 9000

CMD ["php-fpm"]
