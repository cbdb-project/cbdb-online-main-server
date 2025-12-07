# 單向關係修復工具說明

本專案提供 `/admin/unidirectional-relationship-repair` 介面,協助管理員修復 CBDB 資料庫中的單向親屬關係和社會關係。本頁面由 `UnidirectionalRelationshipRepairController` 與 `resources/views/admin/unidirectional-relationship-repair.blade.php` 驅動,僅對活躍管理員開放。

## 目錄

- [功能摘要](#功能摘要)
- [親屬關係修復](#親屬關係修復)
- [社會關係修復](#社會關係修復)
- [使用場景](#使用場景)
- [操作流程](#操作流程)
- [技術細節](#技術細節)
- [注意事項](#注意事項)
- [故障排除](#故障排除)

---

## 功能摘要

單向關係修復工具用於為已存在的單向親屬關係或社會關係創建對應的反向關係記錄。在 CBDB 資料庫中,關係應當是雙向的(例如:A 是 B 的父親,則 B 應是 A 的兒子),但由於歷史錄入原因,部分關係可能僅在一個方向上存在。

**權限要求**:此功能僅限活躍管理員使用(`canRunBatchImport()` 權限)。

**主要功能**:
- 親屬關係修復:為 `KIN_DATA` 表中的單向關係創建反向記錄
- 社會關係修復:為 `ASSOC_DATA` 表中的單向關係創建反向記錄
- 自動驗證:確保輸入參數能唯一定位一條記錄
- 重複檢查:避免創建已存在的反向關係
- 資料保留:新記錄繼承原記錄的來源、備註等資訊

---

## 親屬關係修復

### 資料表

修復 `KIN_DATA` 表中的單向親屬關係。

### 輸入參數

| 參數 | 類型 | 說明 |
|------|------|------|
| `c_personid` | 整數 | 當前記錄中的人物 ID(關係主體) |
| `c_kin_id` | 整數 | 當前記錄中的親屬 ID(關係對象) |
| `c_kin_code` | 整數 | 當前記錄中的親屬關係代碼 |
| `new_c_kin_code` | 整數 | 反向關係應使用的親屬關係代碼 |

### 操作邏輯

1. **檢索記錄**:根據 `c_personid`、`c_kin_id`、`c_kin_code` 三個參數檢索 `KIN_DATA` 表
2. **唯一性驗證**:
   - 若找到多條記錄,返回錯誤並列出所有記錄
   - 若未找到記錄,返回 404 錯誤
   - 僅找到一條記錄時繼續處理
3. **重複檢查**:查詢反向關係是否已存在
4. **創建反向記錄**:
   - 交換 `c_personid` 和 `c_kin_id`
   - 使用 `new_c_kin_code` 作為關係代碼
   - 繼承原記錄的 `c_source`、`c_pages`、`c_notes`
   - 標記 `c_autogen_notes` 為「由單向關係修復工具自動創建」
   - 記錄創建者和創建時間

### 使用範例

**情境 1:父子關係修復**

假設資料庫中存在:
- A(c_personid=100) 是 B(c_kin_id=200) 的父親(c_kin_code=1)
- 但缺少反向關係:B 是 A 的兒子

輸入參數:
```
c_personid: 100
c_kin_id: 200
c_kin_code: 1
new_c_kin_code: 2  (假設 2 代表「兒子」)
```

系統會創建:
- B(c_personid=200) 是 A(c_kin_id=100) 的兒子(c_kin_code=2)

**情境 2:夫妻關係修復**

假設資料庫中存在:
- 張三(c_personid=300) 是 李四(c_kin_id=400) 的配偶(c_kin_code=10)
- 但缺少反向關係:李四 是 張三 的配偶

輸入參數:
```
c_personid: 300
c_kin_id: 400
c_kin_code: 10
new_c_kin_code: 10  (配偶關係是對稱的)
```

系統會創建:
- 李四(c_personid=400) 是 張三(c_kin_id=300) 的配偶(c_kin_code=10)

### 相關代碼表

親屬關係代碼定義在 `KINSHIP_CODES` 表中:
- `c_kincode`:親屬關係代碼
- `c_kinrel_chn`:中文親屬關係名稱
- `c_kinrel`:英文親屬關係名稱
- `c_kin_pair1`、`c_kin_pair2`:反向關係代碼

**常見關係代碼範例**(實際代碼請查閱資料庫):
- 父 ↔ 子
- 母 ↔ 女
- 兄 ↔ 弟
- 配偶 ↔ 配偶(對稱關係)

---

## 社會關係修復

### 資料表

修復 `ASSOC_DATA` 表中的單向社會關係。

### 輸入參數

| 參數 | 類型 | 說明 |
|------|------|------|
| `c_personid` | 整數 | 當前記錄中的人物 ID(關係主體) |
| `c_assoc_id` | 整數 | 當前記錄中的關聯人物 ID(關係對象) |
| `c_assoc_code` | 整數 | 當前記錄中的社會關係代碼 |
| `new_c_assoc_code` | 整數 | 反向關係應使用的社會關係代碼 |

### 操作邏輯

1. **檢索記錄**:根據 `c_personid`、`c_assoc_id`、`c_assoc_code` 三個參數檢索 `ASSOC_DATA` 表
2. **唯一性驗證**:
   - 若找到多條記錄,返回錯誤並列出所有記錄
   - 若未找到記錄,返回 404 錯誤
   - 僅找到一條記錄時繼續處理
3. **重複檢查**:查詢反向關係是否已存在(檢查關鍵欄位組合)
4. **創建反向記錄**:
   - 交換 `c_personid` 和 `c_assoc_id`
   - 使用 `new_c_assoc_code` 作為關係代碼
   - 繼承原記錄的所有欄位(時間、地點、機構、來源等)
   - 記錄創建者和創建時間

### 繼承欄位

社會關係記錄包含大量欄位,反向記錄會繼承以下資訊:
- **基本欄位**:`c_kin_code`、`c_kin_id`、`c_assoc_kin_code`、`c_assoc_kin_id`、`c_text_title`
- **第三方資訊**:`c_tertiary_personid`、`c_tertiary_type_notes`
- **頻次與序列**:`c_assoc_count`、`c_sequence`
- **時間範圍**:`c_assoc_first_year`、`c_assoc_last_year` 及相關年號、月日、干支等欄位
- **地點機構**:`c_addr_id`、`c_inst_code`、`c_inst_name_code`
- **主題分類**:`c_litgenre_code`、`c_occasion_code`、`c_topic_code`、`c_assoc_claimer_id`
- **文獻來源**:`c_source`、`c_pages`、`c_notes`

### 使用範例

**情境 1:師生關係修復**

假設資料庫中存在:
- 王老師(c_personid=500) 是 張學生(c_assoc_id=600) 的老師(c_assoc_code=10)
- 但缺少反向關係:張學生 是 王老師 的學生

輸入參數:
```
c_personid: 500
c_assoc_id: 600
c_assoc_code: 10
new_c_assoc_code: 11  (假設 11 代表「學生」)
```

系統會創建:
- 張學生(c_personid=600) 是 王老師(c_assoc_id=500) 的學生(c_assoc_code=11)
- 繼承原記錄的時間(如拜師年份)、地點(如書院地址)、來源文獻等資訊

**情境 2:同僚關係修復**

假設資料庫中存在:
- 李甲(c_personid=700) 與 王乙(c_assoc_id=800) 是同僚(c_assoc_code=20)
- 但缺少反向關係:王乙 與 李甲 是同僚

輸入參數:
```
c_personid: 700
c_assoc_id: 800
c_assoc_code: 20
new_c_assoc_code: 20  (同僚關係是對稱的)
```

系統會創建:
- 王乙(c_personid=800) 與 李甲(c_assoc_id=700) 是同僚(c_assoc_code=20)

### 相關代碼表

社會關係代碼定義在 `ASSOC_CODES` 表中:
- `c_assoc_code`:社會關係代碼
- `c_assoc_desc_chn`:中文關係描述
- `c_assoc_desc`:英文關係描述
- `c_assoc_pair`、`c_assoc_pair2`:反向關係代碼

**常見關係代碼範例**(實際代碼請查閱資料庫):
- 師 ↔ 生
- 友 ↔ 友(對稱關係)
- 同僚 ↔ 同僚(對稱關係)
- 主管 ↔ 下屬

---

## 使用場景

### 場景 1:資料清理專案

在進行資料品質檢查時,發現大量單向關係需要批次修復:

1. 使用 SQL 查詢找出所有單向關係
2. 逐條在修復工具中輸入參數
3. 系統自動創建反向記錄並保留原始資料的來源資訊

### 場景 2:個別記錄修正

在編輯人物資料時,發現某個關係缺少反向記錄:

1. 記錄當前關係的三個關鍵參數
2. 在修復工具中輸入參數
3. 立即創建反向關係,無需手動錄入所有欄位

### 場景 3:關係代碼更正

發現某個關係使用了錯誤的代碼,需要創建正確的反向關係:

1. 確認正確的反向關係代碼
2. 使用修復工具創建正確的反向記錄
3. 如需要,可手動刪除錯誤的原記錄(工具不會自動刪除)

---

## 操作流程

### 步驟 1:查詢單向關係

**親屬關係查詢範例**:
```sql
-- 查找缺少反向關係的親屬記錄
SELECT k1.c_personid, k1.c_kin_id, k1.c_kin_code, kc.c_kinrel_chn
FROM KIN_DATA k1
JOIN KINSHIP_CODES kc ON k1.c_kin_code = kc.c_kincode
LEFT JOIN KIN_DATA k2 ON k1.c_personid = k2.c_kin_id
    AND k1.c_kin_id = k2.c_personid
    AND k2.c_kin_code = kc.c_kin_pair1
WHERE k2.c_personid IS NULL
LIMIT 100;
```

**社會關係查詢範例**:
```sql
-- 查找缺少反向關係的社會關係記錄
SELECT a1.c_personid, a1.c_assoc_id, a1.c_assoc_code, ac.c_assoc_desc_chn
FROM ASSOC_DATA a1
JOIN ASSOC_CODES ac ON a1.c_assoc_code = ac.c_assoc_code
LEFT JOIN ASSOC_DATA a2 ON a1.c_personid = a2.c_assoc_id
    AND a1.c_assoc_id = a2.c_personid
    AND a2.c_assoc_code = ac.c_assoc_pair
WHERE a2.c_personid IS NULL
LIMIT 100;
```

### 步驟 2:查詢反向關係代碼

**親屬關係代碼查詢**:
```sql
-- 根據當前關係代碼查詢對應的反向代碼
SELECT c_kincode, c_kinrel_chn, c_kin_pair1, c_kin_pair2
FROM KINSHIP_CODES
WHERE c_kincode = 1;  -- 將 1 替換為實際的關係代碼
```

**社會關係代碼查詢**:
```sql
-- 根據當前關係代碼查詢對應的反向代碼
SELECT c_assoc_code, c_assoc_desc_chn, c_assoc_pair, c_assoc_pair2
FROM ASSOC_CODES
WHERE c_assoc_code = 10;  -- 將 10 替換為實際的關係代碼
```

### 步驟 3:訪問修復頁面

登入系統後,點擊側邊欄的「單向關係修復」選單項,或直接訪問:
```
https://input.cbdb.fas.harvard.edu/admin/unidirectional-relationship-repair
```

### 步驟 4:填寫表單

根據要修復的關係類型,在對應的表單中填寫參數:

**親屬關係修復表單**:
- 當前單向關係中的 c_personid
- 當前單向關係中的 c_kin_id
- 當前單向關係中的 c_kin_code
- 新創建的 c_kin_code(反向關係代碼)

**社會關係修復表單**:
- 當前單向關係中的 c_personid
- 當前單向關係中的 c_assoc_id
- 當前單向關係中的 c_assoc_code
- 新創建的 c_assoc_code(反向關係代碼)

### 步驟 5:提交並驗證

1. 點擊「修復親屬關係」或「修復社會關係」按鈕
2. 系統會彈出確認對話框
3. 確認後,系統執行修復操作
4. 查看返回的成功或錯誤訊息
5. 如果成功,頁面會顯示原始關係和新建關係的詳細資訊

### 步驟 6:驗證修復結果

**查詢新創建的親屬關係**:
```sql
SELECT * FROM KIN_DATA
WHERE c_personid = [新記錄的 c_personid]
  AND c_kin_id = [新記錄的 c_kin_id]
  AND c_kin_code = [新記錄的 c_kin_code]
ORDER BY c_created_date DESC
LIMIT 1;
```

**查詢新創建的社會關係**:
```sql
SELECT * FROM ASSOC_DATA
WHERE c_personid = [新記錄的 c_personid]
  AND c_assoc_id = [新記錄的 c_assoc_id]
  AND c_assoc_code = [新記錄的 c_assoc_code]
ORDER BY c_created_date DESC
LIMIT 1;
```

---

## 技術細節

### 資料庫事務

所有修復操作使用資料庫事務確保資料一致性:

```php
DB::beginTransaction();
try {
    // 1. 檢索現有記錄
    // 2. 驗證唯一性
    // 3. 檢查重複
    // 4. 創建新記錄
    DB::commit();
} catch (Exception $e) {
    DB::rollBack();
    // 返回錯誤
}
```

### 外鍵約束

創建的記錄會自動通過外鍵約束驗證:
- `c_personid` 和 `c_kin_id`/`c_assoc_id` 必須存在於 `BIOG_MAIN` 表
- `c_kin_code` 必須存在於 `KINSHIP_CODES` 表
- `c_assoc_code` 必須存在於 `ASSOC_CODES` 表

如果違反外鍵約束,操作會自動回滾並返回錯誤。

### 主鍵衝突處理

**親屬關係主鍵**:(`c_kin_code`, `c_kin_id`, `c_personid`)

如果反向關係已存在(主鍵衝突),系統會:
1. 在創建前檢查是否存在
2. 若存在,返回「反向關係已存在,無需創建」
3. 不會嘗試插入重複記錄

**社會關係主鍵**:(`c_assoc_code`, `c_assoc_id`, `c_assoc_kin_code`, `c_assoc_kin_id`, `c_kin_code`, `c_kin_id`, `c_personid`, `c_text_title`, `c_assoc_first_year`)

社會關係的主鍵較複雜,系統會檢查所有關鍵欄位的組合。

### 日誌記錄

所有操作都會記錄到 `storage/logs/laravel.log`:
- 成功操作:記錄創建的記錄詳情
- 失敗操作:記錄錯誤訊息和堆疊追蹤

查看日誌:
```bash
tail -f storage/logs/laravel.log | grep "relationship repair"
```

### 權限檢查

系統在兩個層級檢查權限:

1. **中介軟體層**:
   ```php
   $this->middleware('auth');
   $this->middleware(function ($request, $next) {
       if (!Auth::user() || !Auth::user()->canRunBatchImport()) {
           abort(403, '此功能僅限活躍管理員使用');
       }
       return $next($request);
   });
   ```

2. **路由層**:路由定義在 `admin` 中介軟體群組內

---

## 注意事項

### 重要提醒

1. **唯一性要求**:
   - 系統要求輸入的參數組合能夠唯一定位一條記錄
   - 如果找到多條記錄,操作會被中止
   - 請確保輸入的參數足夠精確

2. **重複檢查**:
   - 系統會自動檢查反向關係是否已存在
   - 如果已存在,不會重複創建
   - 這是正常情況,表示關係已經是雙向的

3. **關係代碼正確性**:
   - 請確保 `new_c_kin_code` 或 `new_c_assoc_code` 是正確的反向關係代碼
   - 錯誤的關係代碼會導致語義錯誤(如:父親 ↔ 母親)
   - 建議先查詢 `KINSHIP_CODES` 或 `ASSOC_CODES` 確認代碼

4. **資料完整性**:
   - 創建的反向記錄會保留原記錄的來源、備註等資訊
   - 這確保了資料的追溯性
   - `c_autogen_notes` 會標記記錄是由工具自動創建的

5. **權限限制**:
   - 此功能僅限活躍管理員使用
   - 普通用戶無法訪問此頁面
   - 如需權限,請聯繫系統管理員

### 不會自動處理的情況

工具**不會**自動處理以下情況:

1. **刪除錯誤記錄**:工具只創建新記錄,不會刪除或修改原記錄
2. **批次處理**:目前不支援一次修復多條關係,需逐條處理
3. **關係代碼推斷**:工具不會自動推斷反向關係代碼,需手動指定
4. **資料驗證**:工具假設原記錄是正確的,不會驗證資料的語義正確性

### 建議工作流程

1. **先查詢後修復**:
   - 使用 SQL 查詢確認單向關係確實存在
   - 查詢反向關係代碼
   - 然後使用工具修復

2. **小批次處理**:
   - 建議每次處理 10-20 條記錄
   - 處理後驗證結果
   - 再繼續下一批

3. **保留查詢記錄**:
   - 將使用的 SQL 查詢保存下來
   - 便於後續批次使用
   - 方便其他管理員參考

4. **文檔記錄**:
   - 記錄修復的原因
   - 記錄修復的數量
   - 便於日後審計

---

## 故障排除

### 問題 1:找到多條記錄

**錯誤訊息**:
```
檢索到多條記錄（3 條）,請檢查輸入參數是否正確。
```

**原因**:
- 輸入的參數組合不夠精確,匹配到多條記錄
- `KIN_DATA` 或 `ASSOC_DATA` 表中存在重複資料

**解決方案**:
1. 檢查原始資料是否真的有重複
2. 如果有重複,需要先在資料庫層面清理重複資料
3. 清理後再使用修復工具

**檢查重複的 SQL**:
```sql
-- 檢查親屬關係重複
SELECT c_personid, c_kin_id, c_kin_code, COUNT(*) as cnt
FROM KIN_DATA
GROUP BY c_personid, c_kin_id, c_kin_code
HAVING cnt > 1;

-- 檢查社會關係重複
SELECT c_personid, c_assoc_id, c_assoc_code, COUNT(*) as cnt
FROM ASSOC_DATA
GROUP BY c_personid, c_assoc_id, c_assoc_code
HAVING cnt > 1;
```

### 問題 2:未找到記錄

**錯誤訊息**:
```
未找到符合條件的親屬關係記錄。
```

**原因**:
- 輸入的參數有誤(可能是 ID 或代碼錯誤)
- 記錄已被刪除
- 參數順序錯誤(將 c_personid 和 c_kin_id 顛倒)

**解決方案**:
1. 重新查詢資料庫確認記錄是否存在
2. 檢查參數是否輸入正確
3. 確認是否誤將 c_personid 和 c_kin_id 顛倒

**驗證記錄存在的 SQL**:
```sql
-- 驗證親屬關係
SELECT * FROM KIN_DATA
WHERE c_personid = [輸入的 c_personid]
  AND c_kin_id = [輸入的 c_kin_id]
  AND c_kin_code = [輸入的 c_kin_code];

-- 驗證社會關係
SELECT * FROM ASSOC_DATA
WHERE c_personid = [輸入的 c_personid]
  AND c_assoc_id = [輸入的 c_assoc_id]
  AND c_assoc_code = [輸入的 c_assoc_code];
```

### 問題 3:反向關係已存在

**錯誤訊息**:
```
反向關係已存在,無需創建。
```

**原因**:
- 反向關係記錄已經存在
- 關係已經是雙向的

**解決方案**:
這是正常情況,無需處理。說明該關係已經完整,不需要修復。

**驗證反向關係的 SQL**:
```sql
-- 驗證親屬反向關係
SELECT * FROM KIN_DATA
WHERE c_personid = [原記錄的 c_kin_id]
  AND c_kin_id = [原記錄的 c_personid]
  AND c_kin_code = [new_c_kin_code];

-- 驗證社會反向關係
SELECT * FROM ASSOC_DATA
WHERE c_personid = [原記錄的 c_assoc_id]
  AND c_assoc_id = [原記錄的 c_personid]
  AND c_assoc_code = [new_c_assoc_code];
```

### 問題 4:外鍵約束失敗

**錯誤訊息**:
```
修復過程中發生錯誤:SQLSTATE[23000]: Integrity constraint violation
```

**原因**:
- `c_personid` 或 `c_kin_id`/`c_assoc_id` 在 `BIOG_MAIN` 表中不存在
- 關係代碼在 `KINSHIP_CODES` 或 `ASSOC_CODES` 表中不存在

**解決方案**:
1. 驗證人物 ID 是否存在於 `BIOG_MAIN` 表
2. 驗證關係代碼是否存在於相應的代碼表
3. 如果人物或代碼不存在,需先創建

**驗證 SQL**:
```sql
-- 驗證人物是否存在
SELECT c_personid, c_name_chn FROM BIOG_MAIN
WHERE c_personid IN ([c_personid], [c_kin_id/c_assoc_id]);

-- 驗證親屬關係代碼
SELECT * FROM KINSHIP_CODES
WHERE c_kincode = [new_c_kin_code];

-- 驗證社會關係代碼
SELECT * FROM ASSOC_CODES
WHERE c_assoc_code = [new_c_assoc_code];
```

### 問題 5:權限不足

**錯誤訊息**:
```
403 Forbidden
此功能僅限活躍管理員使用
```

**原因**:
- 當前用戶不是活躍管理員
- 用戶未登入

**解決方案**:
1. 確認已登入系統
2. 聯繫系統管理員申請活躍管理員權限
3. 檢查用戶的 `canRunBatchImport()` 權限設定

### 問題 6:查看操作日誌

如果遇到其他問題,可以查看系統日誌:

```bash
# 查看最近的錯誤日誌
tail -100 storage/logs/laravel.log | grep -i "error\|repair"

# 即時查看日誌
tail -f storage/logs/laravel.log

# 查看特定日期的日誌
cat storage/logs/laravel-2025-12-07.log | grep "repair"
```

---

## 相關資料表

### 親屬關係相關

- **KIN_DATA**:親屬關係資料表
  - 主鍵:(`c_kin_code`, `c_kin_id`, `c_personid`)
  - 主要欄位:`c_source`, `c_pages`, `c_notes`, `c_autogen_notes`

- **KINSHIP_CODES**:親屬關係代碼表
  - 主鍵:`c_kincode`
  - 關係欄位:`c_kin_pair1`, `c_kin_pair2`(反向關係代碼)
  - 描述欄位:`c_kinrel_chn`, `c_kinrel`

### 社會關係相關

- **ASSOC_DATA**:社會關係資料表
  - 主鍵:(`c_assoc_code`, `c_assoc_id`, `c_assoc_kin_code`, `c_assoc_kin_id`, `c_kin_code`, `c_kin_id`, `c_personid`, `c_text_title`, `c_assoc_first_year`)
  - 包含大量欄位(時間、地點、機構、主題等)

- **ASSOC_CODES**:社會關係代碼表
  - 主鍵:`c_assoc_code`
  - 關係欄位:`c_assoc_pair`, `c_assoc_pair2`(反向關係代碼)
  - 描述欄位:`c_assoc_desc_chn`, `c_assoc_desc`

### 人物資料

- **BIOG_MAIN**:人物基本資料表
  - 主鍵:`c_personid`
  - 所有關係記錄的 `c_personid`、`c_kin_id`、`c_assoc_id` 必須存在於此表

---

## 相關文件

- [DATABASE.md](DATABASE.md) - 資料庫架構說明
- [USER_PRIVILEGES.md](USER_PRIVILEGES.md) - 使用者權限說明
- [MERGE.md](MERGE.md) - 人物合併工具說明

---

## 未來改進計劃

1. **批次修復功能**:
   - 支援上傳 CSV 檔案批次修復多條關係
   - 自動推斷反向關係代碼(基於 `KINSHIP_CODES` 和 `ASSOC_CODES` 的配對欄位)

2. **關係驗證**:
   - 自動檢查關係的語義正確性
   - 提示可能的錯誤關係

3. **修復歷史記錄**:
   - 記錄所有修復操作到專門的審計表
   - 支援查看和回滾修復操作

4. **自動檢測**:
   - 定期掃描資料庫找出所有單向關係
   - 生成修復建議報告

5. **API 支援**:
   - 提供 REST API 供其他工具呼叫
   - 支援自動化腳本批次修復

---

## 授權與貢獻

本工具是 CBDB 線上輸入系統的一部分,遵循專案的整體授權協議 [CC BY-NC-SA 4.0 International](https://creativecommons.org/licenses/by-nc-sa/4.0/)。
