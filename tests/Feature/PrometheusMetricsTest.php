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
}
