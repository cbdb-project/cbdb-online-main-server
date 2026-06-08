# All Tables 欄位過濾與排序功能 — 工作計劃

**分支：** `feature/codes-table-filter-sort`  
**建立日期：** 2026-06-08  
**目標：**  
1. 為 `/codes/{table}` 列表頁的每一個欄（column）加入獨立的欄位過濾（filter）與排序（sort）功能。  
2. 為 `/codes`（All Tables 首頁）加入客戶端即時搜尋與欄位排序功能，方便使用者在 81 個表格中快速定位。

---

## 1. 目前狀態

`/codes/{table_name}` 列表頁（共用 `resources/views/codes/show.blade.php` + `CodesController::show()`）目前只有：

- **全文搜尋**：`?search=keyword`，對所有可搜尋欄做 `LIKE '%keyword%'`，無法針對特定欄篩選。
- **排序**：無，資料庫預設順序。
- **分頁**：標準 offset 分頁，或大表（`CBDB__NAME_FTS`）使用游標分頁。

### 1.1 現有 `show()` 方法關鍵流程（實作者必讀）

```
show($table_name)
  ├─ guardTable()                          ← 驗證表名白名單
  ├─ 取得 $joinConfig（JOIN 配置，若有）
  ├─ buildJoinQuery() 或 DB::table()      ← 建立 $query
  ├─ buildTableHead()                     ← 建立 $thead（欄位名陣列）
  ├─ determineSearchableColumns()
  ├─ 套用 $search（全文搜尋，OR 邏輯）
  ├─ $query->paginate()                  ← ★ 分頁在此
  ├─ getKeyColumns()                     ← ⚠ 目前在 paginate() 之後
  ├─ getDynastyNameMap()
  └─ return view('codes.show', [...])
```

> **重要**：實作時必須把 `getKeyColumns()` 移到 `paginate()` **之前**（搭配 filter/sort/tie-breaker 使用）。見第 4.1 節。

### 1.2 JOIN 表的查詢結構（實作者必讀）

對有 JOIN 配置的表，`buildJoinQuery()` 產生的 query 形如：

```sql
SELECT rel.*, code.c_appt_desc_chn AS appt_name, type.c_appt_type_desc_chn AS appt_type_name
FROM APPOINTMENT_CODE_TYPE_REL AS rel
LEFT JOIN APPOINTMENT_CODES AS code ON rel.c_appt_code = code.c_appt_code
LEFT JOIN APPOINTMENT_TYPES AS type ON rel.c_appt_type_code = type.c_appt_type_code
```

因此 `sampleRow` 的 keys 同時包含：
- base table 的所有原始欄位（如 `c_appt_code`、`c_appt_type_code`）— 來自 `rel.*`
- JOIN alias 欄位（如 `appt_name`、`appt_type_name`）— 來自 select 表達式

由於存在 JOIN，對任何欄位做 `orderBy('c_appt_code', ...)` 或 `where('c_appt_code', ...)` 都會造成 **ambiguous column** 錯誤。所有欄位傳入 DB query 時都必須帶表別名前綴（如 `rel.c_appt_code`），這是 `resolveColumnForQuery()` 的核心職責。

---

## 2. 目標功能設計

### 2.1 欄位過濾（Column Filter）

- 在 `<thead>` 每一欄標題下方加一列 `<tr class="filter-row">`，每格放一個 `<input type="text">`。
- 使用者填入關鍵字後，按 Apply Filters 按鈕提交，後端對該欄做 `LIKE '%value%'` 篩選（AND 邏輯）。
- URL 參數格式：`?filters[c_name_chn]=張&filters[c_dy]=1`

### 2.2 欄位排序（Column Sort）— 三態

- 每個欄標題加入可點擊排序連結，圖示顯示目前狀態：
  - `⇅`（未排序）→ 點擊 → `▲`（ASC）→ 再點 → `▼`（DESC）→ 再點 → 取消排序回到 `⇅`
- URL 參數格式：`?sort_by=c_name_chn&sort_dir=asc`（取消排序時不帶 `sort_by`）
- 排序狀態與分頁、過濾一起保留在 URL。

### 2.3 已有功能保留

- 全文搜尋 `?search=` 維持不動（兩者並存，可同時使用）。
- 分頁連結自動帶入目前的 filter + sort 狀態。
- **游標分頁（`CBDB__NAME_FTS`）：不加 filter/sort，`showWithCursorPagination()` 方法完全不動。** 游標分頁路徑的 URL 組裝（`array_merge([...], $search ? ['search' => $search] : [], ...)）`也不需要更新。
- JOIN 表：欄位過濾需對應到正確的表別名前綴（見 `resolveColumnForQuery()`）。

---

## 3. 涉及的 8 個側邊欄表格

| 表名 | 說明 | 有 JOIN 配置 |
|------|------|:-----------:|
| `ADDR_BELONGS_DATA` | 地址隸屬關係資料表 | 否 |
| `ADDR_CODES` | 地址代碼表 | 否 |
| `ALTNAME_CODES` | 別名類型代碼表 | 否 |
| `APPOINTMENT_CODES` | 任命類型代碼表 | 否（`APPOINTMENT_CODE_TYPE_REL` 才有 JOIN） |
| `OFFICE_CODES` | 官職代碼表 | 否 |
| `SOCIAL_INSTITUTION_CODES` | 社會機構代碼表 | 否 |
| `TEXT_CODES` | 文獻代碼表 | 否 |
| `TEXT_INSTANCE_DATA` | 文獻版本資料表 | 否 |

> 注意：這 8 個表本身均無 JOIN 配置，但 `CodesController::show()` 支援的所有 81 個表（含有 JOIN 的）都走同一個 view，因此後端設計必須對 JOIN 表也正確工作，才可 merge。

---

## 4. 技術實作細節

### 4.1 後端：`CodesController::show()` 修改

**新增 query params：**
```
GET /codes/{table}?sort_by=c_name_chn&sort_dir=asc&filters[c_dy]=1&search=keyword
```

**修改後的 `show()` 執行順序（完整偽代碼）：**

```php
public function show(Request $request, $table_name)
{
    $table      = $this->guardTable($table_name);
    $search     = trim((string) $request->query('search', ''));
    $upperTable = strtoupper($table);
    $perPage    = config('codes.per_page', 20);

    // JOIN 配置（若有）
    $joinConfig = $this->tableJoinConfigurations[$upperTable] ?? null;
    $query = $joinConfig
        ? $this->buildJoinQuery($joinConfig)
        : DB::table($table);

    // 欄位清單
    $sampleRow = (clone $query)->first();
    $thead = $this->buildTableHead($table, $sampleRow, $joinConfig);
    $searchableColumns = $this->determineSearchableColumns($table, $thead);

    // ★ 提前取得主鍵（必須在 paginate 之前，因為 tie-breaker 排序需要用到）
    $keyColumns = $this->getKeyColumns($table);

    // 游標分頁的大表：不加 filter/sort，走原有分支
    $useCursorPagination = in_array($upperTable, ['CBDB__NAME_FTS'], true);
    if ($useCursorPagination) {
        // 全文搜尋仍然套用（現有邏輯不動）
        if ($search !== '' && !empty($searchableColumns)) { /* 現有邏輯 */ }
        return $this->showWithCursorPagination($request, $table, $query, $search, $perPage, $thead);
    }

    // ──────────────────────────────────────────────
    // 1. 讀取並驗證 filter 參數
    // ──────────────────────────────────────────────
    $rawFilters = $request->query('filters', []);
    if (!is_array($rawFilters)) {
        $rawFilters = [];
    }
    // 只保留 key 在 $thead 白名單內的項目，value 必須是 scalar
    $filters = [];
    foreach ($rawFilters as $col => $val) {
        if (in_array($col, $thead, true) && is_scalar($val)) {
            $filters[$col] = trim((string) $val);
        }
    }

    // ──────────────────────────────────────────────
    // 2. 讀取並驗證 sort 參數
    // ──────────────────────────────────────────────
    $sortBy  = $request->query('sort_by', '');
    $sortDir = strtolower((string) $request->query('sort_dir', 'asc'));
    if (!in_array($sortDir, ['asc', 'desc'], true)) {
        $sortDir = 'asc';
    }
    // sort_by 必須在 $thead 白名單內
    if (!in_array($sortBy, $thead, true)) {
        $sortBy = '';
    }

    // ──────────────────────────────────────────────
    // 3. 全文搜尋（現有邏輯不動，OR 邏輯）
    // ──────────────────────────────────────────────
    if ($search !== '' && !empty($searchableColumns)) {
        $query->where(function ($subQuery) use ($searchableColumns, $search, $joinConfig) {
            foreach ($searchableColumns as $column) {
                $searchColumn = $this->resolveColumnForQuery($column, $joinConfig);
                if ($searchColumn === null) {
                    continue;  // ← null check 必須加
                }
                $subQuery->orWhere($searchColumn, 'like', '%' . $search . '%');
            }
        });
    }

    // ──────────────────────────────────────────────
    // 4. 欄位過濾（AND 邏輯）
    // ──────────────────────────────────────────────
    foreach ($filters as $column => $value) {
        if ($value === '') {
            continue;
        }
        $searchColumn = $this->resolveColumnForQuery($column, $joinConfig);
        if ($searchColumn === null) {
            continue;  // ← null check 必須加
        }
        $query->where($searchColumn, 'like', '%' . $value . '%');
    }

    // ──────────────────────────────────────────────
    // 5. 排序 + 主鍵 tie-breaker（必須在 paginate 之前）
    // ──────────────────────────────────────────────
    if ($sortBy !== '') {
        $sortColumn = $this->resolveColumnForQuery($sortBy, $joinConfig);
        if ($sortColumn !== null) {
            $query->orderBy($sortColumn, $sortDir);
        }
    }
    // 永遠補主鍵作穩定次排序，防止非唯一欄位分頁飄移
    foreach ($keyColumns as $pkCol) {
        $pkSortExpr = $this->resolveColumnForQuery($pkCol, $joinConfig);
        if ($pkSortExpr !== null) {
            $query->orderBy($pkSortExpr, 'asc');
        }
    }

    // ──────────────────────────────────────────────
    // 6. 分頁（appends 使用 $request->except('page') 更穩健）
    // ──────────────────────────────────────────────
    // 不要手動列出 ['search', 'sort_by', 'sort_dir', 'filters']，
    // 改用 withQueryString() 或 appends($request->except('page'))，
    // 避免日後新增 query param 時漏掉，且 filters 的巢狀結構也能正確保留。
    $data = $query->paginate($perPage)->appends($request->except('page'));

    // ──────────────────────────────────────────────
    // 7. 其餘資料（朝代 map、isReadOnly 等，現有邏輯不動）
    // ──────────────────────────────────────────────
    $dynastyMap    = in_array('c_dy', $thead, true) ? $this->getDynastyNameMap() : [];
    $isReadOnly    = $this->isReadOnlyTable($table);
    $copyrightNote = $this->tableCopyrightNotes[$table] ?? null;
    $joinedColumns = $joinConfig ? $this->getJoinedColumnNames($joinConfig) : [];

    return view('codes.show', [
        'page_title'    => $table,
        'page_description' => '',
        'page_url'      => '/codes',
        'archer'        => "<li class='breadcrumb-item'><a href='/codes'>全部表格</a></li>",
        'q'             => $table,
        'thead'         => $thead,
        'data'          => $data,
        'search'        => $search,
        'dynastyMap'    => $dynastyMap,
        'isReadOnly'    => $isReadOnly,
        'keyColumns'    => $keyColumns,
        'copyrightNote' => $copyrightNote,
        'joinedColumns' => $joinedColumns,
        // ★ 新增變數
        'filters'       => $filters,
        'sortBy'        => $sortBy,
        'sortDir'       => $sortDir,
    ]);
}
```

---

### 4.2 新增私有方法 `resolveColumnForQuery()`

**方法簽章：**
```php
protected function resolveColumnForQuery(string $column, ?array $joinConfig): ?string
```

**完整演算法（三段嚴格判斷，逐條必須實作）：**

```php
protected function resolveColumnForQuery(string $column, ?array $joinConfig): ?string
{
    // ──────────────────────────────────────────────
    // 情境 A：非 JOIN 表，直接回傳欄位名（原樣，無 ambiguous 風險）
    // ──────────────────────────────────────────────
    if ($joinConfig === null) {
        return $column;
    }

    $baseAlias   = $joinConfig['base_alias'];        // e.g. 'rel'
    $baseTable   = $joinConfig['base_table'];        // e.g. 'APPOINTMENT_CODE_TYPE_REL'
    $selectList  = $joinConfig['select'] ?? [];      // e.g. ['rel.c_appt_code', 'code.c_appt_desc_chn as appt_name']

    // ──────────────────────────────────────────────
    // 情境 B：欄位名是 JOIN alias（e.g. 'appt_name'）
    // → 用大小寫不敏感、錨定結尾的 regex 比對 selectList
    //   不要用 strpos(' as '.$column)，太脆弱（e.g. 'appt_name' 會錯誤匹配 'appt_name_chn'）
    // ──────────────────────────────────────────────
    foreach ($selectList as $selectExpr) {
        // 比對 "... as {$column}"（$column 錨定在字串結尾，大小寫不敏感）
        if (preg_match('/\s+as\s+' . preg_quote($column, '/') . '\s*$/i', $selectExpr)) {
            // 提取 "as" 之前的原始表達式
            $parts = preg_split('/\s+as\s+/i', $selectExpr, 2);
            if (count($parts) === 2) {
                return trim($parts[0]); // e.g. 'code.c_appt_desc_chn'
            }
        }
    }

    // ──────────────────────────────────────────────
    // 情境 C：欄位名是 base table 的真實欄位（e.g. 'c_appt_code'）
    // → 必須先確認欄位確實存在於 base table 的 schema，才補前綴
    //   不能「沒有 dot 就全部補 baseAlias」，否則任何 $thead 裡的字串都會被接受
    // ──────────────────────────────────────────────
    if (!str_contains($column, '.')) {
        $baseTableColumns = $this->getTableColumns($baseTable); // 查 DB schema
        if (in_array($column, $baseTableColumns, true)) {
            return $baseAlias . '.' . $column; // e.g. 'rel.c_appt_code'
        }
        // 不在 base table schema → 來源不明，回傳 null
        return null;
    }

    // ──────────────────────────────────────────────
    // 情境 D：欄位名已帶 prefix（防禦性處理，正常不應進入）
    // ──────────────────────────────────────────────
    // return null; ← 保守做法：已帶 prefix 的來源不明，拒絕
    return null;
}
```

> **關鍵設計差異**：情境 C 改為先用 `getTableColumns($baseTable)` 確認欄位真實存在於 base table schema 後才補前綴。若直接「沒有 dot 就全補 baseAlias」，則 null return 幾乎不會發生，安全防線形同虛設。
>
> **注意**：`getTableColumns()` 已存在於 `CodesController`（第 848 行），可直接呼叫，無需新增。

---

### 4.3 前端：`codes/show.blade.php` 修改

#### 4.3.1 HTML 結構（重要：`<form>` 必須在 `<table>` 外）

> `<form>` 不能放在 `<tr>` 裡（無效 HTML，瀏覽器會重排 DOM）。
> 正確做法：把 `<form id="filter-form">` 放在 `<table>` **外部**（table 上方），所有 input 用 `form="filter-form"` 屬性關聯到此 form。

```blade
{{-- ① filter-form：放在 .table-responsive div 之前（table 外部）--}}
<form method="GET"
      action="{{ route('codes.show', ['table_name' => $q]) }}"
      id="filter-form">
    {{-- 保留全文搜尋狀態（使用者在 filter 欄送出時，不清除 search） --}}
    <input type="hidden" name="search"   value="{{ $search ?? '' }}">
    {{-- 保留排序狀態 --}}
    <input type="hidden" name="sort_by"  value="{{ $sortBy ?? '' }}">
    <input type="hidden" name="sort_dir" value="{{ $sortDir ?? 'asc' }}">
    {{-- 這個 form 的 input 欄位本體在 table <thead> 的第二列 --}}
</form>
```

#### 4.3.2 全文搜尋 `<form>` 修改（保留 filter/sort 狀態）

現有搜尋 form（`show.blade.php` 第 22-36 行）只送 `search`，提交後會清掉 filter/sort。需要補 hidden inputs：

```blade
<form method="GET" action="{{ route('codes.show', ['table_name' => $q]) }}" style="flex: 0 0 auto; margin: 0;">
    <div class="input-group input-group-sm" style="width: 420px;">
        <input type="text" name="search" class="form-control"
               placeholder="{{ __('common.search') }}"
               value="{{ $search ?? '' }}">
        <div class="input-group-append">
            <button class="btn btn-secondary" type="submit">{{ __('common.search') }}</button>
            @if(!empty($search))
                <a class="btn btn-secondary" href="{{ route('codes.show', ['table_name' => $q]) }}">{{ __('common.reset') }}</a>
            @endif
        </div>
    </div>
    {{-- ★ 新增：保留 filter/sort 狀態，讓搜尋不清除欄位過濾 --}}
    @if(!empty($sortBy))
        <input type="hidden" name="sort_by"  value="{{ $sortBy }}">
        <input type="hidden" name="sort_dir" value="{{ $sortDir ?? 'asc' }}">
    @endif
    @foreach(($filters ?? []) as $col => $val)
        @if($val !== '')
            <input type="hidden" name="filters[{{ $col }}]" value="{{ $val }}">
        @endif
    @endforeach
</form>
```

#### 4.3.3 Apply Filters 按鈕與 Clear Filters 連結

緊接在搜尋 form 後面、Add 按鈕之前加入：

```blade
{{-- Apply Filters 按鈕（關聯到 filter-form，文字走 i18n）--}}
<button type="submit" form="filter-form" class="btn btn-sm btn-primary">
    {{ __('codes.apply_filters') }}
</button>

{{-- Clear Filters 連結：清除 filters + sort，但保留 search（文字走 i18n）--}}
@if(!empty($filters) || !empty($sortBy))
    <a class="btn btn-sm btn-secondary"
       href="{{ route('codes.show', array_filter(['table_name' => $q, 'search' => $search])) }}">
        {{ __('codes.clear_filters') }}
    </a>
@endif
```

> `array_filter(['table_name' => $q, 'search' => $search])` 在 `$search` 為空字串時會自動過濾掉，保持 URL 乾淨。

#### 4.3.4 欄標題：排序連結（三態邏輯）

```blade
@foreach ($thead as $item)
    @php
        // 三態排序邏輯
        if ($sortBy !== $item) {
            // 目前未依此欄排序 → 點擊後 ASC
            $nextSortParams = ['sort_by' => $item, 'sort_dir' => 'asc'];
            $icon = '⇅';
        } elseif ($sortDir === 'asc') {
            // 目前 ASC → 點擊後 DESC
            $nextSortParams = ['sort_by' => $item, 'sort_dir' => 'desc'];
            $icon = '▲';
        } else {
            // 目前 DESC → 點擊後取消排序
            // 同時移除 sort_by 和 sort_dir（不能只移除 sort_by，否則 URL 殘留孤立的 sort_dir=desc）
            $nextSortParams = ['sort_by' => '', 'sort_dir' => ''];
            $icon = '▼';
        }
        $sortUrl = route('codes.show', array_merge(
            ['table_name' => $q],
            // 保留現有 search + filters，移除舊 sort 和 page
            array_filter(request()->only(['search']), fn($v) => $v !== ''),
            !empty($filters) ? ['filters' => $filters] : [],
            // 取消排序時 sort_by/sort_dir 為空字串，route() 會自動忽略空值
            array_filter($nextSortParams, fn($v) => $v !== '')
        ));
    @endphp
    <th>
        <a href="{{ $sortUrl }}" style="color: inherit; text-decoration: none;">
            @if(in_array($item, $joinedColumns))
                ({{ $item }})
            @else
                {{ $item }}
            @endif
            {{ $icon }}
        </a>
        @if(in_array($item, $keyColumns, true))
            <span class="badge badge-info ml-1">PK</span>
        @endif
    </th>
@endforeach
```

#### 4.3.5 Filter 輸入列（`<thead>` 第二列）

```blade
<tr class="filter-row">
    @foreach ($thead as $item)
        <th style="padding: 4px;">
            <input type="text"
                   form="filter-form"
                   name="filters[{{ $item }}]"
                   value="{{ $filters[$item] ?? '' }}"
                   placeholder="{{ $item }}"
                   class="form-control form-control-sm">
        </th>
    @endforeach
    @if($showActions)
        <th></th>
    @endif
</tr>
```

> 因為 `form="filter-form"` 屬性指向 table 外的 `<form id="filter-form">`，在這些 input 裡按 Enter 會 submit `filter-form`（正確行為）。

#### 4.3.6 視圖頂部初始化

在 `show.blade.php` 的 `@php` 區塊新增：

```blade
@php
    $dynastyMap    = $dynastyMap ?? [];
    $isReadOnly    = $isReadOnly ?? false;
    $showActions   = Auth::check() && !$isReadOnly;
    $keyColumns    = $keyColumns ?? [];
    $joinedColumns = $joinedColumns ?? [];
    // ★ 新增
    $filters       = $filters ?? [];
    $sortBy        = $sortBy ?? '';
    $sortDir       = $sortDir ?? 'asc';
@endphp
```

---

## 5. Phase 計劃

### Phase 1 — Pilot（先完成 ALTNAME_CODES 一個表）

**工作項目：**
1. `CodesController::show()` 依照第 4.1 節全部修改（包含把 `getKeyColumns()` 移到 paginate 之前）
2. 新增私有方法 `resolveColumnForQuery()`（依照第 4.2 節完整演算法）
3. `show.blade.php` 依照第 4.3 節全部修改（form 結構、三態排序、filter 列、全文搜尋 hidden inputs）
4. 確認 `$useCursorPagination` 分支不受影響（`showWithCursorPagination()` 完全不動）

**測試目標（ALTNAME_CODES）：**
- `/codes/ALTNAME_CODES?sort_by=c_name_type_code&sort_dir=asc` 排序正確，分頁換頁不飄移
- `/codes/ALTNAME_CODES?sort_by=c_name_type_code&sort_dir=desc` → 再點同欄 → URL 不帶 sort_by（三態取消）
- `/codes/ALTNAME_CODES?filters[c_name_type_code]=A` 只顯示符合的行
- 多欄同時過濾（AND 邏輯）
- 分頁換頁保留 filter + sort 狀態
- 全文搜尋送出後，filter/sort 狀態保留（hidden inputs 正確帶入）
- Clear Filters 後只保留 search，filter/sort 清除

**完成後：** 使用者測試 → 確認後進入 Phase 2。

---

### Phase 2 — 驗證所有 8 個側邊欄表格

由於 view 與 controller 是共用的，Phase 1 完成後所有表格理論上已自動具備功能。此 phase 逐表確認：

| 表名 | 測試項目 |
|------|----------|
| `ADDR_BELONGS_DATA` | filter / sort / 分頁狀態保留 |
| `ADDR_CODES` | filter / sort（c_dy 欄位朝代顯示是否正常） |
| `ALTNAME_CODES` | ✅ Phase 1 已完成 |
| `APPOINTMENT_CODES` | filter / sort |
| `OFFICE_CODES` | filter / sort（含 c_dy 欄） |
| `SOCIAL_INSTITUTION_CODES` | filter / sort |
| `TEXT_CODES` | filter / sort |
| `TEXT_INSTANCE_DATA` | filter / sort |

---

### Phase 4 — `/codes` 首頁客戶端搜尋與排序

**目標：** 在 `codes/index.blade.php` 加入純前端（純 JS）的即時搜尋與排序，無需後端修改。

**工作項目：**
1. `codes/index.blade.php`：加入搜尋 input、更新 `<table>` 結構（加 `id="codes-index-table"` + `id="codes-index-body"` + 欄標題 `data-col="0"` / `data-col="1"` 屬性）、新增 `@section('js')` 中的 inline JS。
2. 新增 i18n key `codes.search_tables`（見 i18n 注意事項）。

**設計原則：**
- 81 行靜態資料，純客戶端操作足夠，不需要後端 query 參數。
- 排序亦採三態：`⇅`（原序）→ `▲`（ASC）→ `▼`（DESC）→ 取消（回到原序）。
- 搜尋 + 排序可同時並存（先排序，再 filter 隱藏不符合列）。
- 不依賴 DataTables、jQuery（AGENTS.md 禁止引入外部 CDN JS）。

---

#### 4.4 `/codes` 首頁：`codes/index.blade.php` 完整修改

**修改後完整 view（實作者直接替換原檔）：**

```blade
@extends('layouts.dashboard-v3')

@section('content')
    <div class="card card-default">
        <div class="card-header">
            <h3 class="card-title">{{ __('nav.all_tables') }}</h3>
            <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse">
                    <i class="fas fa-minus"></i>
                </button>
            </div>
        </div>
        <div class="card-body">
            {{-- 即時搜尋 --}}
            <div style="margin-bottom: 12px;">
                <input type="text"
                       id="table-search"
                       class="form-control form-control-sm"
                       placeholder="{{ __('codes.search_tables') }}"
                       style="max-width: 400px;"
                       autocomplete="off">
            </div>
            <div class="table-responsive p-0">
                <table class="table table-hover table-sm" id="codes-index-table">
                    <thead>
                    <tr>
                        <th data-col="0" style="cursor: pointer; user-select: none;">
                            {{ __('codes.table_name') }}
                            <span class="sort-icon" aria-hidden="true">⇅</span>
                        </th>
                        <th data-col="1" style="cursor: pointer; user-select: none;">
                            {{ __('codes.description') }}
                            <span class="sort-icon" aria-hidden="true">⇅</span>
                        </th>
                    </tr>
                    </thead>
                    <tbody id="codes-index-body">
                    @foreach($data as $item)
                        <tr>
                            <td><a href="/codes/{{ $item['name'] }}">{{ $item['name'] }}</a></td>
                            <td>{{ $item['description'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{-- W1 fix：<p> 必須在 .table-responsive 外，否則 .p-0 會吃掉 padding --}}
            <p id="no-match-msg" style="display:none; color:#999; padding: 8px 0;">
                {{ __('common.no_data') }}
            </p>
        </div>
    </div>
@endsection

@section('js')
<script>
(function () {
    const tbody      = document.getElementById('codes-index-body');
    const searchInput = document.getElementById('table-search');
    const noMatchMsg  = document.getElementById('no-match-msg');
    const headers    = document.querySelectorAll('#codes-index-table thead th[data-col]');

    // 儲存原始列順序（供三態排序取消時還原）
    const allRows    = Array.from(tbody.querySelectorAll('tr'));
    const originalOrder = [...allRows];

    let sortState = { col: null, dir: 'asc' }; // col = 欄 index（整數）

    // ── 更新欄標題排序圖示 ──────────────────────────
    function updateIcons() {
        headers.forEach(th => {
            const icon = th.querySelector('.sort-icon');
            const col  = parseInt(th.dataset.col, 10);
            if (sortState.col === col) {
                icon.textContent = sortState.dir === 'asc' ? '▲' : '▼';
            } else {
                icon.textContent = '⇅';
            }
        });
    }

    // ── 重新套用搜尋可見性（排序後呼叫）──────────────
    function applyFilter() {
        const q = searchInput.value.toLowerCase();
        let visibleCount = 0;
        allRows.forEach(row => {
            const match = !q || row.textContent.toLowerCase().includes(q);
            row.style.display = match ? '' : 'none';
            if (match) visibleCount++;
        });
        noMatchMsg.style.display = visibleCount === 0 ? '' : 'none';
    }

    // ── 排序並重繪（排序 allRows 陣列，再 appendChild）──
    function applySortAndFilter() {
        if (sortState.col === null) {
            // 取消排序：還原原始順序
            originalOrder.forEach(r => tbody.appendChild(r));
        } else {
            // W2 fix：每次排序前先從 originalOrder 重新同步 allRows，
            // 避免連續點擊不同欄時從上次排序後的順序開始（雖然結果相同，但語意更清晰）
            allRows.length = 0;
            originalOrder.forEach(r => allRows.push(r));

            const colIdx = sortState.col;
            allRows.sort((a, b) => {
                const aText = (a.cells[colIdx]?.textContent ?? '').trim().toLowerCase();
                const bText = (b.cells[colIdx]?.textContent ?? '').trim().toLowerCase();
                return sortState.dir === 'asc'
                    ? aText.localeCompare(bText)
                    : bText.localeCompare(aText);
            });
            allRows.forEach(r => tbody.appendChild(r));
        }
        applyFilter();
        updateIcons();
    }

    // ── 欄標題點擊：三態切換 ──────────────────────────
    headers.forEach(th => {
        th.addEventListener('click', function () {
            const col = parseInt(this.dataset.col, 10);
            if (sortState.col !== col) {
                // 點不同欄 → 從 ASC 開始
                sortState = { col, dir: 'asc' };
            } else if (sortState.dir === 'asc') {
                sortState.dir = 'desc';
            } else {
                // 第三次點同欄 → 取消排序
                sortState = { col: null, dir: 'asc' };
            }
            applySortAndFilter();
        });
    });

    // ── 搜尋框即時 filter ──────────────────────────────
    searchInput.addEventListener('input', applyFilter);

    // 初始化時呼叫一次，確保首次載入若資料為空時 noMatchMsg 能正確顯示
    applyFilter();
})();
</script>
@endsection
```

**注意事項：**
- `allRows` 儲存 DOM 節點引用；`originalOrder` 是同一組節點的快照，用於取消排序時還原。
- `applySortAndFilter()` 每次排序後都重呼叫 `applyFilter()`，確保搜尋可見性在排序後仍正確。
- 搜尋框只做 `display:none`，不修改 DOM 結構，排序邏輯仍對全部列有效。
- 不需要 `<form>`，不需要後端路由修改。

---

#### 4.5 i18n 新增（index 頁）

在 `resources/lang/zh-TW/codes.php` 與 `resources/lang/en/codes.php` 各加一個 key：

| 翻譯 key | zh-TW 值 | en 值 |
|---------|---------|-------|
| `codes.search_tables` | `搜尋表格…` | `Search tables…` |

---

#### 4.6 Phase 4 測試

**手動 UI 測試（使用者負責）：**
- 輸入關鍵字後，清單即時篩選（顯示含關鍵字的列，其餘隱藏）
- 輸入不存在的關鍵字，顯示「無資料」提示
- 點欄標題 → ▲ → 再點 → ▼ → 再點 → ⇅（取消排序，回到原始 PHP 輸出順序）
- 先搜尋再排序：搜尋可見性正確保留
- 先排序再搜尋：排序順序正確保留，隱藏不符合列

---

### Phase 3 — 驗證有 JOIN 的其他表格（**必做，merge gate**）

> **重要**：此 phase 不得設為選做。controller/view 共用代表 Phase 1 上線後 filter/sort 即作用於全部 81 張表（含有 JOIN 的）。若 `resolveColumnForQuery()` 的 JOIN 欄位解析有誤，`APPOINTMENT_CODE_TYPE_REL`、`OFFICE_CODE_TYPE_REL` 等表會直接報 ambiguous column 或 SQL error。

**驗收要求：**

| 情境 | 期望行為 |
|------|----------|
| `resolveColumnForQuery('appt_name', $joinConfig)` | 回傳 `'code.c_appt_desc_chn'`（from selectList） |
| `resolveColumnForQuery('c_appt_code', $joinConfig)` | 回傳 `'rel.c_appt_code'`（base alias + column） |
| `resolveColumnForQuery('c_name', null)` | 回傳 `'c_name'`（非 JOIN 表，原樣） |
| `resolveColumnForQuery` 對不可解析欄位 | 回傳 `null`，呼叫方 continue |
| `APPOINTMENT_CODE_TYPE_REL` filter + sort | 無 DB error，無 ambiguous column |
| `OFFICE_CODE_TYPE_REL` filter + sort | 無 DB error |

確認以上通過，才可 merge 進 develop。

---

## 6. 安全性考量

| 風險 | 緩解措施 |
|------|----------|
| SQL Injection（欄位名） | `sort_by` / `filters` 的 key 都對照 `$thead` 白名單（`in_array`，strict） |
| SQL Injection（欄位值） | 使用 Laravel Query Builder `where()` 綁定參數，值不進 SQL 字串拼接 |
| filter value 非字串（array 注入） | 白名單過濾時同步驗證 `is_scalar($val)`，非 scalar 丟棄 |
| 欄位名注入（JOIN 表） | `resolveColumnForQuery()` 只映射到 config 內建的固定表達式，找不到映射 → return null，不原樣回傳 |
| 分頁飄移（正確性問題） | 排序後永遠補主鍵 tie-breaker（`orderBy(PK, asc)`），確保同值列在不同 page 穩定 |

---

## 7. 不在範圍內（Out of Scope）

- **游標分頁表（`CBDB__NAME_FTS`）**：完全不動 `showWithCursorPagination()`，不加 filter/sort（該表不在側邊欄 8 表內）。  
  但注意：`showWithCursorPagination()` 傳給 view 的變數清單，必須與標準分頁分支一致，補上 `'filters' => [], 'sortBy' => '', 'sortDir' => 'asc'`（空值），避免 Blade 的 `$filters ?? []` 等初始化運算式在游標分頁路徑下出現 undefined variable 警告。
- 下拉式 select filter（如朝代 c_dy）：Phase 1 只做文字輸入；若使用者有需求，之後再做。
- DataTables 前端整合：保持伺服器端分頁，不引入 DataTables JS（AGENTS.md 禁止）。

### i18n 注意事項（不可省略）

`show.blade.php` 新增的按鈕文字必須走翻譯 helper，不得硬編碼英文字串（AGENTS.md 規則）：

| 原始字串 | 翻譯 key | 中文值 | 英文值 | 用於 |
|---------|---------|-------|-------|------|
| `Apply Filters` | `codes.apply_filters` | 套用篩選 | Apply Filters | `show.blade.php` |
| `Clear Filters` | `codes.clear_filters` | 清除篩選 | Clear Filters | `show.blade.php` |
| `Search tables…` | `codes.search_tables` | 搜尋表格… | Search tables… | `index.blade.php` |

在 `resources/lang/zh-TW/codes.php` 與 `resources/lang/en/codes.php` 各加入這三個 key。  
Blade 中使用 `{{ __('codes.apply_filters') }}`、`{{ __('codes.clear_filters') }}`、`{{ __('codes.search_tables') }}`。

---

## 8. 測試策略

### 後端 Feature Test（補充到 `tests/Feature/CodesControllerTest.php`）

| 測試案例 | 驗證點 |
|---------|-------|
| `?sort_by=合法欄位&sort_dir=asc` | HTTP 200，回傳資料存在 |
| `?sort_by=非法欄位名` | HTTP 200，忽略 sort_by，不報錯 |
| `?sort_dir=invalid` | HTTP 200，強制轉為 asc |
| `?filters[合法欄位]=value` | HTTP 200，回傳資料只包含符合的行 |
| `?filters[非法欄位]=value` | HTTP 200，忽略 filters，不報錯 |
| `?filters[欄位][]=array_attack` | HTTP 200，丟棄非 scalar value，不報錯 |
| JOIN 表 + filter | HTTP 200，無 ambiguous column |
| 分頁 + sort | 換頁後 sort 狀態保留（`appends` 正確） |

### ⚠ 測試替身（FakeQueryBuilder）必須補充

`tests/Feature/CodesControllerTest.php` 內的 `FakeQueryBuilder`（約第 835 行起）目前缺少以下方法：
- `orderBy(string $column, string $direction)`
- `leftJoin()` / `join()`
- `select()`

若直接跑 filter/sort 的測試，會先在 fake DB 層報錯，而不是測到正式代碼。實作時必須先補齊 `FakeQueryBuilder` 的這幾個方法（可以 return `$this` 讓 fluent interface 正常運作），再寫 filter/sort 測試案例。

### UI 手動測試（使用者負責）
- 三態排序圖示切換正確
- 全文搜尋送出後 filter/sort 狀態保留
- Clear Filters 後 filter/sort 清除但 search 保留
- 分頁換頁所有狀態保留

---

## 附錄：相關檔案

| 檔案 | 用途 |
|------|------|
| `app/Http/Controllers/CodesController.php` | 主要後端修改位置（`show()` 方法 + 新增 `resolveColumnForQuery()`） |
| `resources/views/codes/show.blade.php` | 主要前端修改位置 |
| `config/codes.php` | 表格設定（81 個表） |
| `app/Repositories/CodesRepository.php` | 表名允許清單 |
| `tests/Feature/CodesControllerTest.php` | 補充 filter/sort 測試案例 |

## 附錄：`buildJoinQuery()` SELECT 結構說明

`buildJoinQuery()` 產生 `SELECT $baseAlias.*, [alias exprs...]`，例如：

```php
$query->select(array_merge([$baseAlias . '.*'], $select));
// 結果：SELECT rel.*, code.c_appt_desc_chn AS appt_name, type.c_appt_type_desc_chn AS appt_type_name
```

`buildTableHead()` 合併三個來源：
1. `getTableColumns($table)` → DB schema columns（如 `c_appt_code`、`c_appt_type_code`）
2. `getJoinedColumnNames($joinConfig)` → JOIN aliases（如 `appt_name`、`appt_type_name`）
3. `array_keys((array) $sampleRow)` → sampleRow keys（與前兩者有重疊，`array_unique` 去重）

因此 `$thead` 白名單的來源是可信的（全為 DB 欄位名或 config 內建 alias），但 `resolveColumnForQuery()` 的 null-return 防線仍不可省略。
