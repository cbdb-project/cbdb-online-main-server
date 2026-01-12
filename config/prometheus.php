<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prometheus Metrics 配置
    |--------------------------------------------------------------------------
    |
    | 此配置文件定义 Prometheus metrics 的各項設定，包括存儲適配器、
    | 命名空間、標籤等。
    |
    */

    /*
    |--------------------------------------------------------------------------
    | 启用状态
    |--------------------------------------------------------------------------
    |
    | 控制是否启用 Prometheus metrics 收集。生产环境建议启用。
    |
    */
    'enabled' => env('PROMETHEUS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | 命名空间
    |--------------------------------------------------------------------------
    |
    | Metrics 的命名空间前缀，用于区分不同的应用。
    |
    */
    'namespace' => env('PROMETHEUS_NAMESPACE', 'cbdb'),

    /*
    |--------------------------------------------------------------------------
    | 存储适配器
    |--------------------------------------------------------------------------
    |
    | 支持的适配器：
    | - memory: 内存存储（适合单进程、开发环境）
    | - redis: Redis 存储（推荐用于生产环境，支持多进程）
    | - apc: APC 存储（适合多进程，但不支持跨机器）
    |
    */
    'storage_adapter' => env('PROMETHEUS_STORAGE_ADAPTER', 'memory'),

    /*
    |--------------------------------------------------------------------------
    | Redis 配置
    |--------------------------------------------------------------------------
    |
    | 当使用 redis 适配器时的连接配置。
    |
    */
    'redis' => [
        'host' => env('PROMETHEUS_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
        'port' => env('PROMETHEUS_REDIS_PORT', env('REDIS_PORT', 6379)),
        'password' => env('PROMETHEUS_REDIS_PASSWORD', env('REDIS_PASSWORD', null)),
        'database' => env('PROMETHEUS_REDIS_DB', 2),
        'timeout' => 0.1,
        'read_timeout' => 10,
        'persistent_connections' => false,
        'prefix' => env('PROMETHEUS_REDIS_PREFIX', 'PROMETHEUS_'),
    ],

    /*
    |--------------------------------------------------------------------------
    | 默认标签
    |--------------------------------------------------------------------------
    |
    | 所有 metrics 都会带上这些标签。
    |
    */
    'default_labels' => [
        'app' => env('APP_NAME', 'cbdb'),
        'env' => env('APP_ENV', 'production'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Metrics 配置
    |--------------------------------------------------------------------------
    |
    | HTTP 请求相关的 metrics 配置。
    |
    */
    'http_metrics' => [
        // 是否启用 HTTP metrics 收集
        'enabled' => env('PROMETHEUS_HTTP_METRICS_ENABLED', true),

        // 请求延迟分布的 bucket（单位：秒）
        'latency_buckets' => [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0],

        // 排除的路径（不收集 metrics）
        'excluded_paths' => [
            '/metrics',
        ],

        // 是否记录路由参数（如 /user/{id}）
        // 重要：必须设为 true 以避免内存泄漏！
        // 当设为 false 时，每个唯一的 URL（如 /basicinformation/1/edit, /basicinformation/2/edit）
        // 都会创建独立的 metric 时间序列，导致内存无限增长
        // 当设为 true 时，所有请求会合并到路由模式（如 /basicinformation/{personid}/edit）
        'include_route_params' => env('PROMETHEUS_INCLUDE_ROUTE_PARAMS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics Endpoint 配置
    |--------------------------------------------------------------------------
    |
    | /metrics 端点的访问控制配置。
    |
    */
    'metrics_endpoint' => [
        // 是否启用基本认证
        'auth_enabled' => env('PROMETHEUS_AUTH_ENABLED', false),

        // 认证用户名和密码（仅在 auth_enabled 为 true 时使用）
        'username' => env('PROMETHEUS_AUTH_USERNAME', 'prometheus'),
        'password' => env('PROMETHEUS_AUTH_PASSWORD', 'secret'),

        // 允许访问的 IP 白名单（空数组表示允许所有 IP）
        'allowed_ips' => array_filter(explode(',', env('PROMETHEUS_ALLOWED_IPS', ''))),
    ],
];
