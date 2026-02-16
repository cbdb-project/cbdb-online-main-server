# 複合主鍵 URL 編碼架構設計

本文檔說明 CBDB 系統中複合主鍵（Composite Primary Key）的 URL 編碼問題分析與長期解決方案設計。

## 2026-02-17 現況快照（請先讀）

本文件早期章節以「設計提案」為主；目前專案已進入「部分落地、部分過渡」狀態，需先對齊以下現況：

1. 已落地：
- `App\Support\CompositePrimaryKey` 已存在，且查詢參數模式路由已在多個 BasicInformation 模組使用（`*.edit.query` / `*.update.query` / `*.destroy.query`）。
- `ALTNAME_DATA`、`BIOG_ADDR_DATA`、`BIOG_TEXT_DATA`、`ENTRY_DATA` 等模組已有查詢參數模式的 Controller 路徑與 URL 生成。

2. 過渡中：
- Resource 路由（path-based）仍保留，且部分前端表單仍直接提交到舊 resource 寫路徑。
- 目前確認仍走舊 resource 寫路徑（或保留回退）的表單：
  - `resources/views/biogmains/texts/_form.blade.php`
  - `resources/views/biogmains/addresses/_form.blade.php`
  - `resources/views/biogmains/altname/_form.blade.php`
  - `resources/views/biogmains/entries/_form.blade.php`

3. 閱讀提醒：
- 本文中部分範例仍使用舊命名（如 `basicinformation.altnames.edit`）作為示意；實際專案請以目前路由命名（含 `.query`）為準。

## 尚未完成的重構項目（與本文件直接相關）

1. 前端寫入入口收斂：
- 將上述 4 個表單全面切換為 query 路徑提交，移除或封存舊 resource 回退分支。

2. 路由收斂策略：
- 在確認無流量依賴後，逐步下線 `basicinformation.*` 的舊 path-based 寫入入口（至少先下線 update/store 的舊入口）。

3. 測試補齊：
- 針對 query 路徑的 store/update/destroy 增加對應 Feature Tests，並加入「舊入口是否仍被觸發」的回歸檢查。

4. 文件同步：
- 本文件的範例路由名稱與 `routes/web.php` 需維持一致（避免設計名詞與實作命名漂移）。

## 背景與問題

### 當前實作機制

系統使用 `unionPKDef()` 函數對複合主鍵中的特殊字符進行編碼，以便在 URL 路徑中安全傳遞：

```php
// app/Repositories/BiogMainRepository.php
public function unionPKDef($key) {
    $key = str_replace("/", "(slash)", $key);
    $key = str_replace("\\", "(backslash)", $key);
    $key = str_replace("{", "(brackets)", $key);
    $key = str_replace("}", "(brackets_r)", $key);
    return $key;
}
```

**編碼規則**：
- `/` → `(slash)`
- `\` → `(backslash)`
- `{` → `(brackets)`
- `}` → `(brackets_r)`
- **`-` 不編碼**（用作字段分隔符）

### 核心問題分析

#### 問題 1：編碼時機錯誤

當對整個複合主鍵字串進行編碼時，分隔符也會被誤編碼：

```php
// ❌ 錯誤：對整個複合主鍵字串編碼
$pk = "{$c_personid}-{$c_sequence}-{$c_alt_name_chn}";
$encoded = unionPKDef($pk);
// 結果：分隔符 - 可能與欄位值中的 - 混淆

// ✅ 正確：應該對每個欄位值分別編碼
$encoded = unionPKDef($c_personid) . '-' .
           unionPKDef($c_sequence) . '-' .
           unionPKDef($c_alt_name_chn);
```

#### 問題 2：欄位值包含分隔符時無法正確解析

當欄位值本身包含 `-` 時，會導致解析錯誤：

```php
// 範例：c_alt_name_chn = "張-三"
// resource_id = "12345-1-張-三-10"
// 使用 explode('-') 會分成 5 段而不是預期的 4 段！

// 實際案例：c_text_title 可能包含負號
// "論語-註釋" → 複合主鍵：12345-448-論語-註釋
// explode('-', ...) → ['12345', '448', '論語', '註釋']（錯誤！）
```

#### 問題 3：臨時補丁代碼複雜且易錯

`OperationsController.php` 中的特殊處理邏輯：

```php
// app/Http/Controllers/OperationsController.php:119-125
case "ALTNAME_DATA":
    $alt = str_replace("--", "-minus", $resource_id);
    $alt = $this->unionPKDef_decode($alt);
    $addr_l = explode("-", $alt);
    foreach ($addr_l as $key => $value) {
        $addr_l[$key] = str_replace("minus", "-", $value);
    }
    break;
```

這種「事後補丁」式的處理方式：
- 難以維護
- 容易產生新的邊界情況
- 對 AI 代理不友好
- 不符合「對稱編碼」原則

#### 問題 4：代碼重複

`unionPKDef()` 和 `unionPKDef_decode()` 在三處重複實作：
- `app/Repositories/BiogMainRepository.php`
- `app/Http/Controllers/OperationsController.php`
- `resources/views/biogmains/defense.blade.php`

### 影響範圍

**受影響的資料表**（複合主鍵）：

| 資料表 | 複合主鍵欄位 | 當前 URL 格式範例 |
|--------|-------------|------------------|
| `ALTNAME_DATA` | `c_personid`, `c_sequence`, `c_alt_name_chn`, `c_alt_name_type_code` | `/basicinformation/12345/altnames/12345-1-張三-10/edit` |
| `BIOG_ADDR_DATA` | `c_personid`, `c_addr_id`, `c_addr_type`, `c_sequence` | `/basicinformation/12345/addresses/12345-130-2-1/edit` |
| `TEXT_DATA` | `c_personid`, `c_text_id` | `/basicinformation/12345/texts/12345-448/edit` |
| `POSTED_TO_OFFICE_DATA` | `c_office_id`, `c_posting_id` | `/basicinformation/12345/offices/448-130/edit` |
| `POSTED_TO_ADDR_DATA` | `c_personid`, `c_posting_id`, `c_office_id` | 同上（共用編輯頁） |
| `BIOG_SOURCE_DATA` | `c_personid`, `c_source_id` | `/basicinformation/12345/sources/12345-448/edit` |
| `ASSOC_DATA` | `c_personid`, `c_assoc_id`, `c_assoc_code`, `c_assoc_year` | `/basicinformation/12345/assoc/{encoded}/edit` |

**受影響的程式碼模組**：
- Controllers（7 個）：`BasicInformationAltnamesController`, `BasicInformationAddressesController` 等
- Views（13+ 個 Blade 模板）
- Operations 模組（操作記錄的連結生成）

---

## 長期解決方案對比

### 方案 A：結構化編碼（JSON + Base64URL）

#### 實作原理

```php
namespace App\Support;

class CompositePrimaryKey {
    /**
     * 將複合主鍵編碼為 URL 安全的字串
     */
    public static function encode(array $fields): string {
        $json = json_encode($fields, JSON_UNESCAPED_UNICODE);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * 將編碼字串解碼為複合主鍵陣列
     */
    public static function decode(string $encoded): ?array {
        $json = base64_decode(strtr($encoded, '-_', '+/'));
        return json_decode($json, true);
    }
}
```

#### URL 範例

```
舊格式: /basicinformation/12345/altnames/12345-1-張三-10/edit

新格式: /basicinformation/12345/altnames/eyJjX3BlcnNvbmlkIjoxMjM0NSwiY19zZXF1ZW5jZSI6MSwiY19hbHRfbmFtZV9jaG4iOiLlvLDkuIkiLCJjX2FsdF9uYW1lX3R5cGVfY29kZSI6MTB9/edit
```

#### 優點

- ✅ **完全對稱**：編碼/解碼邏輯一致
- ✅ **支援任意字符**：無需額外處理特殊字符
- ✅ **支援複雜結構**：可處理巢狀、陣列等複雜主鍵

#### 缺點

- ❌ **URL 完全不可讀**：人工無法直接理解
- ❌ **編碼字串較長**：URL 長度增加
- ❌ **不利於調試**：無法在瀏覽器直接修改參數
- ❌ **AI 不友好**：AI 代理難以生成正確的編碼字串

---

### 方案 B：查詢參數模式（推薦 ⭐）

#### 實作原理

利用 HTTP 查詢參數（Query String）傳遞複合主鍵，完全依賴 Laravel 原生的 URL 編碼機制。

```php
// 路由定義
Route::get('basicinformation/{id}/altnames/edit',
    'BasicInformationAltnamesController@edit')
    ->name('basicinformation.altnames.edit');

// Controller
public function edit(Request $request, $id) {
    $pk = $request->only([
        'c_personid',
        'c_sequence',
        'c_alt_name_chn',
        'c_alt_name_type_code'
    ]);

    $altname = DB::table('ALTNAME_DATA')->where($pk)->first();
    // ...
}

// URL 生成
$url = route('basicinformation.altnames.edit', ['id' => 12345]) .
       '?' . http_build_query([
           'c_personid' => 12345,
           'c_sequence' => 1,
           'c_alt_name_chn' => '張-三',
           'c_alt_name_type_code' => 10,
       ]);
```

#### URL 範例

```
舊格式: /basicinformation/12345/altnames/12345-1-張-三-10/edit

新格式: /basicinformation/12345/altnames/edit?c_personid=12345&c_sequence=1&c_alt_name_chn=%E5%BC%B5-%E4%B8%89&c_alt_name_type_code=10
```

**實際顯示**（瀏覽器地址欄）：
```
/basicinformation/12345/altnames/edit?c_personid=12345&c_sequence=1&c_alt_name_chn=張-三&c_alt_name_type_code=10
```

#### 優點

- ✅ **語義清晰**：每個參數的含義一目了然
- ✅ **框架原生支援**：利用 Laravel 的 URL 編碼，無需自定義邏輯
- ✅ **零自定義編碼**：`http_build_query()` 自動處理所有特殊字符
- ✅ **AI 友好**：有利於 AI 代理生成和理解代碼
- ✅ **易於調試**：可在瀏覽器地址欄直接修改參數
- ✅ **RESTful 最佳實踐**：符合 REST API 設計標準
- ✅ **完全對稱**：`http_build_query()` 和 `$request->only()` 對稱
- ✅ **支援 NULL 值**：查詢參數可自然表達空值（省略參數）

#### 缺點

- ❌ **URL 結構變化較大**：需要修改路由和視圖
- ❌ **URL 稍長**（但可讀性補償了這一點）

---

## 推薦方案：方案 B + 漸進式遷移

### 選擇理由

1. **可維護性**：代碼清晰，減少 bug 風險
2. **符合標準**：RESTful API 的常見做法
3. **零自定義編碼**：避免 `unionPKDef()` 系列函數的複雜性
4. **團隊協作友好**：新成員和 AI 代理都能快速理解
5. **長期價值**：符合 Web 標準，未來擴展性強

### 方案對比總結

| 評估維度 | 方案 A（JSON+Base64） | 方案 B（查詢參數）⭐ |
|---------|---------------------|-------------------|
| URL 可讀性 | ❌ 完全不可讀 | ✅ 完全可讀 |
| 實作複雜度 | 🟡 中等（需自定義編碼） | ✅ 低（原生支援） |
| AI 友好度 | ❌ 不友好 | ✅ 非常友好 |
| 調試便利性 | ❌ 困難 | ✅ 容易 |
| 遷移成本 | 🟡 中等 | 🟡 中等 |
| 長期維護成本 | 🟡 中等 | ✅ 低 |
| 符合 Web 標準 | 🟡 部分符合 | ✅ 完全符合 |

---

## 實施計劃（5 個階段）

### 階段 1：建立輔助工具類（不破壞現有代碼）

建立 `app/Support/CompositePrimaryKey.php`：

```php
<?php

namespace App\Support;

use Illuminate\Http\Request;

class CompositePrimaryKey {
    /**
     * 從請求中提取複合主鍵
     *
     * @param Request $request
     * @param array $fields 主鍵欄位名稱列表
     * @return array
     */
    public static function fromRequest(Request $request, array $fields): array {
        return array_filter(
            $request->only($fields),
            fn($value) => $value !== null && $value !== ''
        );
    }

    /**
     * 生成帶查詢參數的 URL
     *
     * @param string $route 路由名稱
     * @param array $pathParams 路徑參數（如 ['id' => 12345]）
     * @param array $queryParams 查詢參數（複合主鍵欄位）
     * @param bool $absolute 是否生成絕對 URL（預設 false，避免 HTTPS 混合內容問題）
     * @return string
     */
    public static function buildUrl(
        string $route,
        array $pathParams,
        array $queryParams,
        bool $absolute = false
    ): string {
        $url = route($route, $pathParams, $absolute);
        $query = http_build_query($queryParams);
        return $query ? "{$url}?{$query}" : $url;
    }

    /**
     * 定義各表的複合主鍵欄位
     *
     * 注意：這些定義應與資料庫 schema 保持一致
     * 參考：database/migrations/2025_01_01_* baseline migrations
     */
    public const SCHEMAS = [
        'ALTNAME_DATA' => [
            'c_personid',
            'c_sequence',
            'c_alt_name_chn',
            'c_alt_name_type_code'
        ],
        'BIOG_ADDR_DATA' => [
            'c_personid',
            'c_addr_id',
            'c_addr_type',
            'c_sequence'
        ],
        'BIOG_TEXT_DATA' => [
            'c_personid',
            'c_textid',
            'c_role_id'
        ],
        'BIOG_SOURCE_DATA' => [
            'c_personid',
            'c_textid',
            'c_pages'
        ],
        'POSTED_TO_OFFICE_DATA' => [
            'c_office_id',
            'c_posting_id'
        ],
        'POSTED_TO_ADDR_DATA' => [
            'c_addr_id',
            'c_office_id',
            'c_posting_id'
            // ⚠️ 注意：數據庫主鍵無 c_personid，與 POSTED_TO_OFFICE_DATA 共用編輯頁面
        ],
        'ASSOC_DATA' => [
            'c_personid',
            'c_assoc_code',
            'c_assoc_id',
            'c_kin_code',
            'c_kin_id',
            'c_assoc_kin_code',
            'c_assoc_kin_id',
            'c_text_title',
            'c_assoc_first_year', // ⚠️ 当前代码中缺失此字段！
        ],
        'KIN_DATA' => [
            'c_personid',
            'c_kin_id',
            'c_kin_code'
        ],
        'EVENTS_DATA' => [
            'c_personid',
            'c_sequence'
            // ⚠️ 注意：數據庫沒有定義 PRIMARY KEY！需手動添加
        ],
        'STATUS_DATA' => [
            'c_personid',
            'c_sequence',
            'c_status_code'
        ],
        'ENTRY_DATA' => [
            'c_personid',
            'c_entry_code',
            'c_sequence',
            'c_kin_code',
            'c_assoc_code',
            'c_kin_id',
            'c_year',
            'c_assoc_id',
            'c_inst_code',
            'c_inst_name_code'
        ],
        'POSSESSION_DATA' => [
            'c_possession_record_id'
            // ✅ 單一主鍵，不是複合主鍵
        ],
        'BIOG_INST_DATA' => [
            'c_personid',
            'c_inst_code',
            'c_inst_name_code',
            'c_bi_role_code'
        ],
        'OFFICE_CODE_TYPE_REL' => [
            'c_office_id',
            'c_office_tree_id'
        ],
    ];

    /**
     * 取得指定資料表的主鍵欄位定義
     *
     * @param string $table 資料表名稱
     * @return array|null
     */
    public static function getSchema(string $table): ?array {
        return self::SCHEMAS[strtoupper($table)] ?? null;
    }

    /**
     * 驗證複合主鍵是否包含所有必要欄位
     *
     * @param array $pk 複合主鍵陣列
     * @param string $table 資料表名稱
     * @return bool
     */
    public static function validate(array $pk, string $table): bool {
        $schema = self::getSchema($table);
        if (!$schema) {
            return false;
        }

        foreach ($schema as $field) {
            // 允許某些欄位為 NULL（如 c_sequence）
            if (!isset($pk[$field]) && $pk[$field] !== null) {
                return false;
            }
        }

        return true;
    }
}
```

**預計時間**：0.5 天
**風險級別**：低

---

### 階段 2：新增路由（與舊路由並存）

更新 `routes/web.php`：

```php
// === ALTNAME_DATA ===
// 舊路由（保留兼容性）
Route::get('basicinformation/{id}/altnames/{pk}/edit',
    'BasicInformationAltnamesController@editLegacy')
    ->name('basicinformation.altnames.edit.legacy');

// 新路由（查詢參數模式，推薦使用）
Route::get('basicinformation/{id}/altnames/edit',
    'BasicInformationAltnamesController@edit')
    ->name('basicinformation.altnames.edit');

// === BIOG_ADDR_DATA ===
Route::get('basicinformation/{id}/addresses/{pk}/edit',
    'BasicInformationAddressesController@editLegacy')
    ->name('basicinformation.addresses.edit.legacy');

Route::get('basicinformation/{id}/addresses/edit',
    'BasicInformationAddressesController@edit')
    ->name('basicinformation.addresses.edit');

// === TEXT_DATA ===
Route::get('basicinformation/{id}/texts/{pk}/edit',
    'BasicInformationTextsController@editLegacy')
    ->name('basicinformation.texts.edit.legacy');

Route::get('basicinformation/{id}/texts/edit',
    'BasicInformationTextsController@edit')
    ->name('basicinformation.texts.edit');

// ... 其他複合主鍵表類似處理
```

**預計時間**：1 天
**風險級別**：低

---

### 階段 3：更新 Controller（支援雙模式）

以 `BasicInformationAltnamesController` 為範例：

```php
<?php

namespace App\Http\Controllers;

use App\Support\CompositePrimaryKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BasicInformationAltnamesController extends Controller {
    /**
     * 新的查詢參數模式（推薦）
     */
    public function edit(Request $request, $id) {
        // 從查詢參數提取複合主鍵
        $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 驗證必填欄位
        $required = ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'];
        foreach ($required as $field) {
            if (!isset($pk[$field])) {
                abort(400, "缺少必要參數：{$field}");
            }
        }

        // 查詢資料
        $altname = DB::table('ALTNAME_DATA')->where($pk)->first();

        if (!$altname) {
            abort(404, '別名記錄不存在');
        }

        $basicinformation = $this->biogMainRepository->byPersonId($id);
        $personLabel = $id . ' - ' . $basicinformation->c_name_chn;

        return view('biogmains.altname.edit', [
            'id' => $id,
            'row' => $altname,
            'basicinformation' => $basicinformation,
            'page_title' => '別名',
            'breadcrumbs' => [
                ['label' => '人物基本資料', 'url' => route('basicinformation.index')],
                ['label' => $personLabel, 'url' => route('basicinformation.edit', $id)],
                ['label' => '別名', 'url' => route('basicinformation.altnames.index', $id)],
                ['label' => '編輯', 'url' => '#'],
            ],
        ]);
    }

    /**
     * 舊的 path-based 模式（兼容性支援，自動重定向到新格式）
     *
     * 注意：此方法僅用於向後兼容，未來將移除
     */
    public function editLegacy($id, $pk) {
        // 解析舊格式的複合主鍵
        $parsed = $this->parseLegacyPK($pk);

        // 重定向到新的查詢參數格式
        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.altnames.edit',
            ['id' => $id],
            $parsed
        ), 301); // 301 永久重定向
    }

    /**
     * 解析舊格式的複合主鍵（臨時兼容性代碼）
     *
     * @deprecated 將在未來版本移除
     */
    private function parseLegacyPK(string $pk): array {
        // 處理特殊字符編碼
        $decoded = $this->biogMainRepository->unionPKDef_decode($pk);

        // 處理 -- 特殊情況（欄位值中包含負號）
        $decoded = str_replace("--", "-minus", $decoded);

        // 分割欄位
        $parts = explode("-", $decoded);

        // 還原 minus
        $parts = array_map(fn($p) => str_replace("minus", "-", $p), $parts);

        // 檢查欄位數量
        if (count($parts) < 4) {
            \Log::warning("無效的舊格式複合主鍵", [
                'pk' => $pk,
                'decoded' => $decoded,
                'parts' => $parts,
            ]);
            abort(400, '無效的複合主鍵格式');
        }

        return [
            'c_personid' => $parts[0],
            'c_sequence' => $parts[1] === 'NULL' ? null : $parts[1],
            'c_alt_name_chn' => $parts[2],
            'c_alt_name_type_code' => $parts[3],
        ];
    }

    /**
     * 更新別名記錄（同時支援新舊格式）
     */
    public function update(Request $request, $id, $pk = null) {
        // 如果 URL 路徑包含 $pk，表示使用舊格式，需要解析
        if ($pk !== null) {
            $pkArray = $this->parseLegacyPK($pk);
        } else {
            // 新格式：從查詢參數取得
            $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
            $pkArray = CompositePrimaryKey::fromRequest($request, $schema);
        }

        // 更新邏輯...
        DB::table('ALTNAME_DATA')
            ->where($pkArray)
            ->update($request->only([
                'c_alt_name',
                'c_notes',
                // ...
            ]));

        // 重定向到新格式的編輯頁面
        return redirect(CompositePrimaryKey::buildUrl(
            'basicinformation.altnames.edit',
            ['id' => $id],
            $pkArray
        ))->with('success', '別名更新成功');
    }
}
```

**預計時間**：2-3 天（需更新 7+ 個 Controller）
**風險級別**：中

---

### 階段 4：更新視圖連結生成

更新 `resources/views/biogmains/altname/index.blade.php`：

```blade
@php
use App\Support\CompositePrimaryKey;
@endphp

@extends('layouts.dashboard-v3')

@section('content')
<div class="card">
    <div class="card-body">
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>序號</th>
                    <th>別名（中）</th>
                    <th>別名（英）</th>
                    <th>類型</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                @foreach($basicinformation->altnames as $value)
                <tr>
                    <td>{{ $value->c_sequence }}</td>
                    <td>{{ $value->c_alt_name_chn }}</td>
                    <td>{{ $value->c_alt_name }}</td>
                    <td>{{ $value->c_alt_name_type_code }}</td>
                    <td>
                        {{-- 舊方式（逐步淘汰） --}}
                        {{-- @php
                            $pk = unionPKDef("{$value->c_personid}-{$value->c_sequence}-{$value->c_alt_name_chn}-{$value->c_alt_name_type_code}");
                        @endphp
                        <a href="/basicinformation/{{ $id }}/altnames/{{ $pk }}/edit">編輯</a> --}}

                        {{-- 新方式（推薦）✅ --}}
                        <a href="{{ CompositePrimaryKey::buildUrl(
                            'basicinformation.altnames.edit',
                            ['id' => $id],
                            [
                                'c_personid' => $value->c_personid,
                                'c_sequence' => $value->c_sequence,
                                'c_alt_name_chn' => $value->c_alt_name_chn,
                                'c_alt_name_type_code' => $value->c_alt_name_type_code,
                            ]
                        ) }}" class="btn btn-sm btn-primary">
                            <i class="fas fa-edit"></i> 編輯
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
```

**預計時間**：3-4 天（需更新 13+ 個視圖檔案）
**風險級別**：中

---

### 階段 5：Operations 模組適配

更新 `resources/views/operations/index.blade.php`：

```blade
@php
use App\Support\CompositePrimaryKey;

// 在生成 resourceSpecificLink 時使用新方法
$resourceLink = null;

switch ($a) {
    case "ALTNAME_DATA":
        $schema = CompositePrimaryKey::getSchema('ALTNAME_DATA');

        // 解析 resource_id（可能是舊格式）
        if (strpos($rawResourceId, '_._') !== false) {
            // CodesController 格式
            $parts = explode('_._', $rawResourceId);
        } else {
            // BasicInformation 格式
            $parts = explode('-', $rawResourceId);
        }

        if (count($parts) >= count($schema)) {
            $pk = array_combine($schema, array_slice($parts, 0, count($schema)));

            $resourceLink = CompositePrimaryKey::buildUrl(
                'basicinformation.altnames.edit',
                ['id' => $id],
                $pk
            );
        }
        break;

    case "BIOG_ADDR_DATA":
        $schema = CompositePrimaryKey::getSchema('BIOG_ADDR_DATA');
        $parts = explode('-', $rawResourceId);

        if (count($parts) >= count($schema)) {
            $pk = array_combine($schema, array_slice($parts, 0, count($schema)));

            $resourceLink = CompositePrimaryKey::buildUrl(
                'basicinformation.addresses.edit',
                ['id' => $id],
                $pk
            );
        }
        break;

    case "TEXT_DATA":
    case "BIOG_TEXT_DATA":
        $schema = CompositePrimaryKey::getSchema('TEXT_DATA');
        $parts = explode('-', $rawResourceId);

        if (count($parts) >= count($schema)) {
            $pk = array_combine($schema, array_slice($parts, 0, count($schema)));

            $resourceLink = CompositePrimaryKey::buildUrl(
                'basicinformation.texts.edit',
                ['id' => $id],
                $pk
            );
        }
        break;

    // ... 其他資料表類似處理
}
@endphp
```

**預計時間**：1-2 天
**風險級別**：中

---

## 遷移時間表

| 階段 | 任務 | 預計時間 | 風險級別 | 負責人 |
|-----|------|---------|---------|-------|
| 1 | 建立 `CompositePrimaryKey` 工具類 | 0.5 天 | 低 | - |
| 2 | 新增路由（保留舊路由） | 1 天 | 低 | - |
| 3 | 更新 Controller（支援雙模式） | 2-3 天 | 中 | - |
| 4 | 更新視圖連結生成 | 3-4 天 | 中 | - |
| 5 | Operations 模組適配 | 1-2 天 | 中 | - |
| 測試 | 全面測試新舊兩種模式 | 2-3 天 | - | - |
| 觀察期 | 線上驗證（保留舊路由） | 1-2 週 | 低 | - |
| 清理 | 移除舊路由和 legacy 方法 | 1 天 | 低 | - |

**總計**：約 2-3 週（實作時間）+ 1-2 週（觀察期）

---

## 風險控制

### 低風險點

- ✅ **新舊路由並存**：不破壞現有功能
- ✅ **自動重定向**：舊連結通過 `editLegacy()` 自動重定向到新格式
- ✅ **漸進式遷移**：可逐表、逐頁面測試和遷移
- ✅ **回滾容易**：如遇問題可快速切回舊路由

### 中等風險點

- ⚠️ **需要更新多個視圖檔案**：約 13 個表相關的視圖
- ⚠️ **Operations 模組邏輯複雜**：連結解析需要額外小心
- ⚠️ **舊資料相容性**：需確保歷史操作記錄的 `resource_id` 仍可正確解析

### 緩解措施

1. **自動化測試**：編寫 Feature Tests 覆蓋所有複合主鍵表的 CRUD 操作
2. **充分測試**：在開發環境充分測試後再部署到生產環境
3. **長期並存**：保留舊路由至少 1-2 個月，確保平穩過渡
4. **監控日誌**：記錄所有使用 `editLegacy()` 的請求，追蹤舊連結使用情況
5. **用戶通知**：在舊格式頁面頂部顯示提示訊息，鼓勵使用新連結

---

## 測試策略

### Unit Tests

```php
// tests/Unit/CompositePrimaryKeyTest.php

use App\Support\CompositePrimaryKey;
use Tests\TestCase;

class CompositePrimaryKeyTest extends TestCase {
    /** @test */
    public function it_can_get_schema_for_table() {
        $schema = CompositePrimaryKey::getSchema('ALTNAME_DATA');

        $this->assertEquals([
            'c_personid',
            'c_sequence',
            'c_alt_name_chn',
            'c_alt_name_type_code',
        ], $schema);
    }

    /** @test */
    public function it_builds_url_with_query_params() {
        $url = CompositePrimaryKey::buildUrl(
            'basicinformation.altnames.edit',
            ['id' => 12345],
            [
                'c_personid' => 12345,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張-三',
                'c_alt_name_type_code' => 10,
            ]
        );

        $this->assertStringContains('c_personid=12345', $url);
        $this->assertStringContains('c_alt_name_chn=', $url);
    }

    /** @test */
    public function it_validates_composite_key() {
        $pk = [
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ];

        $this->assertTrue(CompositePrimaryKey::validate($pk, 'ALTNAME_DATA'));
    }
}
```

### Feature Tests

```php
// tests/Feature/CompositePrimaryKeyRoutingTest.php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompositePrimaryKeyRoutingTest extends TestCase {
    /** @test */
    public function it_can_access_altname_edit_with_query_params() {
        $user = User::factory()->create(['is_active' => 1]);

        // 建立測試資料
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 12345,
            'c_name_chn' => '測試人物',
        ]);

        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張-三',
            'c_alt_name_type_code' => 10,
        ]);

        // 測試新格式 URL
        $response = $this->actingAs($user)->get(
            '/basicinformation/12345/altnames/edit?' . http_build_query([
                'c_personid' => 12345,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張-三',
                'c_alt_name_type_code' => 10,
            ])
        );

        $response->assertOk();
        $response->assertSee('張-三');
    }

    /** @test */
    public function legacy_url_redirects_to_new_format() {
        $user = User::factory()->create(['is_active' => 1]);

        // 測試舊格式 URL 自動重定向
        $response = $this->actingAs($user)->get(
            '/basicinformation/12345/altnames/12345-1-張三-10/edit'
        );

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContains('c_personid=12345', $location);
        $this->assertStringContains('altnames/edit?', $location);
    }

    /** @test */
    public function it_handles_special_characters_in_field_values() {
        $user = User::factory()->create(['is_active' => 1]);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 12345,
            'c_name_chn' => '測試人物',
        ]);

        // 測試欄位值中包含特殊字符
        DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張-三/李四{測試}',
            'c_alt_name_type_code' => 10,
        ]);

        $response = $this->actingAs($user)->get(
            '/basicinformation/12345/altnames/edit?' . http_build_query([
                'c_personid' => 12345,
                'c_sequence' => 1,
                'c_alt_name_chn' => '張-三/李四{測試}',
                'c_alt_name_type_code' => 10,
            ])
        );

        $response->assertOk();
        $response->assertSee('張-三/李四{測試}');
    }
}
```

---

## 未來清理計劃

### 何時移除舊路由？

當滿足以下條件時，可考慮移除舊路由和相關代碼：

1. ✅ 新路由在生產環境穩定運行 1-2 個月
2. ✅ 監控日誌顯示舊路由使用量 < 1%
3. ✅ 所有內部連結已更新為新格式
4. ✅ Operations 模組的歷史記錄連結已驗證正常

### Query 路徑遷移完成標準（新增）

下列條件全部成立，才可視為「複合主鍵 URL 重構完成」：

1. 所有 BasicInformation 寫操作表單（create/edit）預設只產生 query 路徑 URL。
2. Controller 的主要寫入邏輯不再依賴 path-based 複合主鍵字串解析。
3. `CompositePrimaryKey` 的 schema 定義、路由、Controller 驗證規則三者一致。
4. Feature Tests 覆蓋 query 路徑的 store/update/destroy 主流程與關鍵邊界值（含 `NULL` PK 片段）。
5. 舊 path-based 寫入口僅保留顯式兼容策略，並有可追蹤的下線計畫與日期。

### 移除清單

```php
// routes/web.php
// ❌ 移除所有 .legacy 路由
Route::get('basicinformation/{id}/altnames/{pk}/edit', '...')->name('...legacy');

// app/Http/Controllers/BasicInformationAltnamesController.php
// ❌ 移除 editLegacy() 方法
// ❌ 移除 parseLegacyPK() 方法

// app/Repositories/BiogMainRepository.php
// ❌ 移除 unionPKDef() 方法
// ❌ 移除 unionPKDef_decode() 方法

// app/Http/Controllers/OperationsController.php
// ❌ 移除 unionPKDef_decode() 方法

// resources/views/biogmains/defense.blade.php
// ❌ 移除所有 unionPKDef* 函數定義
```

---

## 附錄

### A. 相關 Issue 與 PR

- **Issue #718**：Select search 觸發 too many requests
- **PR #728**：去掉 300 requests/min 的 API 限流策略
- **PR #741**：修正複合主鍵 URL 編碼問題（本文檔的直接起因）

### B. 參考資料

- [Laravel Routing - Query String](https://laravel.com/docs/10.x/routing#parameters-and-query-strings)
- [PHP http_build_query()](https://www.php.net/manual/en/function.http-build-query.php)
- [RESTful API Design - Query Parameters](https://restfulapi.net/resource-naming/)
- [URL Encoding (Percent Encoding)](https://en.wikipedia.org/wiki/Percent-encoding)

### C. 13 個子頁面完整對比分析

本節詳細對比所有複合主鍵表的數據庫定義與代碼實際使用情況。

#### 對比總覽表

| # | 表名 | 數據庫字段數 | 代碼字段數 | 狀態 |
|---|------|------------|----------|------|
| 1 | ALTNAME_DATA | 3 | 4 | ❌ **嚴重不匹配** |
| 2 | BIOG_ADDR_DATA | 4 | 4 | ✅ 完全匹配 |
| 3 | BIOG_TEXT_DATA | 3 | 3 | ⚠️ 順序不同 |
| 4 | BIOG_SOURCE_DATA | 3 | 3 | ⚠️ 順序不同 + c_pages 風險 |
| 5 | POSTED_TO_OFFICE_DATA | 2 | - | ✅ 使用 Repository |
| 6 | POSTED_TO_ADDR_DATA | 3 | - | ⚠️ 數據庫無 c_personid |
| 7 | ASSOC_DATA | 9 | 9 | ✅ 已修正（2026-01-26） |
| 8 | KIN_DATA | 3 | 3 | ✅ 字段齊全 |
| 9 | EVENTS_DATA | 0 | 2 | ✅ 已修正（2026-01-26） |
| 10 | STATUS_DATA | 3 | 3 | ✅ 完全匹配 |
| 11 | ENTRY_DATA | 10 | 10 | ⚠️ 順序不同 |
| 12 | POSSESSION_DATA | 1 | 1 | ✅ 單一主鍵 |
| 13 | BIOG_INST_DATA | 4 | 4 | ✅ 已修正（2026-01-26） |

#### 詳細問題清單

##### ❌ 1. ALTNAME_DATA - 嚴重不匹配

**數據庫 PRIMARY KEY**（3 個字段）：
```sql
PRIMARY KEY (`c_alt_name_chn`, `c_alt_name_type_code`, `c_personid`)
```

**代碼實際使用**（4 個字段）：
```php
// BasicInformationAltnamesController.php:134
$resource_id = $data['c_personid'] . "-" .
               $data['c_sequence'] . "-" .        // ❌ 數據庫主鍵無此字段！
               $data['c_alt_name_chn'] . "-" .
               $data['c_alt_name_type_code'];
```

**問題**：
- 代碼多使用了 `c_sequence` 字段，但數據庫主鍵中沒有
- 數據庫主鍵無法保證 `(c_personid, c_sequence)` 的唯一性
- 可能允許同一人有多個相同別名（只要 sequence 不同）

**建議**：需要確認業務邏輯，決定是修改數據庫主鍵還是修改代碼。

---

##### ✅ 2. BIOG_ADDR_DATA - 完全正確

**數據庫定義**：
```sql
PRIMARY KEY (`c_personid`, `c_addr_id`, `c_addr_type`, `c_sequence`)
```

**代碼使用**：
```php
$resource_id = $data['c_personid'] . "-" .
               $data['c_addr_id'] . "-" .
               $data['c_addr_type'] . "-" .
               $data['c_sequence'];
```

✅ **完全匹配，無問題。**

---

##### ⚠️ 3. BIOG_TEXT_DATA - 順序不同

**數據庫定義**：
```sql
PRIMARY KEY (`c_personid`, `c_role_id`, `c_textid`)
```

**代碼使用**：
```php
$resource_id = $data['c_personid'] . "-" .
               $data['c_textid'] . "-" .      // ⚠️ 與數據庫順序不同
               $data['c_role_id'];
```

⚠️ 字段齊全但順序不同（功能正常，但不一致）。

---

##### ⚠️ 4. BIOG_SOURCE_DATA - c_pages 可能含特殊字符

**數據庫定義**：
```sql
PRIMARY KEY (`c_pages`, `c_personid`, `c_textid`)
```

**代碼使用**：
```php
// BasicInformationSourcesController.php:125
$_id = $data['c_personid'] . "-" .
       $data['c_textid'] . "-" .
       $data['c_pages'];              // ⚠️ VARCHAR(255)，可能含 -
```

**問題**：
- `c_pages` 是 VARCHAR 類型，可能包含 `-`、`/` 等特殊字符
- 存在與 ASSOC_DATA `c_text_title` 類似的解析風險

**測試重點**：需測試 `c_pages` 包含 `-` 的情況（如 "12-15"）。

---

##### ❌ 9. EVENTS_DATA - 數據庫缺失主鍵定義

**數據庫定義**：
```sql
-- ❌ 完全沒有 PRIMARY KEY 定義！
```

**代碼使用**：
```php
// BiogMainRepository.php:1406
$row = DB::table('EVENTS_DATA')
    ->where('c_personid', $id_arr[0])
    ->where('c_sequence', $id_arr[1])
    ->first();
```

**嚴重問題**：
- 數據庫表沒有 PRIMARY KEY，無法保證唯一性
- 可能出現重複記錄
- 查詢性能較差（無主鍵索引）

**緊急建議**：
```sql
ALTER TABLE EVENTS_DATA
ADD PRIMARY KEY (`c_personid`, `c_sequence`);
```

---

##### ✅ 13. BIOG_INST_DATA - 已修正（2026-01-26）

**數據庫定義**：
```sql
PRIMARY KEY (`c_bi_role_code`, `c_inst_code`, `c_inst_name_code`, `c_personid`)
```

**Store 方法**（✅ 正確）：
```php
// BiogMainRepository.php:1391
$newid = $data['c_personid'] . "-" .
         $data['c_inst_code'] . "-" .
         $data['c_inst_name_code'] . "-" .
         $data['c_bi_role_code'];
```

**Delete 方法**（✅ 已修正）：
```php
// BiogMainRepository.php - socialInstDeleteById()
// 修正後使用完整的 4 個字段：
$row = DB::table('BIOG_INST_DATA')
    ->where('c_personid', $addr_l[0])
    ->where('c_inst_code', $addr_l[1])
    ->where('c_inst_name_code', $addr_l[2])
    ->where('c_bi_role_code', $addr_l[3])
    ->first();
```

**修正內容**：Delete 方法現在使用完整的 4 個字段，與 Store 方法保持一致。

---

#### 嚴重問題優先級總結

**❌ 高優先級（數據完整性風險）**：
1. **ALTNAME_DATA** - 字段數不匹配（DB 3個 vs 代碼 4個）
2. **EVENTS_DATA** - 數據庫完全沒有主鍵定義
3. ~~**ASSOC_DATA** - 代碼缺少 `c_assoc_first_year` 字段~~ ✅ 已修正（2026-01-26）

**⚠️ 中優先級（代碼一致性問題）**：
4. ~~**BIOG_INST_DATA** - Delete 方法只用 2 個字段~~ ✅ 已修正（2026-01-26）
5. **BIOG_SOURCE_DATA** - `c_pages` 可能含特殊字符

**⚠️ 低優先級（僅順序不同）**：
6. **BIOG_TEXT_DATA**、**ENTRY_DATA** - 字段齊全但順序不同

---

### D. ASSOC_DATA 特殊說明

**✅ 已修正（2026-01-26）**：`ASSOC_DATA` 表的複合主鍵處理問題已修正。

**數據庫實際主鍵**（9 個字段）：
```sql
PRIMARY KEY (
    `c_assoc_code`,
    `c_assoc_id`,
    `c_assoc_kin_code`,
    `c_assoc_kin_id`,
    `c_kin_code`,
    `c_kin_id`,
    `c_personid`,
    `c_text_title`,
    `c_assoc_first_year`  -- ⚠️ 第 9 個字段
)
```

**代碼中使用的字段**（✅ 已修正為 9 個）：
```php
// BiogMainRepository.php - assocById(), assocUpdateById(), assocDeleteById(), assocStoreById()
// 修正後的複合主鍵格式：
$_id = $data['c_personid']."-".
       $data['c_assoc_code']."-".
       $data['c_assoc_id']."-".
       $data['c_kin_code']."-".
       $data['c_kin_id']."-".
       $data['c_assoc_kin_code']."-".
       $data['c_assoc_kin_id']."-".
       $data['c_text_title']."-".
       $data['c_assoc_first_year'];  // ✅ 已加入第 9 個字段
```

**已修正內容**（2026-01-26）：
- `assocById()` - 增加 `c_assoc_first_year` 到 WHERE 條件
- `assocUpdateById()` - 增加 `c_assoc_first_year` 到 WHERE 條件和操作記錄 ID
- `assocDeleteById()` - 增加 `c_assoc_first_year` 到 WHERE 條件
- `assocStoreById()` - 增加 `c_assoc_first_year` 到操作記錄 ID

**`c_text_title` 包含負號的處理**：
修正後的代碼能正確處理 `c_text_title` 包含 `-` 的情況：
```php
// 由於 c_assoc_first_year 是年份數字，固定在最後一個位置
// c_text_title 是從 index 7 到倒數第二個位置的所有部分（用 - 連接）
$c_assoc_first_year = count($temp_l) > 8 ? end($temp_l) : ($temp_l[8] ?? '-9999');
if (count($temp_l) > 9) {
    $c_text_title = implode('-', array_slice($temp_l, 7, count($temp_l) - 8));
} else {
    $c_text_title = $temp_l[7] ?? '';
}
```

**建議長期措施**：
- 採用查詢參數模式（已在 Controller 中實現），完全避免分隔符解析問題

### E. 聯絡資訊

如有疑問或建議，請聯絡：
- GitHub Issue: https://github.com/cbdb-project/cbdb-online-main-server/issues
- 相關討論：PR #741

---

**文檔版本**：1.3
**最後更新**：2026-02-17
**維護者**：CBDB 開發團隊

### 版本歷史

#### v1.3 (2026-02-17)
- 新增「2026-02-17 現況快照」，明確標註 query 路徑已部分落地、resource 寫入口仍在過渡期
- 補充尚未完成項目：前端表單寫入口收斂、路由下線策略、測試與文件同步
- 新增「Query 路徑遷移完成標準」，作為重構收斂判斷依據

#### v1.2 (2026-01-26)
- 修正 ASSOC_DATA：增加第 9 個欄位 `c_assoc_first_year` 到所有 Repository 方法
- 修正 BIOG_INST_DATA：`socialInstDeleteById()` 現在使用完整的 4 個欄位
- 更新對比總覽表和嚴重問題優先級總結

#### v1.1 (2026-01-23)
- 初始文檔
