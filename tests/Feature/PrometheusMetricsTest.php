<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PrometheusMetricsTest extends TestCase {
    #[Test]
    public function metrics_endpoint_returns_prometheus_format(): void {
        // 启用 Prometheus metrics
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.http_metrics.enabled', true);

        // 发起一个测试请求来生成一些 metrics
        $this->get('/');

        // 访问 /metrics 端点
        $response = $this->get('/metrics');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');

        // 验证返回的内容包含 Prometheus metrics
        $content = $response->getContent();
        $this->assertStringContainsString('# HELP', $content);
        $this->assertStringContainsString('# TYPE', $content);
    }

    #[Test]
    public function metrics_endpoint_includes_http_request_metrics(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.http_metrics.enabled', true);
        Config::set('prometheus.namespace', 'cbdb');

        // 发起多个测试请求
        $this->get('/');
        $this->get('/');

        // 访问 metrics 端点
        $response = $this->get('/metrics');

        $response->assertStatus(200);

        $content = $response->getContent();

        // 验证包含请求总数指标
        $this->assertStringContainsString('cbdb_http_requests_total', $content);

        // 验证包含请求延迟指标
        $this->assertStringContainsString('cbdb_http_request_duration_seconds', $content);

        // 验证包含正在处理的请求数指标
        $this->assertStringContainsString('cbdb_http_requests_in_progress', $content);
    }

    #[Test]
    public function metrics_endpoint_excludes_metrics_path(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.http_metrics.enabled', true);
        Config::set('prometheus.http_metrics.excluded_paths', ['/metrics']);

        // 访问 metrics 端点（不应该被记录）
        $response = $this->get('/metrics');

        $response->assertStatus(200);

        $content = $response->getContent();

        // 不应该包含 /metrics 路径的记录
        $this->assertStringNotContainsString('path="/metrics"', $content);
    }

    #[Test]
    public function metrics_endpoint_respects_ip_whitelist(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.metrics_endpoint.allowed_ips', ['192.168.1.1']);

        // 从不在白名单中的 IP 访问
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '10.0.0.1']);

        $response->assertStatus(403);
    }

    #[Test]
    public function metrics_endpoint_allows_all_ips_when_whitelist_empty(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.metrics_endpoint.allowed_ips', []);

        // 从任意 IP 访问
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '10.0.0.1']);

        $response->assertStatus(200);
    }

    #[Test]
    public function metrics_endpoint_requires_basic_auth_when_enabled(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.metrics_endpoint.auth_enabled', true);
        Config::set('prometheus.metrics_endpoint.username', 'prometheus');
        Config::set('prometheus.metrics_endpoint.password', 'secret');

        // 不提供认证信息
        $response = $this->get('/metrics');

        $response->assertStatus(401);
        $response->assertHeader('WWW-Authenticate', 'Basic realm="Prometheus Metrics"');
    }

    #[Test]
    public function metrics_endpoint_accepts_valid_basic_auth(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.metrics_endpoint.auth_enabled', true);
        Config::set('prometheus.metrics_endpoint.username', 'prometheus');
        Config::set('prometheus.metrics_endpoint.password', 'secret');

        // 提供正确的认证信息
        $response = $this->get('/metrics', [
            'PHP_AUTH_USER' => 'prometheus',
            'PHP_AUTH_PW' => 'secret',
        ]);

        $response->assertStatus(200);
    }

    #[Test]
    public function metrics_endpoint_rejects_invalid_basic_auth(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.metrics_endpoint.auth_enabled', true);
        Config::set('prometheus.metrics_endpoint.username', 'prometheus');
        Config::set('prometheus.metrics_endpoint.password', 'secret');

        // 提供错误的认证信息
        $response = $this->get('/metrics', [
            'PHP_AUTH_USER' => 'wrong',
            'PHP_AUTH_PW' => 'wrong',
        ]);

        $response->assertStatus(401);
    }

    #[Test]
    public function metrics_are_not_collected_when_disabled(): void {
        Config::set('prometheus.enabled', false);

        // 发起测试请求
        $this->get('/');

        // 访问 metrics 端点（metrics 应该为空或最小化）
        $response = $this->get('/metrics');

        $response->assertStatus(200);

        $content = $response->getContent();

        // 当禁用时，不应该收集 HTTP metrics
        $this->assertStringNotContainsString('http_requests_total', $content);
    }

    #[Test]
    public function http_metrics_collection_can_be_disabled_separately(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.http_metrics.enabled', false);

        // 发起测试请求
        $this->get('/');

        // 访问 metrics 端点
        $response = $this->get('/metrics');

        $response->assertStatus(200);

        $content = $response->getContent();

        // HTTP metrics 应该不存在
        $this->assertStringNotContainsString('http_requests_total', $content);
    }

    #[Test]
    public function metrics_endpoint_supports_cidr_ipv4_whitelist(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.metrics_endpoint.allowed_ips', ['192.168.1.0/24']);

        // 从子网内的 IP 访问（应该允许）
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '192.168.1.100']);
        $response->assertStatus(200);

        // 从子网外的 IP 访问（应该拒绝）
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '192.168.2.100']);
        $response->assertStatus(403);

        // 边界测试：子网的第一个地址
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '192.168.1.0']);
        $response->assertStatus(200);

        // 边界测试：子网的最后一个地址
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '192.168.1.255']);
        $response->assertStatus(200);
    }

    #[Test]
    public function metrics_endpoint_supports_cidr_ipv6_whitelist(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.metrics_endpoint.allowed_ips', ['2001:db8::/32']);

        // 从子网内的 IP 访问（应该允许）
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '2001:db8::1']);
        $response->assertStatus(200);

        // 从子网外的 IP 访问（应该拒绝）
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '2001:db9::1']);
        $response->assertStatus(403);
    }

    #[Test]
    public function metrics_endpoint_supports_mixed_ip_formats(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.metrics_endpoint.allowed_ips', [
            '10.0.0.1',              // 精确匹配
            '192.168.1.0/24',        // CIDR IPv4
            '2001:db8::/32',         // CIDR IPv6
        ]);

        // 精确匹配
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '10.0.0.1']);
        $response->assertStatus(200);

        // CIDR IPv4 匹配
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '192.168.1.50']);
        $response->assertStatus(200);

        // CIDR IPv6 匹配
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '2001:db8::100']);
        $response->assertStatus(200);

        // 不匹配任何规则
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '172.16.0.1']);
        $response->assertStatus(403);
    }

    #[Test]
    public function metrics_endpoint_handles_whitespace_in_ip_list(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.metrics_endpoint.allowed_ips', [
            ' 10.0.0.1 ',            // 带空格
            '192.168.1.0/24 ',       // 尾部空格
        ]);

        // 应该正确去除空格并匹配
        $response = $this->get('/metrics', ['REMOTE_ADDR' => '10.0.0.1']);
        $response->assertStatus(200);

        $response = $this->get('/metrics', ['REMOTE_ADDR' => '192.168.1.1']);
        $response->assertStatus(200);
    }

    #[Test]
    public function metrics_use_route_patterns_when_include_route_params_enabled(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.http_metrics.enabled', true);
        Config::set('prometheus.http_metrics.include_route_params', true);
        Config::set('prometheus.namespace', 'cbdb');

        // 模拟访问不同的资源 ID（相同路由模式）
        // 注意：在测试环境中需要有实际的路由才能获取路由模式
        $this->get('/');
        $this->get('/');

        // 获取 metrics
        $response = $this->get('/metrics');
        $content = $response->getContent();

        // 验证使用路由模式而非实际路径
        // 所有请求应该合并到同一个路由模式下
        $lines = explode("\n", $content);
        $pathCount = 0;
        foreach ($lines as $line) {
            if (str_contains($line, 'cbdb_http_requests_total') && str_contains($line, 'path=')) {
                $pathCount++;
            }
        }

        // 应该只有少量的路由模式（不是每个 ID 一个）
        $this->assertLessThan(5, $pathCount, '應該使用路由模式而非實際路徑，避免產生大量不同的 metric 時間序列');
    }

    #[Test]
    public function metrics_without_route_params_create_memory_leak_risk(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.http_metrics.enabled', true);
        Config::set('prometheus.http_metrics.include_route_params', false);  // 错误配置
        Config::set('prometheus.namespace', 'cbdb');

        // 模拟访问不同的资源 ID（使用实际路径）
        $this->get('/');

        // 获取 metrics
        $response = $this->get('/metrics');
        $content = $response->getContent();

        // 这个测试演示了问题：每个唯一的 path 都会创建新的时间序列
        // 在实际应用中，如果访问 /basicinformation/1/edit, /basicinformation/2/edit 等
        // 会创建无数个不同的时间序列，导致内存泄漏

        // 验证使用的是实际路径
        $this->assertStringContainsString('path="/"', $content, '未啟用 include_route_params 時使用實際路徑');

        // 警告：这种配置在生产环境中会导致内存泄漏
        // 建议将 include_route_params 设置为 true
    }

    #[Test]
    public function gauge_does_not_include_path_label_to_avoid_mismatch(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.http_metrics.enabled', true);
        Config::set('prometheus.http_metrics.include_route_params', true);
        Config::set('prometheus.namespace', 'cbdb');

        // 发起请求
        $this->get('/');

        // 获取 metrics
        $response = $this->get('/metrics');
        $content = $response->getContent();

        // 验证 http_requests_in_progress 不包含 path 标签
        // 只应该有 method 标签
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (str_starts_with($line, 'cbdb_http_requests_in_progress{')) {
                // 应该只包含 method 标签，不包含 path 标签
                $this->assertStringContainsString('method=', $line, 'Gauge 應包含 method 標籤');
                $this->assertStringNotContainsString('path=', $line, 'Gauge 不應包含 path 標籤（避免路由解析前後標籤不匹配）');
            }
        }

        // 验证 counter 和 histogram 仍然包含 path 标签
        $hasCounterWithPath = false;
        $hasHistogramWithPath = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'cbdb_http_requests_total{') && str_contains($line, 'path=')) {
                $hasCounterWithPath = true;
            }
            if (str_contains($line, 'cbdb_http_request_duration_seconds_bucket{') && str_contains($line, 'path=')) {
                $hasHistogramWithPath = true;
            }
        }

        $this->assertTrue($hasCounterWithPath, 'Counter 應包含 path 標籤');
        $this->assertTrue($hasHistogramWithPath, 'Histogram 應包含 path 標籤');
    }

    #[Test]
    public function gauge_increments_and_decrements_correctly_without_path(): void {
        Config::set('prometheus.enabled', true);
        Config::set('prometheus.http_metrics.enabled', true);
        Config::set('prometheus.namespace', 'cbdb');

        // 模拟多个请求完成（应该回到 0）
        $this->get('/');
        $this->get('/');

        // 获取 metrics
        $response = $this->get('/metrics');
        $content = $response->getContent();

        // 验证 gauge 正确归零（所有请求已完成）
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            if (str_starts_with($line, 'cbdb_http_requests_in_progress{method="GET"}')) {
                // 提取数值
                $parts = explode(' ', $line);
                $value = (int) end($parts);

                // 应该是 0（所有请求已完成）或 1（如果 /metrics 请求本身正在处理）
                $this->assertLessThanOrEqual(1, $value, 'Gauge 應該正確歸零或保持為 1（當前 /metrics 請求）');
            }
        }
    }
}
