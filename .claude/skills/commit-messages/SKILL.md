---
name: commit-messages
description: 為本專案撰寫一致、清楚、繁體中文的 commit message。當使用者要求提交、請你撰寫 commit 訊息、準備提交、整理變更以便版本控制，或需要合併／重寫提交訊息時使用。
---

# Commit Messages

## 概述

為本專案產出一致的 commit message 格式與內容，強調可讀性、單一目的與可追溯性。

## 工作流程

1. 確認變更範圍與目的，必要時先看 `git status -sb`、`git diff HEAD`。
1. 一個 commit 僅涵蓋單一邏輯變更；多重目的要拆開。
1. 依下方格式撰寫訊息，必要時加上「測試 / 驗證」說明。

## 格式規範

### 標題（第一行）

- 60 字以內
- 繁體中文，命令語氣（如：新增、修正、調整、移除、整理、重構、補齊）
- 避免 `fix:` / `feat:` 這類前綴
- 保持簡潔、可一眼理解

### 內文（可選）

- 以條列說明「做了什麼 / 為什麼」
- 有測試或驗證請寫明（例如：`已執行 ./vendor/bin/phpunit --filter TestName`）
- 需要提及重寫歷史或大範圍改動時，放在內文

### Trailers（可選）

- 只有在專案明確需要時才加（例如修復回歸、對應票號）

## 範例

**只有程式碼格式調整：**

```
整理程式碼格式
```

**新增功能與測試：**

```
新增審計日誌查詢頁

- 加入側欄入口與列表頁
- 提供 diff 與時區顯示
- 已執行 ./vendor/bin/phpunit --filter AuditLogServiceTest
```

**修正行為與原因：**

```
修正 API 審計操作者來源

- actor_id 改用 operations.user_id
```
