# namesByQuery() 性能优化分析

## 现状分析

当前 `BiogMainRepository::namesByQuery()` 方法使用以下查询策略：

```php
$names = BiogMain::select(...)
    ->leftJoin('DYNASTIES', ...)
    ->leftJoin('ADDR_CODES', ...)
    ->leftJoin('ALTNAME_DATA as A1', ...)
    ->leftJoin('ALTNAME_DATA as A2', ...)
    ->where('BIOG_MAIN.c_name_chn', 'like', '%'.$query.'%')
    ->orWhere('BIOG_MAIN.c_name', 'like', $query)
    ->orWhere(...)  // 多个 OR 条件
    ->paginate($num);
```

**问题**：
- 多个 `LIKE '%query%'` 条件无法使用索引，需要全表扫描
- 多个 LEFT JOIN 增加查询复杂度
- 对于常见姓名查询性能较差

## CBDB_NAME_LIST 表分析

### 表结构
```sql
CREATE TABLE `CBDB_NAME_LIST` (
  `c_personid` int DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `source` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  KEY `idx_c_personid` (`c_personid`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### 数据统计
- **记录数**：2,088,027 条
- **人物数**：653,555 人
- **平均每人名字变体**：3.19 个

### 包含的姓名来源
1. `BIOG_MAIN.c_name_chn` - 中文姓名
2. `BIOG_MAIN.c_surname_chn + BIOG_MAIN.c_mingzi_chn` - 姓+名组合
3. `BIOG_MAIN.c_name` - 英文姓名
4. `ALTNAME_DATA` 各类型别名（字、号等）
5. 特殊情况（满族名字、朝代+姓名组合等）

## 性能对比测试

### 测试 1: 单字查询 "張"
| 方法 | 耗时 | 匹配数 | 结果 |
|------|------|--------|------|
| BIOG_MAIN (当前) | **13.45 ms** ✅ | - | 20 条 |
| CBDB_NAME_LIST | 123.38 ms ❌ | 500+ IDs | 20 条 |
| **结论** | 当前方法更快 | - | - |

### 测试 2: 两字查询 "張三"
| 方法 | 耗时 | 匹配数 | 结果 |
|------|------|--------|------|
| BIOG_MAIN (当前) | 1374.29 ms ❌ | - | 20 条 |
| CBDB_NAME_LIST | **3.18 ms** ✅ | 40 IDs | 20 条 |
| **结论** | NAME_LIST 快 **432 倍** | - | - |

### 测试 3: 完整姓名 "蘇軾"
| 方法 | 耗时 | 匹配数 | 结果 |
|------|------|--------|------|
| BIOG_MAIN (当前) | 1540.42 ms ❌ | - | 2 条 |
| CBDB_NAME_LIST | **1.88 ms** ✅ | 1 ID | 1 条 |
| **结论** | NAME_LIST 快 **819 倍** | - | - |

### 查询模式性能对比

在 CBDB_NAME_LIST 上测试不同的查询模式：

| 查询 | 模式 | 耗时 | 结果数 | 可用索引 |
|------|------|------|--------|----------|
| 張三 | `LIKE "張三%"` | **3.15 ms** ✅ | 40 | ✅ 是 |
| 張三 | `LIKE "%張三%"` | 18,829 ms ❌ | 41 | ❌ 否 |
| 張三 | `= "張三"` | **3.97 ms** ✅ | 1 | ✅ 是 |

**关键发现**：
- 前缀匹配 (`LIKE "query%"`) 可以使用 `idx_name` 索引，性能优秀
- 全文匹配 (`LIKE "%query%"`) 无法使用索引，需要全表扫描
- 前缀匹配和全文匹配的结果数相差很小（40 vs 41），但性能差异巨大（3ms vs 18秒）

## 优化建议

### 方案一：混合策略（推荐）✅

根据查询长度使用不同策略：

```php
static public function namesByQuery(Request $request, $num=20)
{
    $request->q = addslashes($request->q);
    $query = $request->q;

    if (!$query) {
        // 保持原有逻辑...
    }

    // 关键优化：2+ 字符使用 CBDB_NAME_LIST
    if (mb_strlen($query) >= 2) {
        // Step 1: 快速从 CBDB_NAME_LIST 获取匹配的 personid
        $personIds = DB::table('CBDB_NAME_LIST')
            ->where('name', 'like', $query . '%')
            ->distinct()
            ->limit(500)  // 防止匹配过多
            ->pluck('c_personid')
            ->toArray();

        if (empty($personIds)) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        // Step 2: 用 personid 列表查询完整信息
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

    // 单字查询：保持原有方法（性能已经很好）
    $names = BiogMain::select(...)
        ->leftJoin(...)
        ->where('BIOG_MAIN.c_name_chn', 'like', '%'.$query.'%')
        ->orWhere(...)
        ->paginate($num);

    return $names;
}
```

**优势**：
- 2+ 字符查询性能提升 **100-800 倍**
- 单字查询保持现有性能
- 代码改动最小，风险低
- 向后兼容

**预期性能提升**：
- "張三" 类查询：1374ms → 3ms (**99.8% 提升**)
- "蘇軾" 类查询：1540ms → 2ms (**99.9% 提升**)
- "張" 类查询：13ms（保持不变）

### 方案二：添加全文索引

如果需要支持中间字符匹配且保持高性能，可以添加全文索引：

```sql
ALTER TABLE CBDB_NAME_LIST ADD FULLTEXT INDEX idx_name_fulltext (name) WITH PARSER ngram;
```

然后使用：
```php
$personIds = DB::table('CBDB_NAME_LIST')
    ->whereRaw('MATCH(name) AGAINST(? IN BOOLEAN MODE)', [$query])
    ->distinct()
    ->pluck('c_personid');
```

**优势**：
- 支持中文分词搜索
- 可以匹配姓名任意位置

**劣势**：
- 需要修改数据库结构
- ngram 解析器可能增加索引大小
- 需要测试和调优

### 方案三：ElasticSearch（长期方案）

对于更复杂的搜索需求，可以考虑引入 ElasticSearch：
- 支持拼音搜索
- 支持模糊匹配
- 支持相关性排序
- 可扩展性强

但需要额外的基础设施和维护成本。

## 实施建议

1. **短期**：实施方案一（混合策略）
   - 工作量小，1-2 小时即可完成
   - 立即获得 99%+ 性能提升
   - 风险低，易于回滚

2. **中期**：评估用户搜索行为
   - 收集搜索日志，分析查询模式
   - 确定是否需要全文索引或更高级方案

3. **长期**：根据业务需求决定是否引入 ElasticSearch

## 测试建议

实施优化后，建议进行以下测试：

1. **单元测试**：确保搜索结果的准确性
2. **性能测试**：对比优化前后的响应时间
3. **边界测试**：
   - 空查询
   - 单字查询
   - 多字查询
   - 特殊字符查询
   - 超长查询

## 潜在风险

1. **结果差异**：前缀匹配可能漏掉中间/末尾匹配的结果
   - **缓解方案**：如果前缀匹配结果 < 阈值，回退到全文匹配

2. **CBDB_NAME_LIST 数据不完整**：如果该表未包含所有姓名变体
   - **缓解方案**：定期检查数据完整性，确保与 BIOG_MAIN 同步

3. **性能退化**：如果 500 个 ID 的 WHERE IN 性能不佳
   - **缓解方案**：调整 limit 值，或使用临时表

## 结论

**强烈建议实施方案一**，理由：
- ✅ 性能提升显著（99%+）
- ✅ 实施成本低
- ✅ 风险可控
- ✅ 用户体验大幅改善

特别是对于常见的两字以上姓名查询（如"張三"、"蘇軾"），响应时间从 1-2 秒降至 2-3 毫秒，用户体验将有质的飞跃。
