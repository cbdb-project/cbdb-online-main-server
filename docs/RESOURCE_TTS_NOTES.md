# Resource TTS 注意事項

## ALTNAME_DATA 主鍵處理
- `resource_id` 原採用 `personid-sequence-alt_name_chn-type_code` 組合；因別名可能更新或包含 `-`（記錄中會轉寫成 `minus`），直接依賴此字串在名稱變更時會失敗。
- 改為從操作紀錄的 `resource_data` 取得 `c_personid`、`c_sequence`、`c_alt_name_type_code` 等欄位，若缺值再回退 `resource_original`。如此 `/operations` 在別名調整後仍能取得現況。

## 主鍵考量
- ALTNAME_DATA 實際主鍵為 `(c_personid, c_alt_name_type_code, c_alt_name_chn)`，`c_sequence` 僅為選填排序欄位並非 PK 一部分；查詢時應將 `c_alt_name_chn` 納入條件，避免抓到舊版本。

## TTS 中的 NULL
- 表單允許 `c_sequence` 留空，資料庫會存 `NULL`；在 TTS 上會顯示成 "NULL"。

## 排序唯一性
- 資料庫沒有針對 `c_sequence` 設唯一鍵（僅有主鍵限制），重新排序需由程式端控制，避免同一類別出現相同序號。

## BIOG_MAIN 特例
- `/operations` 顯示 BIOG_MAIN 現況時，直接透過 `BiogMain::find()` 取得，不依賴 `resource_id` 解析，因此不受上述別名問題影響。
