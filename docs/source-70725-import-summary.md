# CBDB source `70725` 自动录入总结

本文总结本次将「浙江大学图书馆中国历代墓志数据库」对应关系批量写入 CBDB prod 的工作。

## 目标

将浙大墓志库中，已经整理出的、可直接对应到 CBDB 人物的记录，写入 CBDB `BIOG_SOURCE_DATA`，统一使用：

- `c_textid = 70725`
- `c_pages = stone_id`
- 其他浙大侧定位信息与匹配信息写入 `c_notes`

对应的 `TEXT_CODES` 条目是：

- `70725 = 浙江大學圖書館中國歷代墓誌數據庫`

## 本次采用的 source of truth

批量导入主体使用这份 CSV：

- exports/zju_cbdb_people_with_epitaph_related_source.csv

这份 CSV 共 `1251` 条，字段足以直接拼出写入 `c_notes` 所需的信息，包括：

- `cbdb_person_id`
- `person_name_in_stone`
- `role_in_stone`
- `match_status`
- `match_method`
- `stone_id`
- `stone_number`
- `rubbing_id`
- `identifier`
- `custom_title`
- `dynasty`
- `sub_library_type`

过滤口径：姓名、朝代完全匹配，且已有一条「墓志」相关出处记录。

## 写入规则

每条写入记录遵循以下规则：

- `BIOG_SOURCE_DATA.c_personid = cbdb_person_id`
- `BIOG_SOURCE_DATA.c_textid = 70725`
- `BIOG_SOURCE_DATA.c_pages = stone_id`
- `BIOG_SOURCE_DATA.c_notes` 统一写成：

```text
ZJU epitaph mapping: custom_title=...; stone_id=...; stone_number=...; rubbing_id=...; identifier=...; dynasty=...; sub_library_type=...; matched_cbdb_person_id=...; person_name_in_stone=...; role_in_stone=...; match_status=...; match_method=...
```

导入使用的是 `mode=direct` + `operation=create`，不是 proposal。

## 执行方式

本次批量导入使用脚本：

- scripts/push_cbdb_sources.py

脚本执行逻辑是：

1. 读取 CSV
2. 调用 `cbdbapi/person?id=...&o=json` 检查该人物是否已经存在：
   - `SourceId = 70725`
   - `Pages = stone_id`
3. 已存在则跳过
4. 不存在则调用 prod mutate API 直接写入

prod 使用的正确接口主机是：

- `https://input.cbdb.fas.harvard.edu/api/v2/mutate`

## 本次结果

### 1. 批量主体

针对 CSV 中的 `1251` 条记录，最终全部处理完成：

- 主体执行汇总：
  - `processed = 1251`
  - `submitted = 1246`
  - `skipped_existing = 3`
  - `failed = 2`

其中：

- `3` 条在主体运行前已经存在，因此被正确跳过
- `2` 条在首次全量运行时因远端连接重置而未成功写入

### 2. 补跑失败项

首次全量运行中失败的 2 条是：

- `143227 / 8a8fbda74ca22703014ca22a07741261`
- `143719 / 8a8fbda74ca22703014ca229e3fd110d`

随后已单独补跑，补跑结果：

- `processed = 2`
- `submitted = 2`
- `failed = 0`

因此，CSV 主体中的 `1251` 条记录最终已全部写入或确认存在。

### 3. 总量核对

最终在 CBDB prod 中查询：

- `SELECT COUNT(*) FROM BIOG_SOURCE_DATA WHERE c_textid = 70725`

结果为：

- `1252` 条

这个数字与预期一致。

构成为：

- 本次 CSV 主体成功覆盖的 `1251` 条
- 加上 1 条不在该 CSV 里、但此前已写入 prod 的记录

这条额外记录是：

- `person_id = 67`
- `stone_id = 40288b957e0f6f86017f8cc102f20691`
- 对应人物：`陳貫`

它属于更宽泛口径下的自动匹配结果，不属于本次「已有墓志相关出处」这份 CSV 子集。

## 结果文件

本次执行的详细逐条日志记录在：

- cbdb_source_push_results.jsonl

该文件包含：

- `submitted`
- `skipped_existing`
- `precheck_error`
- 对应的 `person_id`
- `stone_id`
- API 返回结果

注意：

- 日志中有更早本地 dry-run 产生的少量历史 `precheck_error`
- 它们不代表本次 prod 全量运行失败
- 本次 prod 全量真正未成功的只有上面那 2 条，且已补跑完成

## 结论

截至本次执行完成：

- `TEXT_CODES 70725` 在 prod 中共有 `1252` 条 `BIOG_SOURCE_DATA`
- 其中本次按 CSV 自动导入并完成核对的是 `1251` 条
- 批量导入、重复跳过、失败补跑这三部分都已完成
