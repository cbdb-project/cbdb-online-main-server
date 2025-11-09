# CBDB_NAME_LIST 全文索引使用指南

## 概述

为 `CBDB_NAME_LIST.name` 字段添加了全文索引，使用 MySQL 的 ngram 解析器以支持中文分词搜索。

## Migration 文件

```bash
database/migrations/2025_11_09_143732_add_fulltext_index_to_cbdb_name_list_table.php
```

## 执行 Migration

```bash
# 应用 migration
php artisan migrate

# 如果需要回滚
php artisan migrate:rollback --step=1
```

执行后将创建索引：`idx_name_fulltext`

## 使用方法

### 1. 基本全文搜索

```php
use Illuminate\Support\Facades\DB;

// 搜索包含"張三"的姓名
$personIds = DB::table('CBDB_NAME_LIST')
    ->whereRaw('MATCH(name) AGAINST(? IN NATURAL LANGUAGE MODE)', ['張三'])
    ->distinct()
    ->pluck('c_personid');
```

### 2. 布尔模式搜索（更灵活）

```php
// 必须包含"張"且包含"三"
$personIds = DB::table('CBDB_NAME_LIST')
    ->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', ['+張 +三'])
    ->distinct()
    ->pluck('c_personid');

// 包含"張"或"王"
$personIds = DB::table('CBDB_NAME_LIST')
    ->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', ['張 王'])
    ->distinct()
    ->pluck('c_personid');

// 包含"張"但不包含"三"
$personIds = DB::table('CBDB_NAME_LIST')
    ->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', ['+張 -三'])
    ->distinct()
    ->pluck('c_personid');

// 前缀搜索
$personIds = DB::table('CBDB_NAME_LIST')
    ->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', ['張*'])
    ->distinct()
    ->pluck('c_personid');
```

### 3. 相关性排序

全文搜索可以返回相关性评分：

```php
$results = DB::table('CBDB_NAME_LIST')
    ->selectRaw('c_personid, name, MATCH(name) AGAINST(? IN NATURAL LANGUAGE MODE) AS relevance_score', ['張三'])
    ->whereRaw('MATCH(name) AGAINST(? IN NATURAL LANGUAGE MODE)', ['張三'])
    ->orderByDesc('relevance_score')
    ->limit(20)
    ->get();
```

## 在 BiogMainRepository::namesByQuery() 中的应用

### 优化方案：结合三种搜索策略

```php
static public function namesByQuery(Request $request, $num=20)
{
    $request->q = addslashes($request->q);
    $query = $request->q;

    if (!$query) {
        // 保持原有逻辑...
        return ...;
    }

    $queryLength = mb_strlen($query);

    // 策略选择
    if ($queryLength >= 3) {
        // 长查询（3+ 字符）：使用全文索引
        // 优势：支持中间匹配，有相关性排序
        $personIds = DB::table('CBDB_NAME_LIST')
            ->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', [$query . '*'])
            ->distinct()
            ->limit(500)
            ->pluck('c_personid')
            ->toArray();
    } elseif ($queryLength == 2) {
        // 2字查询：使用前缀索引（最快）
        $personIds = DB::table('CBDB_NAME_LIST')
            ->where('name', 'like', $query . '%')
            ->distinct()
            ->limit(500)
            ->pluck('c_personid')
            ->toArray();
    } else {
        // 单字查询：使用原有 BIOG_MAIN 查询（避免匹配过多）
        $names = BiogMain::select(...)
            ->leftJoin(...)
            ->where('BIOG_MAIN.c_name_chn', 'like', '%'.$query.'%')
            ->orWhere(...)
            ->paginate($num);

        $names->appends(['q' => $query])->links();
        return $names;
    }

    if (empty($personIds)) {
        return response()->json(['data' => [], 'total' => 0]);
    }

    // 用 personid 列表查询完整信息
    $names = BiogMain::select(
            'BIOG_MAIN.c_personid',
            'BIOG_MAIN.c_name_chn',
            'BIOG_MAIN.c_name',
            'DYNASTIES.c_dynasty_chn',
            'BIOG_MAIN.c_index_year',
            'ADDR_CODES.c_name_chn AS ADDR_c_name_chn',
            'A1.c_alt_name_chn as c_alt_name_chn_zi',
            'A2.c_alt_name_chn as c_alt_name_chn_hao'
        )
        ->leftJoin('DYNASTIES', 'DYNASTIES.c_dy', '=', 'BIOG_MAIN.c_dy')
        ->leftJoin('ADDR_CODES', 'ADDR_CODES.c_addr_id', '=', 'BIOG_MAIN.c_index_addr_id')
        ->leftJoin('ALTNAME_DATA as A1', function($join) {
            $join->on('A1.c_personid', '=', 'BIOG_MAIN.c_personid')
                 ->where('A1.c_alt_name_type_code', '=', 4);
        })
        ->leftJoin('ALTNAME_DATA as A2', function($join) {
            $join->on('A2.c_personid', '=', 'BIOG_MAIN.c_personid')
                 ->where('A2.c_alt_name_type_code', '=', 5);
        })
        ->whereIn('BIOG_MAIN.c_personid', $personIds)
        ->orderBy('BIOG_MAIN.c_personid', 'ASC')
        ->groupBy('BIOG_MAIN.c_personid')
        ->paginate($num);

    $names->appends(['q' => $query])->links();
    return $names;
}
```

## ngram 解析器说明

### 什么是 ngram？

ngram 是一种将文本分解为连续 n 个字符片段的方法。MySQL 的 ngram 解析器默认使用 `ngram_token_size=2`（bigram），适合中文搜索。

### 示例

对于姓名 "張三豐"：
- ngram (n=2) 分词结果：`张三`, `三豐`
- 这意味着搜索 "三豐"、"張三" 都能匹配到 "張三豐"

### 配置

默认的 `ngram_token_size=2` 对中文姓名搜索已经很合适。如果需要调整：

```sql
-- 查看当前配置
SHOW VARIABLES LIKE 'ngram_token_size';

-- 修改需要在 my.cnf 中设置（需要重启 MySQL）
[mysqld]
ngram_token_size=2
```

## 性能特点

### 全文索引 vs B-Tree 索引 vs 全表扫描

| 搜索类型 | B-Tree (`LIKE "query%"`) | 全文索引 (`MATCH AGAINST`) | 全表扫描 (`LIKE "%query%"`) |
|----------|--------------------------|----------------------------|------------------------------|
| 前缀匹配 | ✅ 最快 (ms 级) | ✅ 快 (ms 级) | ❌ 慢 (秒级) |
| 中间匹配 | ❌ 不支持 | ✅ 快 (ms 级) | ❌ 慢 (秒级) |
| 相关性排序 | ❌ 不支持 | ✅ 支持 | ❌ 不支持 |
| 布尔操作 | ❌ 不支持 | ✅ 支持 (+, -, *) | ❌ 不支持 |
| 索引大小 | 小 | 较大 | - |

### 推荐使用场景

1. **前缀匹配（2字）**：优先使用 B-Tree 索引 `LIKE "query%"`
   - 最快的查询方式
   - 适合常见的姓名查询

2. **完整姓名（3+ 字）**：使用全文索引 `MATCH AGAINST`
   - 支持更灵活的匹配
   - 提供相关性排序

3. **单字查询**：使用 BIOG_MAIN 直接查询
   - 避免 CBDB_NAME_LIST 匹配过多结果

## 监控和优化

### 检查索引使用情况

```sql
-- 查看全文索引信息
SHOW INDEX FROM CBDB_NAME_LIST WHERE Key_name = 'idx_name_fulltext';

-- 查看表大小
SELECT
    table_name,
    ROUND((data_length + index_length) / 1024 / 1024, 2) AS total_mb,
    ROUND(data_length / 1024 / 1024, 2) AS data_mb,
    ROUND(index_length / 1024 / 1024, 2) AS index_mb
FROM information_schema.tables
WHERE table_schema = 'CBDB' AND table_name = 'CBDB_NAME_LIST';
```

### 性能测试示例

```php
// 测试脚本
$queries = ['張', '張三', '蘇軾', '東坡居士'];

foreach ($queries as $query) {
    echo "查询: {$query}\n";

    // 方法1: B-Tree 前缀索引
    $start = microtime(true);
    $result1 = DB::table('CBDB_NAME_LIST')
        ->where('name', 'like', $query . '%')
        ->distinct()
        ->pluck('c_personid');
    $time1 = round((microtime(true) - $start) * 1000, 2);

    // 方法2: 全文索引
    $start = microtime(true);
    $result2 = DB::table('CBDB_NAME_LIST')
        ->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', [$query . '*'])
        ->distinct()
        ->pluck('c_personid');
    $time2 = round((microtime(true) - $start) * 1000, 2);

    echo "  B-Tree: {$time1} ms, {$result1->count()} 结果\n";
    echo "  Fulltext: {$time2} ms, {$result2->count()} 结果\n\n";
}
```

## 注意事项

1. **索引构建时间**：对于 200 万+ 条记录，创建全文索引可能需要几分钟时间
2. **磁盘空间**：全文索引会占用额外的磁盘空间（约为数据大小的 20-30%）
3. **维护开销**：插入/更新操作会稍慢，因为需要更新索引
4. **最小搜索长度**：ngram token size 为 2，意味着单字符搜索可能不走全文索引

## 回滚方案

如果遇到问题，可以安全地删除全文索引：

```bash
php artisan migrate:rollback --step=1
```

或手动删除：

```sql
ALTER TABLE CBDB_NAME_LIST DROP INDEX idx_name_fulltext;
```

删除索引不会影响数据，只会移除搜索加速功能。

## 参考资料

- [MySQL Full-Text Search Functions](https://dev.mysql.com/doc/refman/8.0/en/fulltext-search.html)
- [MySQL ngram Full-Text Parser](https://dev.mysql.com/doc/refman/8.0/en/fulltext-search-ngram.html)
- [Full-Text Search Boolean Mode](https://dev.mysql.com/doc/refman/8.0/en/fulltext-boolean.html)
