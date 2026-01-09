<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use Symfony\Component\HttpFoundation\Response;

class PrometheusMetrics {
    protected CollectorRegistry $registry;

    public function __construct(CollectorRegistry $registry) {
        $this->registry = $registry;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response {
        // 如果 Prometheus 未启用或路径被排除，则跳过
        if (!$this->shouldCollectMetrics($request)) {
            return $next($request);
        }

        // 记录请求开始时间
        $startTime = microtime(true);

        // 增加正在处理的请求计数
        $this->incrementInProgressRequests($request);

        // 处理请求
        $response = $next($request);

        // 计算请求耗时
        $duration = microtime(true) - $startTime;

        // 减少正在处理的请求计数
        $this->decrementInProgressRequests($request);

        // 记录 metrics
        $this->recordMetrics($request, $response, $duration);

        return $response;
    }

    /**
     * 检查是否应该收集 metrics
     */
    protected function shouldCollectMetrics(Request $request): bool {
        if (!config('prometheus.enabled', true)) {
            return false;
        }

        if (!config('prometheus.http_metrics.enabled', true)) {
            return false;
        }

        $excludedPaths = config('prometheus.http_metrics.excluded_paths', []);
        $path = $request->path();

        foreach ($excludedPaths as $excludedPath) {
            if ($path === trim($excludedPath, '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * 增加正在处理的请求计数
     */
    protected function incrementInProgressRequests(Request $request): void {
        try {
            $gauge = $this->registry->getOrRegisterGauge(
                config('prometheus.namespace', 'cbdb'),
                'http_requests_in_progress',
                'Number of HTTP requests currently being processed',
                ['method', 'path']
            );

            $gauge->inc($this->getLabels($request));
        } catch (\Exception $e) {
            // 忽略 metrics 收集错误，不影响正常请求
            report($e);
        }
    }

    /**
     * 减少正在处理的请求计数
     */
    protected function decrementInProgressRequests(Request $request): void {
        try {
            $gauge = $this->registry->getOrRegisterGauge(
                config('prometheus.namespace', 'cbdb'),
                'http_requests_in_progress',
                'Number of HTTP requests currently being processed',
                ['method', 'path']
            );

            $gauge->dec($this->getLabels($request));
        } catch (\Exception $e) {
            report($e);
        }
    }

    /**
     * 记录请求 metrics
     */
    protected function recordMetrics(Request $request, Response $response, float $duration): void {
        try {
            $labels = array_merge(
                $this->getLabels($request),
                ['status' => $response->getStatusCode()]
            );

            // 记录请求总数
            $counter = $this->registry->getOrRegisterCounter(
                config('prometheus.namespace', 'cbdb'),
                'http_requests_total',
                'Total number of HTTP requests',
                ['method', 'path', 'status']
            );
            $counter->inc($labels);

            // 记录请求延迟分布
            $histogram = $this->registry->getOrRegisterHistogram(
                config('prometheus.namespace', 'cbdb'),
                'http_request_duration_seconds',
                'HTTP request latency in seconds',
                ['method', 'path', 'status'],
                config('prometheus.http_metrics.latency_buckets')
            );
            $histogram->observe($duration, $labels);
        } catch (\Exception $e) {
            report($e);
        }
    }

    /**
     * 获取请求的标签
     */
    protected function getLabels(Request $request): array {
        $path = $this->getPath($request);

        return [
            'method' => $request->method(),
            'path' => $path,
        ];
    }

    /**
     * 获取路径（可选择包含路由参数）
     */
    protected function getPath(Request $request): string {
        if (config('prometheus.http_metrics.include_route_params', false)) {
            // 使用路由模式（如 /user/{id}）
            $route = $request->route();
            if ($route) {
                return '/' . ltrim($route->uri(), '/');
            }
        }

        // 使用实际路径
        return '/' . ltrim($request->path(), '/');
    }
}
