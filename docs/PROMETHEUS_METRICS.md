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

# ⚠️ 重要：必須設為 true 以防止內存泄漏！
# 使用路由模式（如 /user/{id}）而非實際路徑（如 /user/123）
PROMETHEUS_INCLUDE_ROUTE_PARAMS=true

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

限制只有特定 IP 可以訪問 `/metrics` 端點。支援以下格式：

- **精確匹配**: `10.0.0.1`
- **CIDR IPv4**: `192.168.1.0/24`
- **CIDR IPv6**: `2001:db8::/32`
- **混合格式**: 可以同時使用多種格式，用逗號分隔

範例：

```env
# 單一 IP
PROMETHEUS_ALLOWED_IPS=10.0.0.1

# CIDR 格式
PROMETHEUS_ALLOWED_IPS=192.168.1.0/24

# 混合多個格式
PROMETHEUS_ALLOWED_IPS=10.0.0.1,192.168.1.0/24,2001:db8::/32
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

### 路由參數配置（重要：防止內存泄漏）

**⚠️ 關鍵配置**：`include_route_params` 必須設置為 `true` 以防止內存泄漏！

```env
# 在 .env 中設置（預設已為 true）
PROMETHEUS_INCLUDE_ROUTE_PARAMS=true
```

#### 為什麼這個配置如此重要？

當 `include_route_params = false` 時：
- ❌ 每個唯一的 URL 都會創建**獨立的 metric 時間序列**
- ❌ 例如：`/basicinformation/1/edit`、`/basicinformation/2/edit`、`/basicinformation/3/edit` 等
- ❌ 如果有 10,000 個不同的資源 ID，就會創建 10,000+ 個不同的時間序列
- ❌ 每個時間序列包含 Counter、Histogram（14 個 bucket）、Gauge
- ❌ **內存持續增長，最終導致 OOM (Out of Memory) 錯誤**

當 `include_route_params = true` 時（**推薦**）：
- ✅ 所有相同路由的請求會**合併到同一個時間序列**
- ✅ 例如：所有 `/basicinformation/{personid}/edit` 請求共享同一個 metric
- ✅ 內存占用固定且可預測
- ✅ Metrics 數據更有意義（按路由聚合而非按個別資源）

#### Metrics 路徑示例對比

| include_route_params | 實際 URL | Metric 中的 path 標籤 | 內存影響 |
|---------------------|---------|---------------------|---------|
| `false` ❌ | `/basicinformation/1/edit` | `path="/basicinformation/1/edit"` | 每個 ID 創建新時間序列 |
| `false` ❌ | `/basicinformation/2/edit` | `path="/basicinformation/2/edit"` | 無限增長 💥 |
| `true` ✅ | `/basicinformation/1/edit` | `path="/basicinformation/{personid}/edit"` | 固定數量 |
| `true` ✅ | `/basicinformation/2/edit` | `path="/basicinformation/{personid}/edit"` | 所有請求共享同一時間序列 |

#### 內存泄漏影響評估

假設網站有：
- 10,000 個 person 記錄
- 每個被訪問 1 次
- 每個 URL 創建 3 種 metric（Counter + Histogram + Gauge）
- Histogram 有 14 個 bucket

**錯誤配置的內存占用**：
```
10,000 個唯一 URL × (1 Counter + 14 Histogram buckets + 1 Gauge)
= 160,000+ 個內存對象
≈ 數百 MB 甚至 GB 內存 💥
```

**正確配置的內存占用**：
```
固定數量的路由（例如 50 個） × (1 Counter + 14 Histogram buckets + 1 Gauge)
= 800 個內存對象
≈ 幾 MB 內存 ✅
```

#### 如何驗證配置是否正確

訪問 `/metrics` 端點並檢查輸出：

**❌ 錯誤（內存泄漏）**：
```
cbdb_http_requests_total{method="GET",path="/basicinformation/1/edit",status="200"} 1
cbdb_http_requests_total{method="GET",path="/basicinformation/2/edit",status="200"} 1
cbdb_http_requests_total{method="GET",path="/basicinformation/3/edit",status="200"} 1
...（成千上萬行）
```

**✅ 正確（內存可控）**：
```
cbdb_http_requests_total{method="GET",path="/basicinformation/{personid}/edit",status="200"} 3
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

### 內存不足錯誤（500 錯誤 / Memory Allocation Failed）

**症狀**：
- 應用程式頻繁返回 500 錯誤
- 日誌顯示「Allowed memory size exhausted」或「Failed to allocate memory」
- `/metrics` 端點返回非常大的響應（幾 MB 以上）

**原因**：
- `include_route_params` 配置為 `false`，導致每個唯一 URL 創建獨立的 metric 時間序列

**解決方案**：
1. 檢查配置：
   ```bash
   grep -r "include_route_params" config/prometheus.php
   ```

2. 確認 `.env` 中設置：
   ```env
   PROMETHEUS_INCLUDE_ROUTE_PARAMS=true
   ```

3. 清除現有 metrics（重啟應用或清除 Redis）：
   ```bash
   # 如果使用 Memory 適配器
   php artisan optimize:clear

   # 如果使用 Redis 適配器
   redis-cli KEYS "PROMETHEUS_*" | xargs redis-cli DEL
   ```

4. 驗證修復：
   ```bash
   # 訪問幾個不同的資源頁面
   curl http://your-domain.com/basicinformation/1/edit
   curl http://your-domain.com/basicinformation/2/edit

   # 檢查 metrics 是否合併到同一路由模式
   curl http://your-domain.com/metrics | grep "basicinformation"

   # 應該看到：path="/basicinformation/{personid}/edit"
   # 而不是：path="/basicinformation/1/edit" 和 path="/basicinformation/2/edit"
   ```

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

### 可靠性與異常處理

Metrics 收集系統經過強化設計，確保即使在異常情況下也能正確運作：

#### 異常時的 Metrics 記錄

- 使用 `try/finally` 結構確保即使請求處理過程中發生異常，仍能正確：
  - 遞減 `http_requests_in_progress` gauge（避免計數器洩漏）
  - 記錄錯誤請求的 metrics（包含狀態碼和延遲）
- 未被捕獲的異常會被記錄為 500 錯誤
- HTTP 異常（如 404、403）會正確記錄其狀態碼

#### 錯誤隔離

- Metrics 收集過程中的錯誤**不會影響**正常的 HTTP 請求處理
- 所有 metrics 操作都包含異常捕獲，失敗時會通過 `report()` 記錄但不會中斷請求

#### IP 白名單的容錯性

- 支援 CIDR 格式（IPv4 和 IPv6）
- 自動去除空格，容忍格式錯誤
- 無效的 CIDR 格式會被安全忽略

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
