# Mutate API 語義約定（草案）

本文用於釐清 `/api/v2/mutate` 的語義邊界，避免後續擴充（任意欄位更新、proposal 模式、批量更新）時混淆成「舊編輯頁 updateQuery 的 API 化」。

## 結論（先講重點）

- `mutate` **不應預設等同**既有 `updateQuery()`（整頁表單提交語義）。
- `mutate` 應被定義為一個**變更協議（mutation protocol）**：
  - 指定 `resource / mode / operation / target / changes`
  - 表示「對某筆記錄做某種變更」
  - 回傳 JSON 結果（成功/失敗/錯誤細節）
- `mutate` 的目標是：
  - **業務規則一致**
  - **權限與審計一致（或更嚴格）**
  - **不要求與舊頁面走完全相同的程式碼路徑**

## 為什麼不能直接等同 `updateQuery()`

既有 `updateQuery()` 多半代表「提交完整編輯頁表單」，通常包含：

- 表單預處理（sentinel 值轉換、預設值補齊）
- 依賴完整欄位上下文
- 頁面導向邏輯（redirect / flash）
- 關聯資料同步（例如任官地址）
- 舊格式相容邏輯（複合主鍵解析等）

而 `mutate` 的典型場景是：

- 前端 XHR / API 呼叫
- 僅修改少量欄位（例如 `c_sequence`）
- 預期 JSON 回應
- 後續可能支援 `proposal` / 批量

若強行讓 `mutate == updateQuery()`，常見問題：

- 需要偽造大量原本只存在於頁面表單的欄位
- 容易觸發非預期副作用
- 審計語義模糊（到底是「整頁保存」還是「欄位 patch」）

## 建議語義模型

### 1) `mutate` 是變更協議（API 合約）

請求 JSON 描述：

- 我要改哪一個資源（`resource`）
- 用什麼模式（`mode`：`direct` / `proposal`）
- 做什麼操作（`operation`：`update` / `create` / `delete`）
- 目標記錄是誰（`target.pk`）
- 要改哪些欄位（`changes`）

### 2) `operation=update` 的語義是「局部更新（PATCH）」

- 只修改 `changes` 提供的欄位
- 未提供欄位不應被隱式重置
- 需做資源級欄位白名單與規則校驗

### 3) 舊頁面 `updateQuery()` 是另一種語義（整頁表單提交）

- 可以視為 `form_update` 類型語義（概念上）
- `mutate` 實作時可選擇「重用其內部 repository 邏輯」
- 但不應要求 `mutate` 對外行為完全複製 page form 的細節

## 實作原則（重要）

### 原則 A：追求「結果一致」，不追求「路徑一致」

- 必須一致：
  - 權限檢查
  - 資料完整性
  - 操作紀錄 / 審計可追溯
- 可以不同：
  - 是否經過 `updateQuery()`
  - 是否需要完整表單 payload
  - 是否使用專門的 resource handler

### 原則 B：每個資源可有自己的 mutate handler

`resource + operation + mode` 對應到 handler，handler 可選：

- 直接復用既有 repository update
- 包一層 proxy request 補必要欄位
- 自行實作最小 SQL + operation/audit（當舊邏輯太重或不適合）

### 原則 C：`person_id` 與 `target.pk` 必須一致

對人物資訊資源，`person_id` 與 `target.pk` 中的人物 ID（若有）必須一致，避免：

- 改到 A 的資料、卻記到 B 的操作紀錄
- 審計與還原流程出現錯配

## 以 `c_sequence` 快速修改為例：兩大類策略

### 類型 1：`c_sequence` **不是主鍵**

例：

- `ALTNAME_DATA`
- `POSSESSION_DATA`

可行策略：

- 直接做 `operation=update`（局部改 `c_sequence`）
- 常可部分復用舊 repository update 邏輯（必要時補少量欄位）

### 類型 2：`c_sequence` **是主鍵的一部分**

例：

- `BIOG_ADDR_DATA`
- `STATUS_DATA`
- `EVENTS_DATA`
- `ENTRY_DATA`

注意：

- 修改 `c_sequence` 等同於修改主鍵
- 需要專門處理：
  - 舊主鍵定位
  - 新主鍵衝突檢查
  - 操作紀錄 `resource_id` / audit row PK 一致性
  - 回傳新主鍵

這類資源通常不適合直接硬套舊 `updateById()`（尤其當舊邏輯排除主鍵欄位更新時）。

## 建議的擴充方向（逐步）

### Phase 1（已在做）

- `mode=direct`
- `operation=update`
- 僅支援部份資源、部份安全欄位（例如 `c_sequence`）

### Phase 2

- `mode=proposal`
- 同一請求協議，改走 proposal 寫入而非直接落表

### Phase 3

- 批量變更（例如整頁次序調整）
- 明確定義批量成功/失敗與部分失敗策略

## 前端使用建議（列表快速修改）

列表中的「次序快速提交」應使用：

- `mode = direct`
- `operation = update`
- `changes = { c_sequence: ... }`

它的語義是：

- **欄位級 patch**
- 不是「模擬編輯頁按下保存」

## 備註

若未來確實需要一種「等同舊編輯頁整表單提交」的 API，可另外定義明確語義（例如 `operation=form_update`），避免與 `operation=update`（局部 patch）混淆。

