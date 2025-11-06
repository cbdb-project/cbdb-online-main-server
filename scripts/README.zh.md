# CBDB 腳本工具

此目錄包含 CBDB Online 項目的實用工具腳本。

## fetch_wikidata_cbdb.py

此腳本查詢 Wikidata SPARQL 端點，獲取具有 CBDB 人物 ID 的人物記錄，並以結構化 JSON 格式輸出。

### 系統需求

- Python 3.6+
- `requests` 函式庫

安裝依賴：
```bash
pip install requests
```

### 使用方法

```bash
# 基本用法 - 輸出到 wikidata_cbdb_persons.json
python3 scripts/fetch_wikidata_cbdb.py

# 指定自定義輸出檔案
python3 scripts/fetch_wikidata_cbdb.py -o custom_output.json

# 啟用詳細輸出
python3 scripts/fetch_wikidata_cbdb.py --verbose
```

### 輸出格式

腳本生成具有以下結構的 JSON 檔案：

```json
{
  "generated_at": "2025-11-05T00:00:00Z",
  "source": "Wikidata SPARQL",
  "schema_version": 1,
  "records": [
    {
      "cbdb_personid": 12345,
      "wikidata_qid": "Q125054",
      "wikipedia": {
        "zh": "司马光",
        "en": "Sima_Guang",
        "ja": "司馬光"
      }
    }
  ]
}
```

### 功能特色

- 查詢 Wikidata 中所有具有 CBDB 人物 ID（屬性 P497）的人物
- 在可用時提取中文、英文和日文維基百科條目標題
- 包含網路故障的重試邏輯
- 提供獲取資料的詳細統計資訊
- 優雅地處理錯誤並提供資訊性輸出

### 注意事項

- 腳本遵守 Wikidata 的查詢限制並包含適當的延遲
- 維基百科條目標題儲存為原始UTF-8格式，便於閱讀和顯示
- 僅包含"人類"（Q5）實例的人物
- 結果按 CBDB 人物 ID 排序以保持一致性

### 輸出統計

腳本執行完成後會顯示：
- 總記錄數
- 具有中文維基百科條目的記錄數
- 具有英文維基百科條目的記錄數
- 具有日文維基百科條目的記錄數

### 錯誤處理

- 網路請求失敗時自動重試（最多 3 次）
- 無效的 CBDB ID 格式會被跳過並記錄警告
- 解析錯誤會被捕獲並繼續處理其他記錄

### SPARQL 查詢說明

腳本使用的 SPARQL 查詢會尋找：
- 屬於人類（wdt:P31 wd:Q5）的實體
- 具有 CBDB 人物 ID（wdt:P497）的實體
- 可選：中文維基百科站點連結
- 可選：英文維基百科站點連結
- 可選：日文維基百科站點連結

這確保了只有真實的人物記錄被包含在結果中，並且盡可能多地收集相關的維基百科資訊。