# BiogMain 審批流程實施計劃

## 一、背景與目標

### 1.1 目標
參考 `AGENTS.md` 和 `APPROVAL_FLOWS.md`，為 BiogMain 及其 12 個子頁面（共 13 類項目）實現完整的審批流程，仿照現有 CodesController 的提案審批模式。

### 1.2 涉及範圍
- **BIOG_MAIN 主表**：人物基本信息
- **12 個子頁面**：
  1. 地址（BIOG_ADDR_DATA）
  2. 別名/字號（ALTNAME_DATA）
  3. 著述/文獻（BIOG_TEXT_DATA）
  4. 官職（POSTED_TO_OFFICE_DATA）
  5. 關聯人物（ASSOC_DATA）
  6. 條目（ENTRY_DATA）
  7. 事件（EVENTS_DATA）
  8. 親屬（KIN_DATA）
  9. 身份（STATUS_DATA）
  10. 所有物（POSSESSION_DATA）
  11. 社交機構（BIOG_INST_DATA）
  12. 來源（SOURCES 或其他相關表）

## 二、現有審批流程分析

### 2.1 CodesController 提案模式總結

#### 操作類型
- `Operation::TYPE_PROPOSAL_CREATE = 8`：新增提案
- `Operation::TYPE_PROPOSAL_UPDATE = 9`：修改提案
- `Operation::TYPE_CREATE = 1`：正式新增（核准後）
- `Operation::TYPE_UPDATE = 2`：正式更新（核准後）

#### 提案數據結構
```json
{
  "field1": "value1",
  "field2": "value2",
  "__proposal_meta": {
    "action": "create|update",
    "table": "TABLE_NAME",
    "submitted_by": "使用者名稱",
    "submitted_by_id": 123,
    "submitted_at": "2025-11-01 23:40:00",
    "comment": "提案說明"
  },
  "__review_status": "pending|approved|rejected|cancelled",
  "__key_columns": ["c_personid", "c_sequence"],
  "__reviewed_by": "審核者名稱",
  "__reviewed_by_id": 456,
  "__reviewed_at": "2025-11-02 10:00:00",
  "__review_comment": "審核備註"
}
```

#### 核心流程
1. **提交提案**：
   - `proposalStore()`：新增提案
   - `proposalUpdate()`：修改提案
   - 檢查主鍵衝突、數據重複
   - 寫入 `operations` 表，`op_type` = 8 或 9

2. **審核提案**（OperationsProposalController）：
   - `approve()`：核准並應用至實際數據表
   - `reject()`：退回提案

3. **提案管理**：
   - `proposalEdit()`：編輯待審核或已退回的提案
   - `proposalUpdateExisting()`：更新提案內容
   - 撤回提案功能

### 2.2 BiogMain 現有操作模式

#### 權限檢查
```php
if (!Auth::check()) {
    flash('请登入后编辑', 'error');
    return redirect()->back();
}
elseif (Auth::user()->is_active != 1 || Auth::user()->is_admin == 2) {
    flash('该用户没有权限，请联系管理员', 'error');
    return redirect()->back();
}
```

#### 操作記錄
```php
$this->operationRepository->store(
    Auth::id(),
    $c_personid,
    1, // op_type
    'ALTNAME_DATA',
    $resourceId,
    $data,
    $originalData // 僅更新時提供
);
```

#### 數據處理特點
- 使用複合主鍵（如 `c_personid-c_sequence-c_alt_name_chn-c_alt_name_type_code`）
- 主鍵中包含特殊字符處理（`unionPKDef()` / `unionPKDef_decode()`）
- 時間戳自動填充（`toolsRepository->timestamp()`）
- 重複數據檢查

## 三、實施策略

### 3.1 整體架構設計

#### 選項 A：集中式控制器（推薦）
創建一個統一的 `BasicInformationProposalController`，處理所有 BiogMain 相關的提案：

**優點**：
- 代碼集中，易於維護
- 統一的業務邏輯處理
- 減少重複代碼

**缺點**：
- 控制器可能較大
- 需要根據資源類型進行路由分發

#### 選項 B：分散式控制器
在每個子頁面控制器中添加提案方法（如 `BasicInformationAltnamesController::proposalStore()`）：

**優點**：
- 符合現有代碼結構
- 每個控制器職責清晰

**缺點**：
- 代碼重複較多
- 維護成本高

**建議**：採用 **選項 A**，創建 `BasicInformationProposalController`，並提取共用邏輯到 Trait 或 Service 類。

### 3.2 路由設計

```php
// 主表提案路由
Route::post('/basicinformation/proposal-store', 'BasicInformationProposalController@proposalStoreMain');
Route::post('/basicinformation/{id}/proposal-update', 'BasicInformationProposalController@proposalUpdateMain');

// 子頁面提案路由（統一格式）
Route::post('/basicinformation/{personid}/{resource}/proposal-store', 'BasicInformationProposalController@proposalStore');
Route::post('/basicinformation/{personid}/{resource}/{id}/proposal-update', 'BasicInformationProposalController@proposalUpdate');

// 提案編輯與撤回
Route::get('/basicinformation/proposals/{operation}/edit', 'BasicInformationProposalController@proposalEdit');
Route::put('/basicinformation/proposals/{operation}', 'BasicInformationProposalController@proposalUpdateExisting');
Route::delete('/basicinformation/proposals/{operation}/cancel', 'BasicInformationProposalController@cancel');
```

### 3.3 數據表映射

| 資源類型 | 數據表 | 主鍵欄位 | Resource ID 格式 |
|---------|--------|----------|-----------------|
| main | BIOG_MAIN | c_personid | `{c_personid}` |
| addresses | BIOG_ADDR_DATA | c_personid, c_addr_id, c_sequence, c_addr_type | `{c_personid}-{c_addr_id}-{c_sequence}-{c_addr_type}` |
| altnames | ALTNAME_DATA | c_personid, c_sequence, c_alt_name_chn, c_alt_name_type_code | `{c_personid}-{c_sequence}-{c_alt_name_chn}-{c_alt_name_type_code}` |
| texts | BIOG_TEXT_DATA | c_personid, c_textid, c_year_year, c_text_role_code | `{c_personid}-{c_textid}-{c_year_year}-{c_text_role_code}` |
| offices | POSTED_TO_OFFICE_DATA | c_personid, c_posting_id, c_office_id | `{c_personid}-{c_posting_id}-{c_office_id}` |
| assocs | ASSOC_DATA | c_personid, c_assoc_id, c_assoc_code, c_assoc_type | `{c_personid}-{c_assoc_id}-{c_assoc_code}-{c_assoc_type}` |
| entries | ENTRY_DATA | c_personid, c_entry_id, c_entry_code | `{c_personid}-{c_entry_id}-{c_entry_code}` |
| events | EVENTS_DATA | c_personid, c_event_id, c_event_type | `{c_personid}-{c_event_id}-{c_event_type}` |
| kinship | KIN_DATA | c_personid, c_kin_id, c_kin_code | `{c_personid}-{c_kin_id}-{c_kin_code}` |
| statuses | STATUS_DATA | c_personid, c_status_code, c_sequence | `{c_personid}-{c_status_code}-{c_sequence}` |
| possessions | POSSESSION_DATA | c_personid, c_poss_code, c_sequence | `{c_personid}-{c_poss_code}-{c_sequence}` |
| socialinst | BIOG_INST_DATA | c_personid, c_inst_code, c_inst_role_code | `{c_personid}-{c_inst_code}-{c_inst_role_code}` |

### 3.4 共用邏輯提取

創建 `app/Traits/BasicInformationProposalTrait.php`：

```php
trait BasicInformationProposalTrait
{
    protected function getResourceConfig($resourceType)
    {
        // 返回資源配置（表名、主鍵、控制器路由等）
    }

    protected function buildProposalMeta($action, $resourceType, Request $request)
    {
        // 構建提案元數據
    }

    protected function recordProposalOperation($opType, $resourceType, $data, $original, $meta)
    {
        // 記錄提案操作
    }

    protected function sanitizeProposalPayload($payload)
    {
        // 清理提案數據（移除 __ 開頭的字段）
    }

    protected function buildCompositeId($keyColumns, $data)
    {
        // 構建複合主鍵 ID
    }

    protected function ensureProposalEditable($operation, $resourceType)
    {
        // 確保提案可編輯（pending 或 rejected 狀態）
    }
}
```

## 四、實施步驟

### 階段一：基礎架構（第 1-2 週）

#### 1.1 創建提案控制器
- [ ] 創建 `BasicInformationProposalController`
- [ ] 創建 `BasicInformationProposalTrait`
- [ ] 定義資源配置數組

#### 1.2 實現核心方法
- [ ] `proposalStore()` - 新增提案
- [ ] `proposalUpdate()` - 修改提案
- [ ] `proposalEdit()` - 編輯提案
- [ ] `proposalUpdateExisting()` - 更新提案
- [ ] `cancel()` - 撤回提案

#### 1.3 擴展審核控制器
- [ ] 擴展 `OperationsProposalController::approve()` 支持 BiogMain 資源
- [ ] 處理複合主鍵解析
- [ ] 處理特殊字符轉義

### 階段二：視圖調整（第 3 週）

#### 2.1 表單按鈕調整
修改各子頁面的 create/edit 視圖，添加「提交提案」按鈕：

```blade
<!-- 原有「儲存」按鈕 -->
<button type="submit" name="action" value="save" class="btn btn-primary">
    儲存
</button>

<!-- 新增「提交提案」按鈕 -->
@if(Auth::check() && Auth::user()->is_active == 1)
    <button type="submit" name="action" value="proposal" class="btn btn-info">
        提交提案
    </button>
@endif
```

#### 2.2 提案編輯視圖
創建 `resources/views/biogmains/proposal-edit.blade.php`：
- 顯示提案狀態（pending/rejected）
- 顯示審核備註
- 提供修改與撤回功能

#### 2.3 提案列表視圖
擴展 `/operations` 頁面，支持 BiogMain 相關提案的顯示與篩選。

### 階段三：逐步實現各模組（第 4-10 週）

建議實施順序（由簡到繁）：

1. **第 4 週：ALTNAME_DATA（別名）** ✅ 優先
   - 結構簡單，主鍵清晰
   - 無複雜關聯
   - 適合作為模板

2. **第 5 週：STATUS_DATA（身份）**
   - 結構類似別名
   - 驗證流程穩定性

3. **第 6 週：BIOG_ADDR_DATA（地址）**
   - 涉及地址代碼關聯
   - 測試外鍵處理

4. **第 7 週：BIOG_TEXT_DATA（著述）**
   - 涉及文本代碼關聯
   - 測試複雜查詢

5. **第 8 週：ASSOC_DATA（關聯人物）**
   - 涉及人物關聯
   - 測試雙向關係

6. **第 9 週：KIN_DATA（親屬）**
   - 類似關聯人物
   - 測試親屬網絡

7. **第 10 週：ENTRY_DATA（條目）**
   - 簡單結構
   - 補充測試

8. **第 11 週：EVENTS_DATA（事件）**
   - 涉及時間處理
   - 測試日期範圍

9. **第 12 週：POSSESSION_DATA（所有物）**
   - 簡單結構
   - 快速實現

10. **第 13 週：BIOG_INST_DATA（社交機構）**
    - 涉及機構代碼
    - 測試機構關聯

11. **第 14 週：POSTED_TO_OFFICE_DATA（官職）** ⚠️ 複雜
    - 已有專門的 Repository 方法（officeStoreById/officeUpdateById/officeDeleteById）
    - 涉及地址列表（POSTED_TO_ADDR_DATA）
    - 需要數據庫交易處理
    - 需特別設計

12. **第 15 週：BIOG_MAIN（主表）** ⚠️ 最複雜
    - 字段眾多
    - 涉及多個關聯表
    - 最後實現

13. **第 16 週：來源模組（如果需要）**
    - 確認具體表結構後實現

### 階段四：測試與文檔（第 17-18 週）

#### 4.1 單元測試
- [ ] 為每個資源類型編寫提案測試
- [ ] 測試權限控制
- [ ] 測試主鍵衝突檢測
- [ ] 測試審核流程

#### 4.2 功能測試
- [ ] 端到端提案流程測試
- [ ] 多用戶協作測試
- [ ] 邊界情況測試

#### 4.3 文檔更新
- [ ] 更新 `APPROVAL_FLOWS.md`
- [ ] 更新 `CHANGELOG.md`
- [ ] 編寫用戶操作手冊

## 五、技術要點

### 5.1 複合主鍵處理

#### 問題
BiogMain 子頁面使用複合主鍵，需要特殊處理：
- 主鍵包含特殊字符（如 `-`）
- 需要編碼/解碼（`unionPKDef()` / `unionPKDef_decode()`）

#### 解決方案
```php
protected function buildCompositeId($keyColumns, $data)
{
    $parts = [];
    foreach ($keyColumns as $column) {
        $value = $data[$column] ?? '';
        // 處理特殊字符
        $value = str_replace('-', 'minus', $value);
        $parts[] = $value;
    }
    return implode('-', $parts);
}

protected function parseCompositeId($id, $keyColumns)
{
    // 先處理保留字
    $id = $this->biogMainRepository->unionPKDef_decode($id);
    $parts = explode('-', $id);

    $conditions = [];
    foreach ($keyColumns as $index => $column) {
        $value = $parts[$index] ?? '';
        // 還原特殊字符
        $value = str_replace('minus', '-', $value);
        $conditions[$column] = $value;
    }
    return $conditions;
}
```

### 5.2 時間戳處理

所有數據變更都應調用 `ToolsRepository::timestamp()`：

```php
protected function applyTimestamp($data, $isCreate = false)
{
    return $this->toolsRepository->timestamp($data, $isCreate);
}
```

### 5.3 官職特殊處理

官職使用專門的 Repository 方法，需要特別設計：

```php
// 正常流程（直接儲存）
$this->biogMainRepository->officeStoreById($personId, $data);
$this->biogMainRepository->officeUpdateById($personId, $officeId, $data);

// 提案流程（審核後才應用）
// 需要在 approve() 中調用相應的 Repository 方法
if ($resourceType === 'offices') {
    if ($opType === Operation::TYPE_PROPOSAL_CREATE) {
        $this->biogMainRepository->officeStoreById($personId, $sanitizedData);
    } else {
        $this->biogMainRepository->officeUpdateById($personId, $officeId, $sanitizedData);
    }
}
```

### 5.4 權限控制

#### 提案權限
- 活躍用戶（`is_active == 1`）可提交提案
- 排除 `is_admin == 2` 的用戶

#### 直接儲存權限
- 視具體需求決定是否保留直接儲存功能
- 建議：普通用戶僅能提案，管理員可直接儲存

#### 審核權限
- 僅活躍管理員（`is_active == 1 && is_admin == 1`）可審核

### 5.5 數據驗證

#### 主鍵完整性
```php
protected function validatePrimaryKey($keyColumns, $data)
{
    foreach ($keyColumns as $column) {
        if (!isset($data[$column]) || $data[$column] === '' || $data[$column] === null) {
            throw new \Exception("主鍵欄位 {$column} 不得為空");
        }
    }
}
```

#### 數據唯一性
```php
protected function checkDuplicateData($table, $conditions)
{
    $exists = DB::table($table)->where($conditions)->exists();
    if ($exists) {
        throw new \Exception('數據已存在');
    }
}
```

#### 提案衝突檢測
```php
protected function hasActiveProposalConflict($table, $keyColumns, $data, $opType)
{
    $resourceId = $this->buildCompositeId($keyColumns, $data);

    return Operation::where('resource', $table)
        ->where('resource_id', $resourceId)
        ->where('op_type', $opType)
        ->whereRaw("JSON_EXTRACT(resource_data, '$.__review_status') = 'pending'")
        ->exists();
}
```

## 六、風險與挑戰

### 6.1 技術風險

| 風險 | 影響 | 緩解措施 |
|-----|------|---------|
| 複合主鍵解析錯誤 | 高 | 充分測試，使用現有的 `unionPKDef()` 函數 |
| 數據庫交易衝突 | 中 | 官職操作使用現有 Repository 方法 |
| JSON 數據過大 | 低 | 監控 `resource_data` 大小，必要時壓縮 |
| 特殊字符處理 | 中 | 統一使用現有編碼/解碼函數 |

### 6.2 業務風險

| 風險 | 影響 | 緩解措施 |
|-----|------|---------|
| 用戶不熟悉提案流程 | 中 | 提供清晰的 UI 提示和操作手冊 |
| 提案積壓 | 中 | 提供提案統計和提醒功能 |
| 誤審核 | 高 | 提供審核預覽和撤銷機制 |

### 6.3 性能風險

| 風險 | 影響 | 緩解措施 |
|-----|------|---------|
| `/operations` 頁面過慢 | 中 | 添加索引、分頁、篩選 |
| JSON 查詢效率低 | 低 | 考慮添加 `review_status` 欄位 |

## 七、後續優化建議

### 7.1 短期優化（實施後 1-3 個月）
- [ ] 添加提案通知功能（郵件/站內信）
- [ ] 提供批量審核功能
- [ ] 添加提案統計儀表板

### 7.2 中期優化（實施後 3-6 個月）
- [ ] 考慮添加 `review_status` 欄位到 `operations` 表，提升查詢效率
- [ ] 提供提案歷史版本對比
- [ ] 實現提案自動核准規則（如小幅修改）

### 7.3 長期優化（實施後 6-12 個月）
- [ ] 實現工作流引擎（多級審核）
- [ ] 提供 API 接口
- [ ] 與外部系統集成

## 八、總結

### 8.1 關鍵成功因素
1. **遵循現有模式**：嚴格參考 CodesController 的實現
2. **充分測試**：每個模組實現後都要有完整的測試覆蓋
3. **漸進實施**：從簡單模組開始，逐步推進到複雜模組
4. **文檔同步**：及時更新文檔，保持代碼與文檔一致

### 8.2 預期成果
- 13 個資源類型全部支持提案審批流程
- 統一的提案管理介面
- 完整的操作審計追蹤
- 降低數據錯誤風險
- 提升數據質量管理能力

### 8.3 時間估算
- **總工期**：16-18 週
- **核心開發**：12-14 週
- **測試與優化**：4 週

### 8.4 建議優先實施
**第一階段（MVP）**：先實現 3-5 個簡單模組，驗證架構設計
- ALTNAME_DATA（別名）
- STATUS_DATA（身份）
- POSSESSION_DATA（所有物）

**第二階段**：中等複雜度模組
- BIOG_ADDR_DATA（地址）
- BIOG_TEXT_DATA（著述）
- ASSOC_DATA（關聯人物）

**第三階段**：複雜模組
- POSTED_TO_OFFICE_DATA（官職）
- BIOG_MAIN（主表）

## 九、問題與決策

### 待確認問題
1. **是否保留直接儲存功能？**
   - 選項 A：普通用戶僅能提案，管理員可直接儲存
   - 選項 B：所有用戶（包括管理員）都走提案流程
   - **建議**：選項 A

2. **是否支持刪除提案？**
   - 當前 CodesController 不支持刪除提案
   - **建議**：暫不支持，未來可擴展

3. **第 12 個子頁面（來源）的具體表是？**
   - 需要確認是 SOURCES 還是其他表
   - **待確認**

4. **是否需要批量操作支持？**
   - 如批量審核、批量撤回
   - **建議**：後續優化

5. **官職的地址列表如何處理？**
   - POSTED_TO_ADDR_DATA 與 POSTED_TO_OFFICE_DATA 關聯
   - **建議**：將地址列表作為 JSON 存儲在提案中，審核時一併處理

---

**文檔版本**：1.0
**創建日期**：2025-11-17
**最後更新**：2025-11-17
**作者**：Claude AI
**審核狀態**：待審核
