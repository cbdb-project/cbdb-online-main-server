# Fidelity Spec：codes/proposal-edit（P2-5，完成 Phase 2）

> 舊頁 = `codes.proposals.edit`/`codes.proposals.update`/`codes.proposals.cancel`。
> 新頁 = `app.codes.proposals.edit`（Inertia render）+ 重用 proposalUpdateExisting/proposalCancel
> （重導 operations.index，shell-agnostic，故無需 app 變體）。flag = `codes`。

## 設計
- `appProposalEdit` 鏡像 `proposalEdit`（findOperationOrAbort + ensureProposalEditable 授權：
  僅提案者本人、狀態 pending/rejected），Inertia::render('Codes/ProposalEdit')。
- update/cancel 直接重用既有 `proposalUpdateExisting`/`proposalCancel`（兩者都 redirect
  operations.index，Blade 與 app 共用同一目標，write-path 零改動）。
- **路由排序關鍵**：proposals/{operation} 系列須註冊在 {id}='.*' 泛用路由之前，否則被吞掉。

## 表單 parity
- rejected → 駁回原因 alert；cancelled → 撤回原因 alert。
- 每欄受控輸入（PK 標示）+ 提案說明（預填 meta.comment）+ 更新提案鈕（PATCH）+ 返回提案列表連結。

## 偏離決策
1. update/cancel 重用既有 Blade 控制器方法（重導 operations.index 對兩 shell 相同），不另建 app 變體。
2. 整頁表單 → Inertia useForm（PATCH）。

## parity 檢查清單
- [x] 提案授權（本人 + pending/rejected）；非提案者 403
- [x] 欄位載入提案值 + 提案說明預填
- [x] 駁回/撤回原因 alert
- [x] 更新提案持久化 + 重導 operations.index
- [x] 路由排序不遮蔽 {id} 泛用路由
- [x] i18n codes 群組；無硬編碼中文
- [x] write-path 零改動（重用既有方法；CodesControllerTest 53/53 綠）；flag old
