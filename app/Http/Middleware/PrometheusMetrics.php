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

        $response = null;
        $exception = null;

        try {
            // 处理请求
            $response = $next($request);

            return $response;
        } catch (\Throwable $e) {
            $exception = $e;

            throw $e;
        } finally {
            // 计算请求耗时
            $duration = microtime(true) - $startTime;

            // 减少正在处理的请求计数（确保即使发生异常也会执行）
            $this->decrementInProgressRequests($request);

            // 记录 metrics
            if ($response !== null) {
                $this->recordMetrics($request, $response, $duration);
            } elseif ($exception !== null) {
                // 对于未被捕获的异常，记录为 500 错误
                $this->recordExceptionMetrics($request, $exception, $duration);
            }
        }
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
     *
     * 注意：此方法在路由解析之前调用，因此不包含 path 标签。
     * 原因：如果包含 path 标签，在 increment 时路由未解析（使用实际路径），
     * 在 decrement 时路由已解析（使用路由模式），会导致标签不匹配，
     * 从而引发 gauge 泄漏和错误计数。
     */
    protected function incrementInProgressRequests(Request $request): void {
        try {
            $gauge = $this->registry->getOrRegisterGauge(
                config('prometheus.namespace', 'cbdb'),
                'http_requests_in_progress',
                'Number of HTTP requests currently being processed',
                ['method']
            );

            $gauge->inc(['method' => $request->method()]);
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
                ['method']
            );

            $gauge->dec(['method' => $request->method()]);
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
     * 记录异常情况的 metrics
     */
    protected function recordExceptionMetrics(Request $request, \Throwable $exception, float $duration): void {
        try {
            // 确定 HTTP 状态码
            $statusCode = 500;
            if (method_exists($exception, 'getStatusCode')) {
                $statusCode = $exception->getStatusCode();
            }

            $labels = array_merge(
                $this->getLabels($request),
                ['status' => $statusCode]
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
     *
     * 注意：为避免 label cardinality 爆量（例如机器人扫描随机 URL），
     * 当请求不匹配任何已定义路由时，统一归类为 __unknown__。
     */
    protected function getPath(Request $request): string {
        $route = $request->route();

        if (config('prometheus.http_metrics.include_route_params', false)) {
            // 使用路由模式（如 /user/{id}）
            if ($route) {
                return '/' . ltrim($route->uri(), '/');
            }

            // 未匹配任何路由，归类为 /__unknown__ 避免 cardinality 过高
            return '/__unknown__';
        }

        // 使用实际路径（但仍需处理未匹配路由的情况）
        if ($route) {
            return '/' . ltrim($request->path(), '/');
        }

        // 未匹配任何路由，归类为 /__unknown__
        return '/__unknown__';
    }
}
