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

        foreach ($allowedIps as $allowedIp) {
            // 去除空格
            $allowedIp = trim($allowedIp);

            // 检查是否为 CIDR 格式
            if (str_contains($allowedIp, '/')) {
                if ($this->ipMatchesCidr($clientIp, $allowedIp)) {
                    return true;
                }
            } else {
                // 精确匹配
                if ($clientIp === $allowedIp) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 检查 IP 是否匹配 CIDR 格式
     */
    protected function ipMatchesCidr(string $ip, string $cidr): bool {
        // 分离 IP 和前缀长度
        [$subnet, $prefixLength] = explode('/', $cidr);

        // 验证 IP 地址格式
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP)) {
            return false;
        }

        // 确定是 IPv4 还是 IPv6
        $isIpv4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isSubnetIpv4 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

        // IP 版本必须一致
        if ($isIpv4 !== $isSubnetIpv4) {
            return false;
        }

        if ($isIpv4) {
            return $this->ipv4MatchesCidr($ip, $subnet, (int) $prefixLength);
        } else {
            return $this->ipv6MatchesCidr($ip, $subnet, (int) $prefixLength);
        }
    }

    /**
     * 检查 IPv4 是否匹配 CIDR
     */
    protected function ipv4MatchesCidr(string $ip, string $subnet, int $prefixLength): bool {
        // 验证前缀长度
        if ($prefixLength < 0 || $prefixLength > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        // 创建子网掩码
        $mask = -1 << (32 - $prefixLength);

        // 比较网络地址部分
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * 检查 IPv6 是否匹配 CIDR
     */
    protected function ipv6MatchesCidr(string $ip, string $subnet, int $prefixLength): bool {
        // 验证前缀长度
        if ($prefixLength < 0 || $prefixLength > 128) {
            return false;
        }

        // 转换为二进制
        $ipBinary = inet_pton($ip);
        $subnetBinary = inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false) {
            return false;
        }

        // 计算需要比较的完整字节数和部分位数
        $fullBytes = (int) floor($prefixLength / 8);
        $remainingBits = $prefixLength % 8;

        // 比较完整字节
        if ($fullBytes > 0) {
            if (substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
                return false;
            }
        }

        // 比较剩余位
        if ($remainingBits > 0) {
            $mask = ~((1 << (8 - $remainingBits)) - 1);
            $ipByte = ord($ipBinary[$fullBytes]);
            $subnetByte = ord($subnetBinary[$fullBytes]);

            if (($ipByte & $mask) !== ($subnetByte & $mask)) {
                return false;
            }
        }

        return true;
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
