# Laravel 查詢陷阱檢查報告

## 檢查日期
2025-12-30

## 背景
在近期的提交中發現 `orWhere` 分組問題（參見 commit 5a34685），導致 SQL 查詢的優先級錯誤。本次檢查系統性地審查了代碼庫中所有可能存在的類似問題。

## 檢查範圍

### 1. ✅ orWhere / whereNot / when
**檢查內容**：
- `orWhere` 調用是否正確使用閉包分組
- `whereNot` 是否存在（Laravel 9+ 新增方法）
- `when` 條件查詢的使用模式

**檢查結果**：
- ❌ **發現 2 個嚴重問題需要修復**：
  1. `app/Repositories/BiogMainRepository.php:405-417` - 姓名搜索的 orWhere 鏈式調用
  2. `app/Repositories/BiogMainRepository.php:1693-1700` - ASSOC_DATA 更新的 orWhere 調用

- ✅ **已修復的代碼（無需改動）**：
  - `BiogMainRepository.php:1099-1249` - KIN_DATA 相關查詢（已在 commit 5a34685 中修復）
  - `Api/ApiController.php:290-346` - 所有 orWhere 都在閉包內
  - `CbdbApiController.php:323-365` - 所有 orWhere 都在閉包內
  - `QueryPlaygroundController.php:321-326` - 在閉包內
  - `ViewTableController.php:70-74` - 在閉包內
  - `OperationsController.php:49-53` - 在閉包內
  - `ManagementController.php:28-40` - 在閉包內
  - `CodesController.php:450-452` - 在閉包內
  - `MergePreviewController.php:964, 1049` - 在閉包內

- ✅ **安全的 orWhere（查詢起始位置）**：
  所有 Repository 中的簡單搜索查詢（如 `AddrCodeRepository` 等）都是從 Eloquent 模型或 Query Builder 的起始位置開始，沒有前置條件，因此是安全的。

- ℹ️ **whereNot**：代碼庫中未使用此方法（Laravel 9+ 新增）
- ℹ️ **when**：未發現問題使用模式

### 2. ✅ whereIn / whereNotIn 參數來源安全性
**檢查內容**：檢查 `whereIn` 和 `whereNotIn` 的參數是否來自可信來源，避免 SQL 注入

**檢查結果**：
- ✅ 所有 `whereIn` / `whereNotIn` 的參數都來自：
  - 數據庫查詢結果
  - 內部數組（硬編碼常量）
  - 經過處理的內部變數
- ✅ 無直接使用用戶輸入作為 whereIn 參數的情況
- ✅ Laravel Query Builder 會自動對參數進行綁定，防止 SQL 注入

### 3. ✅ with() 中帶 where 的關聯查詢
**檢查內容**：檢查 Eloquent `with()` 方法中是否有需要注意的 where 條件

**檢查結果**：
- ✅ 所有 `with()` 調用都是：
  - 簡單的關聯加載（如 `->with('sources', 'texts')`）
  - 帶閉包的關聯加載且邏輯正確（如 `BiogMainRepository.php:136-138`）
  - Laravel redirect 的 session flash（`->with('success', 'message')`）
- ✅ 未發現問題模式

### 4. ✅ SQLite 測試中的字符串日期處理
**檢查內容**：檢查測試中是否有直接使用字符串日期進行比較，可能在 SQLite 和 MySQL 中行為不一致

**檢查結果**：
- ✅ 所有日期字段都通過 Laravel Migration 的 `timestamp()` 方法創建
- ✅ 所有日期操作都使用 Carbon 對象
- ✅ 未發現直接使用字符串日期進行 where 查詢的情況
- ✅ 測試中使用 `Carbon::now()->format('Y-m-d H:i:s')` 是安全的，因為 Laravel 會正確處理

### 5. ✅ 鏈式調用是否正確 return $query
**檢查內容**：檢查閉包中是否有忘記 return 查詢構建器的情況

**檢查結果**：
- ✅ 所有閉包中的查詢構建器方法（where、orWhere 等）都會自動返回 $this
- ✅ Laravel Query Builder 支持方法鏈，無需顯式 return
- ✅ 未發現需要修復的問題

## 修復詳情

### 問題 1: BiogMainRepository.php 姓名搜索 orWhere 分組
**位置**：`app/Repositories/BiogMainRepository.php:405-417`

**問題描述**：
```php
// ❌ 錯誤：未使用閉包包裹
$names = $names->where('BIOG_MAIN.c_name_chn', 'like', '%'.$request->q.'%');
$names = $names->orWhere('BIOG_MAIN.c_name', 'like', $request->q)
    ->orWhere('BIOG_MAIN.c_surname', 'like', $request->q)
    // ... 更多 orWhere
```

這會生成錯誤的 SQL（假設前面有 LEFT JOIN 條件）：
```sql
WHERE BIOG_MAIN.c_name_chn LIKE '%q%'
   OR BIOG_MAIN.c_name LIKE 'q'
   OR BIOG_MAIN.c_surname LIKE 'q'
   -- 這裡的 OR 可能會與 JOIN 條件產生意外的優先級問題
```

**修復方案**：
```php
// ✅ 正確：使用閉包包裹所有搜索條件
$names = $names->where(function ($query) use ($request) {
    $query->where('BIOG_MAIN.c_name_chn', 'like', '%'.$request->q.'%')
        ->orWhere('BIOG_MAIN.c_name', 'like', $request->q)
        ->orWhere('BIOG_MAIN.c_surname', 'like', $request->q)
        // ... 更多 orWhere
});
```

這會生成正確的 SQL：
```sql
WHERE (
    BIOG_MAIN.c_name_chn LIKE '%q%'
    OR BIOG_MAIN.c_name LIKE 'q'
    OR BIOG_MAIN.c_surname LIKE 'q'
    ...
)
```

### 問題 2: BiogMainRepository.php ASSOC_DATA 更新 orWhere 分組
**位置**：`app/Repositories/BiogMainRepository.php:1693-1700`

**問題描述**：
```php
// ❌ 錯誤：未使用閉包包裹
DB::table('ASSOC_DATA')->where([
    ['c_assoc_id', '=', $c_personid],
    ['c_personid', '=', $old_assoc_id],
    ['c_text_title', '=', $old_c_text_title],
])
->Where('c_assoc_code', '=', $old_c_assocship_pair1)
->orWhere('c_assoc_code', '=', $old_c_assocship_pair2)
->update($data);
```

這會生成錯誤的 SQL：
```sql
WHERE c_assoc_id = ? AND c_personid = ? AND c_text_title = ?
  AND c_assoc_code = ?
   OR c_assoc_code = ?
-- OR 的優先級會導致意外的查詢範圍
```

**修復方案**：
```php
// ✅ 正確：使用閉包包裹 c_assoc_code 的條件
DB::table('ASSOC_DATA')->where([
    ['c_assoc_id', '=', $c_personid],
    ['c_personid', '=', $old_assoc_id],
    ['c_text_title', '=', $old_c_text_title],
])
->where(function ($query) use ($old_c_assocship_pair1, $old_c_assocship_pair2) {
    $query->where('c_assoc_code', '=', $old_c_assocship_pair1)
        ->orWhere('c_assoc_code', '=', $old_c_assocship_pair2);
})
->update($data);
```

這會生成正確的 SQL：
```sql
WHERE c_assoc_id = ? AND c_personid = ? AND c_text_title = ?
  AND (c_assoc_code = ? OR c_assoc_code = ?)
```

## 最佳實踐建議

### 1. orWhere 使用規範
**原則**：當 orWhere 與其他條件組合時，必須使用閉包包裹形成獨立的邏輯分組。

✅ **正確模式**：
```php
// 模式 A：查詢起始位置（安全）
$query = Model::where('field1', 'value1')
    ->orWhere('field2', 'value2');

// 模式 B：閉包分組（推薦）
$query->where(function ($q) {
    $q->where('field1', 'value1')
      ->orWhere('field2', 'value2');
});

// 模式 C：多個獨立的 OR 分組
$query->where(function ($q) {
    $q->where('a', 1)->where('b', 2);
})->orWhere(function ($q) {
    $q->where('c', 3)->where('d', 4);
});
```

❌ **錯誤模式**：
```php
// 在已有條件後直接使用 orWhere（危險）
$query->where('base_condition', 'value')
    ->orWhere('field1', 'value1')  // ❌ 會破壞查詢邏輯
    ->orWhere('field2', 'value2');

// 應改為：
$query->where('base_condition', 'value')
    ->where(function ($q) {
        $q->where('field1', 'value1')
          ->orWhere('field2', 'value2');
    });
```

### 2. 代碼審查檢查清單
在提交代碼前，請檢查：
- [ ] 所有 `orWhere` 調用是否在正確的分組中
- [ ] 是否有多個 where 條件與 orWhere 混用
- [ ] 是否在 JOIN 之後使用了 orWhere
- [ ] whereIn 的參數來源是否可信
- [ ] 日期比較是否使用 Carbon 而非字符串

### 3. 測試建議
- 對於複雜查詢，建議使用 `toSql()` 和 `getBindings()` 檢查生成的 SQL
- 在 Feature 測試中驗證查詢結果的正確性
- 測試邊界情況（空結果、多結果等）

## 總結

**檢查範圍**：
- ✅ orWhere / whereNot / when
- ✅ whereIn / whereNotIn 參數來源
- ✅ with() 中帶 where 的關聯查詢
- ✅ SQLite 字符串日期處理
- ✅ 鏈式調用 return 語句

**發現問題**：2 個嚴重問題
**已修復**：2 個嚴重問題
**無需修復**：大部分代碼遵循最佳實踐

**建議**：
1. 在代碼審查中重點關注 orWhere 的使用模式
2. 考慮在 `.claude/skills/` 中添加查詢構建最佳實踐指南
3. 定期運行類似的代碼掃描以發現潛在問題
