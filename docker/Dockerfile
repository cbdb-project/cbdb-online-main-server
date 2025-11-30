# FrankenPHP Dockerfile for CBDB Online
# 使用 FrankenPHP 替代传统的 PHP-FPM + Nginx 架构

FROM dunglas/frankenphp:1-php8.4.15

# 设置工作目录
WORKDIR /app

# 安装编译工具和依赖（用于编译 SQLite）
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
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
# FrankenPHP 使用 install-php-extensions 工具
RUN install-php-extensions \
    pdo_sqlite \
    pdo_mysql \
    gd \
    mbstring \
    exif \
    pcntl \
    bcmath \
    opcache

# 复制 Composer（FrankenPHP 镜像已包含 Composer，但我们确保使用最新版本）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 复制应用代码
COPY . /app

# 确保必要的目录存在并设置权限
RUN mkdir -p storage/framework/{sessions,views,cache} \
    && mkdir -p storage/logs \
    && mkdir -p bootstrap/cache \
    && mkdir -p database \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R 775 storage bootstrap/cache database

# 复制 FrankenPHP 配置文件（Caddyfile）
COPY docker/Caddyfile /etc/caddy/Caddyfile

# 复制并设置启动脚本
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# 暴露端口
# FrankenPHP 默认使用 80 (HTTP) 和 443 (HTTPS)
EXPOSE 80
EXPOSE 443
EXPOSE 443/udp

# 使用自定义启动脚本
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
