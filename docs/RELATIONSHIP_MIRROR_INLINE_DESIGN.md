# 親屬／社會關係 鏡像對應「行內化」設計與工作帳

> 狀態：**已實作並上線**（2026-06-26）。行內鏡像確認閘已併入 assoc/kin 編輯器；提案核准路徑補上鍵碰撞/鏡像偵測（#77/#82/#117）；專屬修復頁降級為「暫不公開」。§11.1 已有真實資料庫全情境實測紀錄（#90，零 bug）。本文保留設計脈絡供維護參考。
> 範圍：`KIN_DATA`（親屬）與 `ASSOC_DATA`（社會關係）兩種互逆鏡像關係。
> 關聯議題：#70 / #72（鏡像疑似匹配）、#77（提案核准繞過鏡像偵測）。

## 1. 目的

把「單邊關係補建」與「一對多／多對多對應的人工裁決」從**專屬 admin 頁**（`UnidirectionalRelationshipRepairController`）搬進**一般編輯器的點擊／存檔場景**，讓有編輯權限者在編輯該關係時就能看到對面狀況、自主處理，不必另外跑去專屬頁。最後把專屬頁降級到「暫不公開」菜單。

本文處理兩個問題 + 兩個關聯項：

- **問題 A**：開啟編輯器時偵測「對面缺對應關係」→ 行內提示，建議使用者在當前頁選好反向關係、存檔即補建對面。
- **問題 B**：對面有「多條對應」（一對多）與「多條對多條」→ 無論新建或更新，無論是否為問題，**一律提示**，列出**所有匹配項＋可點連結＋動作選項**，由使用者自主裁決（程式不替使用者決定）。
- **關聯項 C**：`delete` 定位器寬窄不一致導致漏刪孤兒。
- **關聯項 D**：#77 提案核准路徑繞過 #70/#72 鏡像偵測。

> 註：原本評估的「親屬／社會碼是否寫死」一題，經查證**碼非寫死**（即時查 `KINSHIP_CODES`/`ASSOC_CODES`，前端碼欄走 `/api/select/search/kincode|kinpair` 即時候選，全庫無對特定碼數字的硬比對業務分支），新增碼**不需改程式**，故**不列為工作項**，已自本設計移除。

## 2. 現狀地圖（證據）

### 2.1 鏡像同步主路徑
- 親屬更新鏡像：`BiogMainRepository::syncKinMirrorOnUpdate()`（`app/Repositories/BiogMainRepository.php:2571`）。legacy `kinshipUpdateById()`（`:1429`）與 v2 `KinshipMutationHandler`（`app/Services/Mutations/KinshipMutationHandler.php:142`）**共用此方法**。
- 親屬新建鏡像：`KinshipCreateHandler::afterDirectInsert()`（`app/Services/Mutations/KinshipCreateHandler.php:109`）委派 `syncKinMirrorOnUpdate(..., allowBackfill=true, ...)`。
- 社會關係對應：`AssociationCreateHandler`（`:128`）／`AssociationMutationHandler`／`syncAssocMirrorOnUpdate`，結構對稱。

### 2.2 定位器與多重性（問題核心）
- **更新定位（寬）**：`$pairWhere`（`:2596`）以 `c_kin_id=本人 + c_personid=對方 + c_autogen_notes=舊備註 + c_kin_code ∈ legitReverses` 命中；`legitReverses`（`:2586`）= KINSHIP_CODES 中 `c_kin_pair1` 或 `c_kin_pair2` 指向舊碼者（如「父(75)」展開為子/三子/季子/長女…約 49 碼）。`sumCount >= 1` 分支（`:2621`）**一次更新全部命中列**。
- **刪除定位（窄）**：`syncKinMirrorOnDelete()`（`:2717`）只認 `c_kin_code = c_kin_pair1`〔OR `c_kin_pair2`〕（`:2732`）。具體排行碼（季子/五子/三子）多不在 pair1/pair2 → **漏刪 → 孤兒**（關聯項 C）。
- **疑似偵測（#70）**：僅在精確集落空且對面存在「碼∉合法 KINSHIP_CODE 的漂移列」時拋 `MirrorSuspectedException`（`:2678`）→ 409。**合法的多條並不提示**（直接走 update-all）。

### 2.3 既有專屬工具（要復用＋降級的對象）
- `app/Http/Controllers/UnidirectionalRelationshipRepairController.php`
  - `executeRepair()`（`:150`）：鎖定正向列 → 偵測 >1（`multipleRecordsError`，`:222`，**已回傳匹配清單**）→ 檢查反向是否已存在（`reverseRelationExists`，`:265`）→ 建反向（`buildReverseRelation`，`:296`）→ insert + operation log。
  - 注意：此路徑是**獨立的簡化 insert**，**未經 #70/#72 gating**。
- 路由：`routes/web.php:479-517`（`admin.unidirectional-relationship-repair[.kinship|.assoc]`、`app.admin...`）。
- 頁面：`resources/js/inertia/Pages/Admin/UnidirectionalRelationshipRepair/Index.tsx`、`resources/views/admin/unidirectional-relationship-repair.blade.php`。
- **目前無任何側邊欄/菜單連結**（`sidebar-v3.blade.php`、`Sidebar.tsx` 皆無），靠直連 URL 進入。

### 2.4 權限判定
- 直接寫入閘：`Auth::user()->canWriteDirectly()`（各 `BasicInformation*Controller` 一致使用）。true＝直接落庫；否則走提案核准。

## 3. 核心設計原則：鏡像寫入前確認閘

> 兩個問題共用同一條互動主軸（依使用者拍板）：

**任何鏡像寫入（補建缺邊、或同步多條）之前，先彈出「將寫入／影響誰的哪些記錄」的清單，使用者確認後才落庫。**

- 觸發對象：**僅 `canWriteDirectly()` 為真者**（含管理員等所有有編輯權限的類別）。無編輯權限者**不觸發**任何此類提示。
- 提示內容必載明：**將被新建／修改的是「哪一個人」的「哪些列」**，每列附 `edit-v2` 可點連結（由 PK 組 URL）。
- 提示提供**動作選項**（見問題 B），但預設不替使用者決定；使用者確認後才寫。
- 後端對應：偵測到「需確認」狀態時回 `409`＋結構化 payload（清單＋建議反向碼＋連結 PK），前端據此彈窗；使用者確認後帶 `meta.force`（或新的 `meta.confirm`）重送。沿用 #70 既有「409 → 彈窗 → 強制」互動骨架。

## 4. 工作項 A — 開啟即偵測對面缺失 → 行內補建

### 4.1 行為
1. 進入 assoc/kin 編輯器載入某列時（且 `canWriteDirectly()`），用「鏡像定位器同一套條件」算對面命中數。
2. 命中 0 → 顯示**非阻斷**提示「對面（人物 X）尚無對應關係」，附**反向碼選擇器**（選項＝即時 `legitReverses`，預設權威 `c_kin_pair1` / `c_assoc_pair`）。
3. 使用者選好反向碼 → 存檔 → 在**對面人物**補建反向列；確認閘先列出「將於人物 X 新建：[碼] 一列」，確認後落庫。

### 4.2 寫入路徑（已拍板）
- 走**一般 create/mirror 路徑**（與既有編輯同權限模型），自動套 #70/#72 gating；**不**走 admin endpoint。
- 復用 `buildReverseRelation()` 的反向欄位拷貝邏輯（抽到共用 service，見 §6）。

### 4.3 權限
- 僅 `canWriteDirectly()` 觸發與可補建；否則不提示（亦不經提案）。

## 5. 工作項 B — 一對多／多對多一律提示

### 5.1 觸發定義（已拍板）
- 對面命中 `legitReverses` 的列數 **> 1** 即觸發提示（**含合法多排行子等「無問題」情況**）。
- 僅對 `canWriteDirectly()` 者觸發。

### 5.2 行為（新建與更新皆適用）
1. 偵測到多條 → **先提示、不直接寫**。提示列出**全部匹配列**：每列顯示 `人物 / 碼 / 關係名 / 出處 / 建立日`＋**可點 `edit-v2` 連結**（PK→URL）。
2. 提示明確標示「**本次將寫入／影響誰的哪些記錄**」，讓使用者確認。
3. 提供**動作選項**（已拍板：要給選項，不只連結）。候選動作集（最終以實作確認）：
   - 「逐列去檢視／編輯」（連結）。
   - 「只同步指定的某一列」（使用者點選目標列）。
   - 「全部不動，僅更新正向」。
   - 「強制依權威反向碼收斂」（沿用 #70 force 語意，慎用）。
4. 使用者確認選擇後才落庫；未確認則整筆不寫（沿用 #70 的整筆回滾 + 409）。

### 5.3 與現狀的差異
- 現行 update 對多條**靜默 update-all**；本工作項改為**偵測即停、人工裁決**。
- 現行 #70 只對「漂移列」提示；本工作項**擴大到合法多條**也提示。

### 5.4 復用
- `multipleRecordsError()`（`:222`）已輸出匹配清單結構 → 抽為共用、供前端彈窗。

## 6. 關聯項 C — delete 漏刪孤兒（納入本 doc）

- 問題：`syncKinMirrorOnDelete` 窄定位（pair1/pair2）漏刪具體排行碼鏡像 → 刪正向後對面留孤兒（§2.2）。
- 設計方向（待細化）：將 delete 定位器與 update 對齊到 `legitReverses`；多條時同樣套 §3 確認閘（刪除前列出「將於對面刪除哪些列」，使用者確認）。assoc 對稱處理。
- 標記為**次要工作項**，可於 A/B 之後排程。

## 7. 關聯項 D — #77 提案核准繞過鏡像偵測

- 問題：提案核准路徑繞過 #66/#70 偵測，UPDATE 核准會靜默覆寫對面鏡像（已記於 #77）。
- 與本設計的關係：本設計的偵測／確認閘**須同步覆蓋提案核准路徑**，否則核准時仍會繞過缺邊／多條偵測。
- 標記為**前置或並行**修復。

## 8. 共用化重構：RelationshipMirrorService

把分散在 `BiogMainRepository`、`KinshipCreateHandler`、`UnidirectionalRelationshipRepairController` 的「定位／偵測／建反向／清單化」邏輯抽成單一服務（暫名 `RelationshipMirrorService`），供三方共用：
- 行內編輯器（新入口）。
- 被降級的 admin repair 頁（仍可用，但走同一 service）。
- create/update mutation handler。

目標：**單一定位與偵測真相來源**，消除「update 寬／delete 窄／repair 另一套」的三路不一致。

## 9. 收尾步驟 — repair 專屬頁降級「暫不公開」

- 由於目前**無菜單連結**，收尾為：**新建「暫不公開」菜單群組**（`Sidebar.tsx` / `sidebar-v3.blade.php`），把 `app.admin.unidirectional-relationship-repair` 登記於其下，標註「已由行內流程取代」。
- 路由保留（admin 可用），但不在常規導覽曝光。
- 待行內流程穩定後再評估是否完全下線。

## 10. 權限模型（彙整）

| 情境 | `canWriteDirectly()=true` | `=false` |
|---|---|---|
| 問題 A 缺邊提示／補建 | 觸發、可補建（直接寫） | 不觸發、不提示 |
| 問題 B 多條提示／裁決 | 觸發、可裁決 | 不觸發 |
| delete 確認閘 | 觸發 | 不觸發 |

> 註：#77（提案核准繞過 #70/#72）為已知缺口；本設計的偵測閘須同步覆蓋提案核准路徑，否則核准時仍會繞過（見關聯項 D）。

## 11. 驗收測試清單（草案）

- A1：開啟一條真實單邊關係（對面 0 列）→ 出現缺邊提示；選反向碼存檔 → 對面補出一列；audit/operation 落帳。
- A2：無編輯權限者開同一列 → **無**提示。
- B1：對面有合法多條（如子+三子）→ 新建/更新皆彈清單＋連結＋動作；未確認 → 整筆回滾、對面不變。
- B2：使用者選「只同步某一列」→ 僅該列被改，其餘不動。
- B3：對面有漂移列（碼∉合法）→ 沿用 #70 警告，與合法多條提示可區分。
- C1：刪有「具體排行碼」鏡像的正向列 → 確認閘列出將刪列；確認後對面不留孤兒。
- D1：提案核准 UPDATE/CREATE 一條會撞多條/缺邊的關係 → 偵測閘同樣生效（不被繞過）。

### 11.1 真實庫實測紀錄（#90，2026-06-26）

以真實 BIOG_MAIN 乾淨人物對（無既有關係，測後完全還原）+ 真實 handler／提案核准路徑逐一實測，全部符合設計、未發現 bug：

| 情境 | 親屬(KIN) | 社會關係(ASSOC) | 結果 |
|---|---|---|---|
| A 純內容編輯（未選反向碼） | 不補建（allowBackfill=false，不臆造） | （同源） | ✅ 符合（補建觸發點＝「選反向碼存檔」） |
| A 選反向碼存檔（pair-only） | 補出反向列 | #89 已實測補建 | ✅ |
| #66 對面內容分歧 | 409 mirror_conflict；force→200 同步覆寫 | 409；force→200 同步 | ✅ |
| #70 對面漂移垃圾碼 | 409 mirror_suspected（不臆造重複）；force→就地收斂、無重複 | 409；force→收斂 9999→5 | ✅ |
| B 對面合法多條 | locateOppositeEdges 命中 2（126,166） | （同源 Option-2） | ✅ |
| #87 對面合法排行碼反向 | 不誤報缺邊/衝突 | — | ✅ |
| C1 刪除「排行碼」鏡像（父75↔三子184） | 連帶刪除、不留孤兒 | — | ✅（#81） |
| D1 提案核准撞漂移 | 偵測閘生效→整筆回滾、提案未核准、未繞過 | （同 kinshipStoreById/assocStoreById detectConflict=true） | ✅（#82） |

> 註：真實 `c_kin_code`/`c_assoc_code` 受 FK 約束於 KINSHIP_CODES/ASSOC_CODES，**合法庫不可能存在「碼∉合法碼集」的垃圾漂移列**；#70 漂移情境以暫時關閉 FK 注入垃圾碼（99/9999）模擬，測後即刪。對面持有「另一合法碼」時（如 75 vs 反向 126）依 Option-2 視為**他段合法關係、不覆寫**、安全 backfill 本段，亦已實測確認。

## 12. 實作里程碑（建議順序）

1. §8 抽 `RelationshipMirrorService`（不改行為，純重構 + 既有測試綠）。
2. 問題 A（缺邊提示／補建）行內化。
3. 問題 B（多條裁決）行內化，擴大 #70 提示觸發。
4. 關聯項 C（delete 對齊 + 確認閘）。
5. 關聯項 D（#77 提案核准覆蓋）。
6. §9 收尾：repair 頁降級「暫不公開」。

## 13. 待決／風險

- 動作選項的最終集合與文案（§5.2）需與前端一起定稿。
- 「確認閘」前端互動是否完全沿用 #70 的 409 彈窗，或需新元件。
- delete 對齊 `legitReverses` 後，歷史孤兒資料的清理策略（是否提供批次體檢）另議。
- proposal_aux 既有結構能否承載「使用者選定的目標列／動作」需驗證。
