<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

class MetricsController extends Controller {
    protected CollectorRegistry $registry;

    public function __construct(CollectorRegistry $registry) {
        $this->registry = $registry;
    }

    /**
     * 暴露 Prometheus metrics
     */
    public function index(Request $request): Response {
        // 检查 IP 白名单
        if (!$this->isAllowedIp($request)) {
            abort(403, 'Access denied');
        }

        // 检查基本认证
        if (!$this->checkBasicAuth($request)) {
            return response('Unauthorized', 401)
                ->header('WWW-Authenticate', 'Basic realm="Prometheus Metrics"');
        }

        // 渲染 metrics
        $renderer = new RenderTextFormat();
        $result = $renderer->render($this->registry->getMetricFamilySamples());

        return response($result, 200)
            ->header('Content-Type', RenderTextFormat::MIME_TYPE);
    }

    /**
     * 检查 IP 是否在白名单中
     */
    protected function isAllowedIp(Request $request): bool {
        $allowedIps = config('prometheus.metrics_endpoint.allowed_ips', []);

        // 空数组表示允许所有 IP
        if (empty($allowedIps)) {
            return true;
        }

        $clientIp = $request->ip();

        return in_array($clientIp, $allowedIps);
    }

    /**
     * 检查基本认证
     */
    protected function checkBasicAuth(Request $request): bool {
        // 如果未启用认证，直接返回 true
        if (!config('prometheus.metrics_endpoint.auth_enabled', false)) {
            return true;
        }

        $username = config('prometheus.metrics_endpoint.username');
        $password = config('prometheus.metrics_endpoint.password');

        // 获取 HTTP Basic Auth 凭据
        $providedUsername = $request->getUser();
        $providedPassword = $request->getPassword();

        return $providedUsername === $username && $providedPassword === $password;
    }
}
