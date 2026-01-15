<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis;

class PrometheusServiceProvider extends ServiceProvider {
    /**
     * Register services.
     */
    public function register(): void {
        $this->app->singleton(CollectorRegistry::class, function ($app) {
            return new CollectorRegistry($this->createStorageAdapter());
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void {
        //
    }

    /**
     * 根据配置创建存储适配器
     */
    protected function createStorageAdapter() {
        if ($this->app->environment('testing')) {
            return new InMemory();
        }

        $adapter = config('prometheus.storage_adapter', 'memory');

        return match ($adapter) {
            'redis' => $this->createRedisAdapter(),
            'apc' => new APC(),
            default => new InMemory(),
        };
    }

    /**
     * 创建 Redis 存储适配器
     */
    protected function createRedisAdapter(): Redis {
        $config = config('prometheus.redis');

        Redis::setDefaultOptions([
            'host' => $config['host'],
            'port' => $config['port'],
            'password' => $config['password'],
            'database' => $config['database'],
            'timeout' => $config['timeout'],
            'read_timeout' => $config['read_timeout'],
            'persistent_connections' => $config['persistent_connections'],
        ]);

        return new Redis([
            'prefix' => $config['prefix'],
        ]);
    }
}
