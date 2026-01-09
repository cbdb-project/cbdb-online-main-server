# Prometheus Metrics 整合指南

本文件說明如何配置和使用專案中的 Prometheus metrics 功能。

## 功能概述

本專案已整合 Prometheus metrics 收集功能，可以監控以下指標：

- **HTTP 請求總數** (`cbdb_http_requests_total`): 按方法、路徑和狀態碼分類的請求計數
- **HTTP 請求延遲** (`cbdb_http_request_duration_seconds`): 請求處理時間的直方圖分佈
- **正在處理的請求數** (`cbdb_http_requests_in_progress`): 當前正在處理的併發請求數量

## 快速開始

### 1. 基本配置

Metrics 功能預設已啟用。訪問以下端點即可查看 metrics：

```
http://your-domain.com/metrics
```

### 2. 環境變數配置

在 `.env` 檔案中可以配置以下選項：

```env
# 啟用/停用 Prometheus metrics
PROMETHEUS_ENABLED=true

# Metrics 命名空間（用於區分不同應用）
PROMETHEUS_NAMESPACE=cbdb

# 存儲適配器（memory/redis/apc）
PROMETHEUS_STORAGE_ADAPTER=memory

# HTTP metrics 啟用/停用
PROMETHEUS_HTTP_METRICS_ENABLED=true

# /metrics 端點的 IP 白名單（逗號分隔，留空表示允許所有 IP）
PROMETHEUS_ALLOWED_IPS=

# /metrics 端點的基本認證（可選）
PROMETHEUS_AUTH_ENABLED=false
PROMETHEUS_AUTH_USERNAME=prometheus
PROMETHEUS_AUTH_PASSWORD=secret
```

## 配置詳解

### 存儲適配器

#### Memory（預設）
- **適用場景**: 開發環境、單進程應用
- **優點**: 無需額外依賴
- **缺點**: 不支援多進程/多伺服器

#### Redis（推薦用於生產環境）
- **適用場景**: 生產環境、多進程/多伺服器部署
- **配置**:
  ```env
  PROMETHEUS_STORAGE_ADAPTER=redis
  PROMETHEUS_REDIS_HOST=127.0.0.1
  PROMETHEUS_REDIS_PORT=6379
  PROMETHEUS_REDIS_PASSWORD=
  PROMETHEUS_REDIS_DB=2
  ```
- **優點**: 支援多進程和分散式部署
- **缺點**: 需要 Redis 服務

#### APC
- **適用場景**: 多進程應用（如 PHP-FPM）
- **優點**: 快速、無需外部服務
- **缺點**: 不支援跨機器聚合

### 存取控制

#### IP 白名單

限制只有特定 IP 可以訪問 `/metrics` 端點：

```env
PROMETHEUS_ALLOWED_IPS=10.0.0.1,10.0.0.2,192.168.1.0/24
```

#### HTTP 基本認證

為 `/metrics` 端點添加密碼保護：

```env
PROMETHEUS_AUTH_ENABLED=true
PROMETHEUS_AUTH_USERNAME=prometheus
PROMETHEUS_AUTH_PASSWORD=your-secure-password
```

訪問時需要提供認證信息：
```bash
curl -u prometheus:your-secure-password http://your-domain.com/metrics
```

### 排除特定路徑

預設情況下，`/metrics` 端點本身不會被記錄。如需排除其他路徑，可在 `config/prometheus.php` 中配置：

```php
'http_metrics' => [
    'excluded_paths' => [
        '/metrics',
        '/health',
        '/ping',
    ],
],
```

### 延遲分布 Bucket

可以自定義延遲直方圖的 bucket（單位：秒）：

```php
'http_metrics' => [
    'latency_buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0],
],
```

## Prometheus 配置範例

在 Prometheus 伺服器的配置檔案中添加 scrape 配置：

```yaml
scrape_configs:
  - job_name: 'cbdb-laravel'
    scrape_interval: 15s
    static_configs:
      - targets: ['your-domain.com:80']
    metrics_path: '/metrics'

    # 如果啟用了基本認證
    basic_auth:
      username: 'prometheus'
      password: 'your-secure-password'
```

## 常見查詢範例

### Grafana Dashboard 查詢

#### 請求速率（QPS）
```promql
rate(cbdb_http_requests_total[5m])
```

#### 錯誤率
```promql
sum(rate(cbdb_http_requests_total{status=~"5.."}[5m])) / sum(rate(cbdb_http_requests_total[5m]))
```

#### 平均延遲
```promql
rate(cbdb_http_request_duration_seconds_sum[5m]) / rate(cbdb_http_request_duration_seconds_count[5m])
```

#### P95 延遲
```promql
histogram_quantile(0.95, rate(cbdb_http_request_duration_seconds_bucket[5m]))
```

#### 按路徑分組的請求數
```promql
sum by (path) (rate(cbdb_http_requests_total[5m]))
```

## 疑難排解

### Metrics 端點返回空白

1. 確認 `PROMETHEUS_ENABLED=true`
2. 確認至少有一個請求被處理過（訪問首頁等）
3. 檢查 IP 白名單配置

### Redis 連線錯誤

1. 確認 Redis 服務正在運行
2. 檢查 Redis 連線配置（主機、端口、密碼）
3. 確認 PHP Redis 擴展已安裝

### 性能影響

- Memory 適配器：幾乎無影響
- Redis 適配器：每個請求約增加 1-2ms 延遲
- 如需完全停用：設定 `PROMETHEUS_ENABLED=false`

## 擴展自定義 Metrics

如需添加自定義業務 metrics，可以注入 `CollectorRegistry`：

```php
use Prometheus\CollectorRegistry;

class YourController extends Controller {
    protected CollectorRegistry $registry;

    public function __construct(CollectorRegistry $registry) {
        $this->registry = $registry;
    }

    public function yourMethod() {
        // 計數器
        $counter = $this->registry->getOrRegisterCounter(
            'cbdb',
            'your_custom_metric',
            'Description of your metric',
            ['label1', 'label2']
        );
        $counter->inc(['value1', 'value2']);

        // 直方圖
        $histogram = $this->registry->getOrRegisterHistogram(
            'cbdb',
            'your_duration_metric',
            'Duration of your operation',
            ['label1'],
            [0.1, 0.5, 1.0, 5.0]
        );
        $histogram->observe($duration, ['value1']);
    }
}
```

## 測試

執行 Prometheus metrics 相關測試：

```bash
./vendor/bin/phpunit --filter PrometheusMetricsTest
```

## 相關文件

- [Prometheus PHP Client](https://github.com/promphp/prometheus_client_php)
- [Prometheus 文檔](https://prometheus.io/docs/)
- [PromQL 查詢語言](https://prometheus.io/docs/prometheus/latest/querying/basics/)
