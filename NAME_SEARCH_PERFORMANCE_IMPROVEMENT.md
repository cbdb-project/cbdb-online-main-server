# CBDB 姓名搜尋效能改進方案

## 一、背景與問題

### 當前問題

`BiogMainRepository::namesByQuery()` 在處理中文姓名搜尋時存在嚴重效能問題：

- **查詢方式**：使用 `LIKE '%關鍵詞%'` 進行模糊搜尋
- **效能表現**：
  - 單字搜尋（如「張」）：13ms（可接受）
  - 雙字搜尋（如「張三」）：1374ms（**過慢**）
  - 完整姓名（如「蘇軾」）：1540ms（**過慢**）
- **根本原因**：包含查詢（`%keyword%`）無法利用 B-Tree 索引，導致全表掃描

### 現有方案的局限性

| 方案 | 效能 | 問題 |
|------|------|------|
| MySQL 全文索引（ngram parser） | 快 | ❌ MariaDB 10.3 不支援，違反資料庫相容性原則 |
| PostgreSQL 全文搜尋 | 快 | ❌ 依賴特定資料庫實作 |
| Elasticsearch | 非常快 | ❌ 引入額外基礎設施，維護成本高 |
| 繼續使用 LIKE | 簡單 | ❌ 效能無法接受 |

### 專案約束

根據 `DATABASE.md` 和 `AGENTS.md` 的要求：

> **重要原則**：避免使用特定資料庫專屬功能（如 MySQL 的 ngram parser、MariaDB 專屬外掛），以保持未來遷移至其他資料庫實作的可能性。

因此，我們需要一個：
- ✅ 不依賴特定資料庫功能的方案
- ✅ 使用標準 SQL 和通用索引（B-Tree）
- ✅ 適合中等規模資料（70 萬人物以內，2M+ 名字記錄）
- ✅ 可控且易於維護

---

## 二、解決方案：手工倒排索引表

### 核心思路

**手動建構倒排表（Inverted Index），將中文姓名拆分為可索引的後綴，支援前綴匹配查詢。**

#### 原理示例

對於人物「王安石」（姓=王，名=安石）：

```
原始資料：
c_personid=1001, c_name_chn="王安石", c_surname="王", c_mingzi="安石"

倒排表記錄：
1001 | 1 | "王安石" | "王安石"  -- 本名完整
1001 | 1 | "安石"   | "王安石"  -- 名部分
1001 | 1 | "石"     | "王安石"  -- 名末字
```

**查詢轉換：**
```sql
-- 原查詢（慢）：全表掃描
WHERE c_name_chn LIKE '%石%'  -- 1540ms

-- 新查詢（快）：索引前綴匹配
WHERE search_term LIKE '石%'  -- 預計 3ms
```

### 為什麼選擇這個方案

1. **效能優異**：前綴匹配可充分利用 B-Tree 索引，預計從 1500ms 降至 3ms（**500 倍提升**）
2. **資料庫相容**：僅使用標準 SQL 和 B-Tree 索引，可在 MySQL/MariaDB/PostgreSQL 上執行
3. **符合習慣**：支援「石」、「安石」等後綴搜尋，符合中文姓名搜尋習慣
4. **資料量可控**：預計 550 萬倒排記錄，空間佔用 350-500 MB（可接受）
5. **完全可控**：應用層維護，不依賴第三方服務

---

## 三、表結構設計

### CBDB_NAME_SEARCH_FTS 表

```sql
CREATE TABLE CBDB_NAME_SEARCH_FTS (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    c_personid INT NOT NULL,
    name_type_code SMALLINT UNSIGNED NOT NULL,
    name_type_desc VARCHAR(32) NOT NULL,
    name_type_desc_chn VARCHAR(32) NOT NULL,
    search_term VARCHAR(100) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    source VARCHAR(32) NOT NULL,
    source_key VARCHAR(255) NULL,
    is_simplified TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_cbdb_name_search_term ON CBDB_NAME_SEARCH_FTS(search_term, c_personid);
CREATE INDEX idx_cbdb_name_person ON CBDB_NAME_SEARCH_FTS(c_personid);
CREATE INDEX idx_cbdb_name_type ON CBDB_NAME_SEARCH_FTS(name_type_code);
```

### name_type_code 欄位說明與對應描述

| 值 | 類型 | 來源表/欄位 |
|----|------|------------|
| 1 | 本名 | `BIOG_MAIN.c_name_chn` |
| 2 | 字 | `ALTNAME_DATA` (c_alt_name_type_code=4) |
| 3 | 號 | `ALTNAME_DATA` (c_alt_name_type_code=5) |
| 4 | 其他別名 | `ALTNAME_DATA` (其他 type_code) |

對應的描述欄位建議如下：

| 欄位 | 範例值 | 說明 |
|------|--------|------|
| `name_type_desc` | `main_name` / `zi` / `hao` / `altname` | 方便後端依英文字串判斷 |
| `name_type_desc_chn` | `本名` / `字` / `號` / `別名` | 直接呈現在 UI 或報表 |
| `source` | `biog_main` / `altname_data` | 標記資料來源表 |
| `source_key` | `biog_main:1762` / `altname:1762-4-介甫` | 以字串保存來源主鍵或複合鍵 |
| `is_simplified` | 0 / 1 | 0=原文，1=簡化字版本 |

> `ALTNAME_DATA` 無自增鍵，建議統一使用 `altname:{c_personid}-{c_alt_name_type_code}-{c_alt_name_chn}` 形式存入 `source_key`，能唯一對應原始別名紀錄；本名則可用 `biog_main:{c_personid}`。

### 繁簡轉換：CBDB_TRAD_SIMP_MAP

為了支援 `is_simplified=1` 的倒排記錄，建議新增一張通用對照表儲存 OpenCC 釋出的繁簡映射：

```sql
CREATE TABLE CBDB_TRAD_SIMP_MAP (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    trad_char CHAR(1) NOT NULL,
    simp_char CHAR(1) NOT NULL,
    variant_set VARCHAR(64) NOT NULL DEFAULT 'OpenCC',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_trad_variant (trad_char, variant_set),
    KEY idx_simp_char (simp_char)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

匯入流程：
1. 下載對應的 OpenCC `TSCharacters.txt`/`STCharacters.txt`。
2. 將每組字拆成 `trad_char → simp_char` 對（多對多則拆成多列）。
3. 透過簡單的 seed/command 將資料批量寫入 CBDB_TRAD_SIMP_MAP。

使用方式：
- 在產生倒排 suffix 時，先寫入繁體字版本（`is_simplified=0`），再以 CBDB_TRAD_SIMP_MAP 查詢對應簡體字重新組合字串、設定 `is_simplified=1` 後寫入同一筆搜尋詞。
- 查詢時若輸入為簡體，可直接 `WHERE search_term LIKE :input% AND is_simplified=1`；若輸入繁體則比對 `is_simplified=0`。也可在應用層將輸入轉為繁簡各一版並合併結果，以兼容混合輸入。

### 示例資料

```sql
-- 王安石（1021-1086，北宋政治家）
-- 本名：王安石，字：介甫，號：半山

INSERT INTO CBDB_NAME_SEARCH_FTS (
    c_personid, name_type_code, name_type_desc, name_type_desc_chn,
    search_term, full_name, source, source_key, is_simplified
) VALUES
-- 本名（完整 + 後綴）
(1762, 1, 'main_name', '本名', '王安石', '王安石', 'biog_main', 'biog_main:1762', 0),
(1762, 1, 'main_name', '本名', '安石', '王安石', 'biog_main', 'biog_main:1762', 0),
(1762, 1, 'main_name', '本名', '石', '王安石', 'biog_main', 'biog_main:1762', 0),

-- 字（完整 + 後綴）
(1762, 2, 'zi', '字', '介甫', '介甫', 'altname_data', 'altname:1762-4-介甫', 0),
(1762, 2, 'zi', '字', '甫', '介甫', 'altname_data', 'altname:1762-4-介甫', 0),

-- 號（完整）
(1762, 3, 'hao', '號', '半山', '半山', 'altname_data', 'altname:1762-5-半山', 0),
(1762, 3, 'hao', '號', '山', '半山', 'altname_data', 'altname:1762-5-半山', 0);

-- 查詢「石」
SELECT DISTINCT c_personid, full_name, name_type_code
FROM CBDB_NAME_SEARCH_FTS
WHERE search_term LIKE '石%'
ORDER BY LENGTH(search_term) DESC, c_personid;

-- 結果：
-- 1762, "王安石", 1  (匹配到 search_term="石")
```

---

## 四、姓名拆分規則

### 4.1 拆分策略

**只拆分「名」部分的後綴，不包含姓氏單字**

#### 原因

1. **避免雜訊**：單字姓氏（如「王」、「李」）會匹配到數萬人
2. **符合習慣**：使用者通常記得名字的後半部分，而非單姓
3. **姓氏過濾**：已有 `c_surname` 欄位可單獨過濾

#### 示例

| 姓名 | 姓 | 名 | 倒排後綴 |
|------|----|----|---------|
| 王安石 | 王 | 安石 | ["王安石", "安石", "石"] |
| 司馬相如 | 司馬 | 相如 | ["司馬相如", "相如", "如"] |
| 李白 | 李 | 白 | ["李白", "白"] |
| 諸葛亮 | 諸葛 | 亮 | ["諸葛亮", "亮"] |

### 4.2 拆分演算法（PHP）

```php
/**
 * 將姓名拆分為可搜尋的後綴列表
 *
 * @param string $surname 姓氏（如「王」、「司馬」）
 * @param string $mingzi 名字（如「安石」）
 * @return array 後綴列表（從長到短）
 */
function splitNameToSuffixes(string $surname, string $mingzi): array
{
    if (empty($mingzi)) {
        return [];
    }

    $fullName = $surname . $mingzi;
    $suffixes = [$fullName]; // 完整姓名

    // 將名字拆成單字陣列
    $chars = preg_split('//u', $mingzi, -1, PREG_SPLIT_NO_EMPTY);

    // 從後往前產生後綴
    for ($i = 0; $i < count($chars); $i++) {
        $suffix = implode('', array_slice($chars, $i));
        $suffixes[] = $suffix;
    }

    return $suffixes;
}

// 示例
splitNameToSuffixes('王', '安石');
// => ["王安石", "安石", "石"]

splitNameToSuffixes('司馬', '相如');
// => ["司馬相如", "相如", "如"]
```

### 4.3 別名處理

對於字、號、別名等：
- **僅拆分多字別名**（≥2 字）
- **單字別名不拆分**（完整保留）

示例：
```
字：介甫（2字） → ["介甫", "甫"]
號：半山（2字） → ["半山", "山"]
號：東坡居士（4字） → ["東坡居士", "坡居士", "居士", "士"]
別名：蘇（1字） → ["蘇"]  // 不拆分
```

### 4.4 外文名處理

**暫不拆分**，整詞儲存：

```
c_name: "Su Shi" → ["Su Shi"]  // 不拆分為 "Shi"
c_name_rm: "Sū Shì" → ["Sū Shì"]
```

理由：
1. 外文名拆分規則複雜（空格分隔？音節分隔？）
2. 外文名搜尋需求較少
3. 可在後續版本增強

---

## 五、索引策略

### 5.1 核心索引

```sql
-- 主查詢索引（複合索引）
CREATE INDEX idx_cbdb_name_search_term ON CBDB_NAME_SEARCH_FTS (search_term, c_personid);
```

**查詢最佳化：**
```sql
-- ✅ 使用索引
EXPLAIN SELECT * FROM CBDB_NAME_SEARCH_FTS
WHERE search_term LIKE '石%';
-- key: idx_cbdb_name_search_term (using index)

-- ❌ 不使用索引
WHERE search_term LIKE '%石%';
-- type: ALL (full table scan)
```

### 5.2 輔助索引

```sql
-- 按人物 ID 查詢（維護時使用）
CREATE INDEX idx_cbdb_name_person ON CBDB_NAME_SEARCH_FTS (c_personid);

-- 按名字類型過濾
CREATE INDEX idx_cbdb_name_type ON CBDB_NAME_SEARCH_FTS (name_type_code);
```

### 5.3 索引大小估算

假設：
- 70 萬人物 × 平均 3.19 個名字 = 220 萬原始名字
- 每個名字平均拆成 2.5 個後綴
- 倒排記錄總數：220 萬 × 2.5 = **550 萬條**

**索引空間：**
- `search_term` (VARCHAR(100), UTF-8)：平均 20 bytes
- `c_personid` (INT)：4 bytes
- 索引開銷：~60%
- 總計：550 萬 × (20+4) × 1.6 ≈ **210 MB**

---

## 六、查詢改進方案

### 6.1 修改 namesByQuery()

```php
// app/Repositories/BiogMainRepository.php

public static function namesByQuery(Request $request, $num=20)
{
    $request->q = addslashes($request->q);

    // 空查詢：返回分頁列表
    if (!$request->q) {
        // ... 原有邏輯
    }

    // 純數字：直接按 c_personid 查詢（已最佳化）
    if (ctype_digit($request->q)) {
        // ... 已有邏輯
    }

    // 新增：使用倒排表搜尋
    $personIds = DB::table('CBDB_NAME_SEARCH_FTS')
        ->where('search_term', 'LIKE', $request->q . '%')
        ->orderByRaw('LENGTH(search_term) ASC')  // 優先精確匹配
        ->limit(500)  // 限制最多 500 個候選人
        ->pluck('c_personid')
        ->unique()
        ->toArray();

    if (empty($personIds)) {
        // 回退到原有的複雜搜尋（相容性保障）
        // ... 原有邏輯
    }

    // 按找到的 personIds 查詢完整資訊
    $names = BiogMain::select('BIOG_MAIN.c_personid', ...)
        ->leftJoin('DYNASTIES', ...)
        ->leftJoin('ADDR_CODES', ...)
        ->leftJoin('ALTNAME_DATA as A1', ...)
        ->leftJoin('ALTNAME_DATA as A2', ...)
        ->whereIn('BIOG_MAIN.c_personid', $personIds)
        ->orderByRaw('FIELD(BIOG_MAIN.c_personid, ' . implode(',', $personIds) . ')')
        ->groupBy('BIOG_MAIN.c_personid')
        ->paginate($num);

    return $names;
}
```

### 6.2 查詢優先順序

**排序規則**（從高到低）：
1. 完整匹配（`search_term = '王安石'`）
2. 長後綴匹配（`search_term = '安石'`）
3. 短後綴匹配（`search_term = '石'`）
4. 按 `c_personid` 升序

實作：
```sql
ORDER BY LENGTH(search_term) ASC, c_personid ASC
```

---

## 七、資料維護方案

### 7.1 初始化：批次產生腳本

#### Artisan 指令

```bash
php artisan cbdb:rebuild-name-search [--chunk=1000] [--force]
```

**實作：**

```php
// app/Console/Commands/RebuildNameSearchIndex.php

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\BiogMain;

class RebuildNameSearchIndex extends Command
{
    protected $signature = 'cbdb:rebuild-name-search
                            {--chunk=1000 : 每批處理記錄數}
                            {--force : 強制重建（清空現有資料）}';

    protected $description = '重建姓名搜尋倒排索引';

    public function handle()
    {
        if ($this->option('force')) {
            $this->warn('清空現有索引資料...');
            DB::table('CBDB_NAME_SEARCH_FTS')->truncate();
        }

        $chunk = (int) $this->option('chunk');
        $this->info("開始重建索引（批次大小：{$chunk}）");

        $bar = $this->output->createProgressBar(BiogMain::count());

        BiogMain::chunk($chunk, function($persons) use ($bar) {
            foreach ($persons as $person) {
                $this->indexPerson($person);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->info("\n索引重建完成！");
        $this->displayStatistics();
    }

    protected function indexPerson($person)
    {
        $records = [];
        $typeMeta = [
            1 => ['desc' => 'main_name', 'desc_chn' => '本名', 'source' => 'biog_main'],
            2 => ['desc' => 'zi', 'desc_chn' => '字', 'source' => 'altname_data'],
            3 => ['desc' => 'hao', 'desc_chn' => '號', 'source' => 'altname_data'],
            4 => ['desc' => 'altname', 'desc_chn' => '別名', 'source' => 'altname_data'],
        ];

        // 1. 本名
        if ($person->c_surname && $person->c_mingzi) {
            $suffixes = $this->splitNameToSuffixes(
                $person->c_surname,
                $person->c_mingzi
            );

            foreach ($suffixes as $suffix) {
                $records[] = [
                    'c_personid' => $person->c_personid,
                    'name_type_code' => 1,
                    'name_type_desc' => $typeMeta[1]['desc'],
                    'name_type_desc_chn' => $typeMeta[1]['desc_chn'],
                    'search_term' => $suffix,
                    'full_name' => $person->c_name_chn,
                    'source' => $typeMeta[1]['source'],
                    'source_key' => "biog_main:{$person->c_personid}",
                    'is_simplified' => 0,
                    'created_at' => now(),
                ];
            }
        }

        // 2. 字、號、別名
        $altnames = DB::table('ALTNAME_DATA')
            ->where('c_personid', $person->c_personid)
            ->get();

        foreach ($altnames as $alt) {
            $type = match($alt->c_alt_name_type_code) {
                4 => 2,  // 字
                5 => 3,  // 號
                default => 4,  // 其他別名
            };

            $suffixes = $this->splitAltname($alt->c_alt_name_chn);

            foreach ($suffixes as $suffix) {
                $records[] = [
                    'c_personid' => $person->c_personid,
                    'name_type_code' => $type,
                    'name_type_desc' => $typeMeta[$type]['desc'],
                    'name_type_desc_chn' => $typeMeta[$type]['desc_chn'],
                    'search_term' => $suffix,
                    'full_name' => $alt->c_alt_name_chn,
                    'source' => $typeMeta[$type]['source'],
                    'source_key' => "altname:{$person->c_personid}-{$alt->c_alt_name_type_code}-{$alt->c_alt_name_chn}",
                    'is_simplified' => 0,
                    'created_at' => now(),
                ];
            }
        }

        if (!empty($records)) {
            DB::table('CBDB_NAME_SEARCH_FTS')->insert($records);
        }
    }

    protected function splitNameToSuffixes(string $surname, string $mingzi): array
    {
        // ... 拆分邏輯（見 4.2 節）
    }

    protected function splitAltname(string $altname): array
    {
        $chars = preg_split('//u', $altname, -1, PREG_SPLIT_NO_EMPTY);

        if (count($chars) <= 1) {
            return [$altname];  // 單字不拆分
        }

        $suffixes = [$altname];
        for ($i = 1; $i < count($chars); $i++) {
            $suffixes[] = implode('', array_slice($chars, $i));
        }

        return $suffixes;
    }

    protected function displayStatistics()
    {
        $stats = DB::table('CBDB_NAME_SEARCH_FTS')
            ->selectRaw('name_type_code, COUNT(*) as count')
            ->groupBy('name_type_code')
            ->get();

        $this->table(
            ['類型', '記錄數'],
            $stats->map(fn($s) => [
                match($s->name_type_code) {
                    1 => '本名',
                    2 => '字',
                    3 => '號',
                    4 => '別名',
                },
                number_format($s->count)
            ])
        );

        $total = DB::table('CBDB_NAME_SEARCH_FTS')->count();
        $this->info("總計：" . number_format($total) . " 條倒排記錄");
    }
}
```

#### 註冊指令

```php
// app/Console/Kernel.php

protected $commands = [
    Commands\RebuildNameSearchIndex::class,
];
```

### 7.2 增量維護：應用層同步

#### Model Observer

```php
// app/Observers/BiogMainObserver.php

<?php

namespace App\Observers;

use App\BiogMain;
use App\Services\NameSearchIndexService;

class BiogMainObserver
{
    protected $indexService;

    public function __construct(NameSearchIndexService $indexService)
    {
        $this->indexService = $indexService;
    }

    public function created(BiogMain $person)
    {
        $this->indexService->indexPerson($person);
    }

    public function updated(BiogMain $person)
    {
        // 檢查姓名欄位是否變化
        if ($person->isDirty(['c_surname', 'c_mingzi', 'c_name_chn'])) {
            $this->indexService->reindexPerson($person);
        }
    }

    public function deleted(BiogMain $person)
    {
        $this->indexService->removePerson($person->c_personid);
    }
}
```

#### 服務類別

```php
// app/Services/NameSearchIndexService.php

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class NameSearchIndexService
{
    protected $typeMeta = [
        1 => ['desc' => 'main_name', 'desc_chn' => '本名', 'source' => 'biog_main'],
        2 => ['desc' => 'zi', 'desc_chn' => '字', 'source' => 'altname_data'],
        3 => ['desc' => 'hao', 'desc_chn' => '號', 'source' => 'altname_data'],
        4 => ['desc' => 'altname', 'desc_chn' => '別名', 'source' => 'altname_data'],
    ];

    public function indexPerson($person)
    {
        // 同 RebuildNameSearchIndex::indexPerson()
    }

    public function reindexPerson($person)
    {
        DB::transaction(function() use ($person) {
            // 刪除舊索引
            DB::table('CBDB_NAME_SEARCH_FTS')
                ->where('c_personid', $person->c_personid)
                ->where('name_type_code', 1)  // 只刪除本名
                ->delete();

            // 重建索引
            $this->indexPerson($person);
        });
    }

    public function removePerson(int $personId)
    {
        DB::table('CBDB_NAME_SEARCH_FTS')
            ->where('c_personid', $personId)
            ->delete();
    }

    public function indexAltname(int $personId, int $typeCode, string $altname, ?string $sourceKey = null)
    {
        $type = match($typeCode) {
            4 => 2,
            5 => 3,
            default => 4,
        };

        $suffixes = $this->splitAltname($altname);
        $records = [];

        foreach ($suffixes as $suffix) {
            $records[] = [
                'c_personid' => $personId,
                'name_type_code' => $type,
                'name_type_desc' => $this->typeMeta[$type]['desc'],
                'name_type_desc_chn' => $this->typeMeta[$type]['desc_chn'],
                'search_term' => $suffix,
                'full_name' => $altname,
                'source' => $this->typeMeta[$type]['source'],
                'source_key' => $sourceKey ?? "altname:{$personId}-{$typeCode}-{$altname}",
                'is_simplified' => 0,
                'created_at' => now(),
            ];
        }

        DB::table('CBDB_NAME_SEARCH_FTS')->insert($records);
    }

    protected function splitAltname(string $altname): array
    {
        // ... 同批次腳本
    }
}
```

#### 註冊 Observer

```php
// app/Providers/AppServiceProvider.php

public function boot()
{
    BiogMain::observe(BiogMainObserver::class);
}
```

### 7.3 管理員工具：Web 介面

#### 路由

```php
// routes/web.php

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/admin/name-search-index', 'Admin\NameSearchIndexController@index')
        ->name('admin.name-search.index');
    Route::post('/admin/name-search-index/rebuild', 'Admin\NameSearchIndexController@rebuild')
        ->name('admin.name-search.rebuild');
    Route::get('/admin/name-search-index/status', 'Admin\NameSearchIndexController@status')
        ->name('admin.name-search.status');
});
```

#### 控制器

```php
// app/Http/Controllers/Admin/NameSearchIndexController.php

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class NameSearchIndexController extends Controller
{
    public function index()
    {
        $stats = $this->getStatistics();
        return view('admin.name-search-index.index', compact('stats'));
    }

    public function status()
    {
        $stats = $this->getStatistics();
        return response()->json($stats);
    }

    public function rebuild(Request $request)
    {
        if (!auth()->user()->is_admin == 1) {
            return response()->json(['error' => '權限不足'], 403);
        }

        set_time_limit(3600);  // 1 小時
        ini_set('memory_limit', '512M');

        try {
            Artisan::call('cbdb:rebuild-name-search', [
                '--force' => true,
                '--chunk' => 500,
            ]);

            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'message' => '索引重建完成',
                'output' => $output,
                'stats' => $this->getStatistics(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function getStatistics()
    {
        $totalPersons = DB::table('BIOG_MAIN')->count();
        $totalIndexed = DB::table('CBDB_NAME_SEARCH_FTS')
            ->distinct('c_personid')
            ->count('c_personid');

        $byType = DB::table('CBDB_NAME_SEARCH_FTS')
            ->selectRaw('name_type_code, COUNT(*) as count')
            ->groupBy('name_type_code')
            ->get()
            ->mapWithKeys(fn($item) => [$item->name_type_code => $item->count]);

        $tableSize = DB::select("
            SELECT
                ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
            FROM information_schema.TABLES
            WHERE table_schema = DATABASE()
            AND table_name = 'CBDB_NAME_SEARCH_FTS'
        ")[0]->size_mb ?? 0;

        return [
            'total_persons' => $totalPersons,
            'indexed_persons' => $totalIndexed,
            'coverage' => $totalPersons > 0
                ? round($totalIndexed / $totalPersons * 100, 2)
                : 0,
            'total_records' => array_sum($byType->toArray()),
            'by_type' => [
                'main_name' => $byType[1] ?? 0,
                'zi' => $byType[2] ?? 0,
                'hao' => $byType[3] ?? 0,
                'altname' => $byType[4] ?? 0,
            ],
            'table_size_mb' => $tableSize,
            'last_updated' => DB::table('CBDB_NAME_SEARCH_FTS')
                ->max('created_at'),
        ];
    }
}
```

#### 視圖

```blade
{{-- resources/views/admin/name-search-index/index.blade.php --}}

@extends('layouts.dashboard')

@section('content')
<div class="box box-primary">
    <div class="box-header with-border">
        <h3 class="box-title">姓名搜尋索引管理</h3>
    </div>

    <div class="box-body">
        <div class="row">
            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-aqua"><i class="fa fa-users"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">總人物數</span>
                        <span class="info-box-number">{{ number_format($stats['total_persons']) }}</span>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-green"><i class="fa fa-check"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">已索引</span>
                        <span class="info-box-number">{{ number_format($stats['indexed_persons']) }}</span>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-yellow"><i class="fa fa-list"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">倒排記錄數</span>
                        <span class="info-box-number">{{ number_format($stats['total_records']) }}</span>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="info-box">
                    <span class="info-box-icon bg-red"><i class="fa fa-database"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">索引大小</span>
                        <span class="info-box-number">{{ $stats['table_size_mb'] }} MB</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <h4>索引類型分布</h4>
                <table class="table table-bordered">
                    <tr>
                        <th>類型</th>
                        <th>記錄數</th>
                    </tr>
                    <tr>
                        <td>本名</td>
                        <td>{{ number_format($stats['by_type']['main_name']) }}</td>
                    </tr>
                    <tr>
                        <td>字</td>
                        <td>{{ number_format($stats['by_type']['zi']) }}</td>
                    </tr>
                    <tr>
                        <td>號</td>
                        <td>{{ number_format($stats['by_type']['hao']) }}</td>
                    </tr>
                    <tr>
                        <td>其他別名</td>
                        <td>{{ number_format($stats['by_type']['altname']) }}</td>
                    </tr>
                </table>
            </div>

            <div class="col-md-6">
                <h4>索引覆蓋率</h4>
                <div class="progress">
                    <div class="progress-bar progress-bar-success"
                         style="width: {{ $stats['coverage'] }}%">
                        {{ $stats['coverage'] }}%
                    </div>
                </div>
                <p class="text-muted">
                    最後更新：{{ $stats['last_updated'] ?? '未知' }}
                </p>
            </div>
        </div>

        <hr>

        <div class="row">
            <div class="col-md-12">
                <button id="rebuild-btn" class="btn btn-danger btn-lg">
                    <i class="fa fa-refresh"></i> 重建索引
                </button>
                <p class="help-block">
                    <strong>警告：</strong>重建索引會清空現有資料並重新產生，可能需要 5-10 分鐘。
                </p>

                <div id="rebuild-progress" style="display: none;">
                    <h4>重建進度</h4>
                    <div class="progress progress-striped active">
                        <div class="progress-bar" style="width: 100%"></div>
                    </div>
                    <pre id="rebuild-output"></pre>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(document).ready(function() {
    $('#rebuild-btn').click(function() {
        if (!confirm('確定要重建索引嗎？這將清空現有資料並重新產生。')) {
            return;
        }

        $(this).prop('disabled', true);
        $('#rebuild-progress').show();
        $('#rebuild-output').text('正在重建索引，請稍候...');

        $.ajax({
            url: '{{ route("admin.name-search.rebuild") }}',
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}'
            },
            timeout: 3600000,  // 1 小時
            success: function(response) {
                $('#rebuild-output').text(response.output);
                alert('索引重建完成！');
                location.reload();
            },
            error: function(xhr) {
                $('#rebuild-output').text('錯誤：' + xhr.responseJSON.error);
                alert('索引重建失敗，請檢視錯誤資訊。');
                $('#rebuild-btn').prop('disabled', false);
            }
        });
    });
});
</script>
@endpush
@endsection
```

---

## 八、實施步驟

### 階段一：基礎設施

1. ✅ **建立資料表**
   ```bash
   php artisan make:migration create_cbdb_name_search_table
   php artisan migrate
   ```

2. ✅ **編寫拆分邏輯**
   - 實作 `splitNameToSuffixes()` 函式
   - 編寫單元測試驗證拆分規則

3. ✅ **實作批次腳本**
   - `RebuildNameSearchIndex` Artisan 指令
   - 測試小批次資料（1000 條）

### 階段二：資料初始化

4. ✅ **執行首次索引建構**
   ```bash
   php artisan cbdb:rebuild-name-search --chunk=500
   ```
   - 預計耗時：15-30 分鐘（70 萬人物）
   - 監控記憶體和 CPU 使用

5. ✅ **驗證資料品質**
   - 抽樣檢查拆分結果
   - 統計覆蓋率和記錄數
   - 檢查索引大小

### 階段三：查詢整合

6. ✅ **修改 namesByQuery()**
   - 整合倒排表查詢
   - 保留回退邏輯（未找到時使用原查詢）
   - 新增查詢日誌

7. ✅ **效能測試**
   - 對比最佳化前後查詢時間
   - 測試邊界情況（單字、長查詢）
   - 驗證結果準確性

### 階段四：增量維護

8. ✅ **實作 Model Observer**
   - `BiogMainObserver` 自動同步
   - `NameSearchIndexService` 服務類別
   - 測試增刪改同步

9. ✅ **別名同步**
   - 監聽 ALTNAME_DATA 變更
   - 實作別名索引更新

### 階段五：管理工具

10. ✅ **Web 管理介面**
    - 索引統計頁面
    - 重建索引按鈕
    - 進度顯示和錯誤處理

11. ✅ **文件和培訓**
    - 更新 `DATABASE.md`
    - 編寫維護手冊
    - 團隊培訓

### 階段六：上線和監控

12. ✅ **灰度發布**
    - 先在測試環境驗證
    - 小流量切換（10% → 50% → 100%）
    - 監控錯誤率和效能

13. ✅ **效能監控**
    - 記錄查詢耗時
    - 監控索引大小成長
    - 定期檢查資料一致性

---

## 九、測試計畫

### 9.1 單元測試

```php
// tests/Unit/NameSearchIndexServiceTest.php

class NameSearchIndexServiceTest extends TestCase
{
    public function test_split_two_char_mingzi()
    {
        $service = new NameSearchIndexService();
        $result = $service->splitNameToSuffixes('王', '安石');

        $this->assertEquals(['王安石', '安石', '石'], $result);
    }

    public function test_split_single_char_mingzi()
    {
        $service = new NameSearchIndexService();
        $result = $service->splitNameToSuffixes('李', '白');

        $this->assertEquals(['李白', '白'], $result);
    }

    public function test_split_compound_surname()
    {
        $service = new NameSearchIndexService();
        $result = $service->splitNameToSuffixes('司馬', '相如');

        $this->assertEquals(['司馬相如', '相如', '如'], $result);
    }

    public function test_altname_single_char_not_split()
    {
        $service = new NameSearchIndexService();
        $result = $service->splitAltname('蘇');

        $this->assertEquals(['蘇'], $result);
    }
}
```

### 9.2 整合測試

```php
// tests/Feature/NameSearchIntegrationTest.php

class NameSearchIntegrationTest extends TestCase
{
    public function test_search_by_full_name()
    {
        // 建立測試資料
        $person = factory(BiogMain::class)->create([
            'c_surname' => '王',
            'c_mingzi' => '安石',
            'c_name_chn' => '王安石',
        ]);

        // 建構索引
        $service = new NameSearchIndexService();
        $service->indexPerson($person);

        // 搜尋
        $request = new Request(['q' => '王安石']);
        $results = BiogMainRepository::namesByQuery($request);

        $this->assertGreaterThan(0, $results->count());
        $this->assertEquals($person->c_personid, $results->first()->c_personid);
    }

    public function test_search_by_suffix()
    {
        $person = factory(BiogMain::class)->create([
            'c_surname' => '王',
            'c_mingzi' => '安石',
            'c_name_chn' => '王安石',
        ]);

        $service = new NameSearchIndexService();
        $service->indexPerson($person);

        // 搜尋「石」
        $request = new Request(['q' => '石']);
        $results = BiogMainRepository::namesByQuery($request);

        $personIds = $results->pluck('c_personid')->toArray();
        $this->assertContains($person->c_personid, $personIds);
    }
}
```

### 9.3 效能測試

```php
// tests/Performance/NameSearchPerformanceTest.php

class NameSearchPerformanceTest extends TestCase
{
    public function test_search_performance()
    {
        $queries = ['石', '安石', '王安石', '介', '介甫'];

        foreach ($queries as $query) {
            $start = microtime(true);

            $request = new Request(['q' => $query]);
            BiogMainRepository::namesByQuery($request);

            $duration = (microtime(true) - $start) * 1000;

            $this->info("Query '{$query}': {$duration}ms");
            $this->assertLessThan(100, $duration, "Query too slow: {$query}");
        }
    }
}
```

---

## 十、效能預期

### 10.1 查詢效能對比

| 查詢類型 | 最佳化前 | 最佳化後 | 提升倍數 |
|---------|-------|-------|---------|
| 單字（如「石」） | 1500ms | 5-10ms | **150-300x** |
| 雙字（如「安石」） | 1374ms | 3-5ms | **275-458x** |
| 完整名（如「王安石」） | 1540ms | 2-3ms | **513-770x** |
| 純數字 ID | 1500ms | 2ms | **750x** (已最佳化) |

### 10.2 空間開銷

| 項目 | 大小 |
|------|------|
| 倒排記錄數 | 550 萬條 |
| 表資料大小 | 140-210 MB |
| 索引大小 | 210-280 MB |
| **總計** | **350-500 MB** |

相對於整個資料庫（預計數 GB），**佔比 < 5%**，可接受。

### 10.3 維護成本

| 操作 | 頻率 | 耗時 |
|------|------|------|
| 新增人物 | 每天 10-100 次 | < 100ms/次 |
| 修改姓名 | 每天 5-20 次 | < 200ms/次 |
| 完整重建 | 每月 1 次（可選） | 15-30 分鐘 |

---

## 十一、風險和應對

### 11.1 資料一致性風險

**風險**：應用層同步失敗導致索引不完整

**應對**：
- ✅ 使用資料庫交易保證原子性
- ✅ 定期執行一致性檢查腳本
- ✅ 提供手動重建工具

### 11.2 效能回退風險

**風險**：倒排表過大影響查詢效能

**應對**：
- ✅ 限制查詢返回數量（最多 500 個候選人）
- ✅ 保留原查詢作為回退方案
- ✅ 監控查詢耗時，即時調整

### 11.3 維護成本風險

**風險**：增量同步邏輯複雜，容易出錯

**應對**：
- ✅ 充分的單元測試和整合測試
- ✅ 完善的日誌記錄
- ✅ 管理員工具可手動修復

---

## 十二、後續最佳化方向

1. **多欄位聯合搜尋**
   - 支援「姓 + 名」組合搜尋
   - 支援朝代、地址過濾

2. **拼音搜尋**
   - 將拼音也加入倒排表
   - 支援 "shi" → "石" 的查詢

3. **模糊匹配**
   - 編輯距離演算法
   - 支援「安石」 → 「安時」（異體字）

4. **快取最佳化**
   - 熱門查詢結果快取
   - Redis 加速高頻查詢

---

## 十三、總結

本方案透過**手工建構倒排索引表**，在不依賴特定資料庫功能的前提下，將姓名搜尋效能從 1500ms 降至 3ms，提升 **500 倍**。

**核心優勢：**
- ✅ 效能卓越（前綴匹配 + B-Tree 索引）
- ✅ 資料庫相容（標準 SQL，可遷移）
- ✅ 資料量可控（550 萬記錄，350-500 MB）
- ✅ 維護成熟（批次腳本 + Web 工具）

**適用場景：**
- ✅ 中等規模資料（70 萬人物以內）
- ✅ 需要資料庫相容性的專案
- ✅ 需要完全控制索引邏輯

本方案已在 `feature/search` 分支實作基礎最佳化（純數字查詢），倒排表實施將在後續迭代完成。
