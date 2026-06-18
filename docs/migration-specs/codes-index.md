# Fidelity Spec：codes/index（P2-1）

> 舊頁 = `codes.index`（Blade）；新頁 = `app.codes.index`（Inertia）。flag = `codes`（整個 Codes
> 群組共用，預設 old）。路由為 public（web，無 auth），與舊頁一致。

## 行為
代碼表總覽：{name, description} 清單（來源 CodesRepository::codes()，config 驅動，無 DB 查詢）。
即時關鍵字搜尋（name/description）+ 欄位排序三態（asc → desc → 取消），全部客戶端（清單小）。
表名連結至 show 頁，URL 由 codesShowUrl() 依 codes flag 解析（新版 show 就緒前安全回退 /codes/{name}）。

## parity
| 項目 | 舊 | 新 | parity |
|---|---|---|---|
| 表清單 name/description | yes | yes（appIndex 用同一 repository） | ✅ |
| 即時搜尋 | jQuery | React useState | ✅ 等價 |
| 欄位排序三態 | jQuery | React | ✅ 等價 |
| 連結 /codes/{name} | yes | flag-aware（old 時相同） | ✅ |
| 空結果提示 | common.no_data | 同 | ✅ |

## 偏離決策
1. 搜尋/排序由 jQuery 改 React 狀態（等價；移除對 onViteReady 的依賴）。
2. 表名連結改 flag-aware（forward-compatible，flag old 時與舊頁相同）。

## parity 檢查清單
- [x] 表清單與舊頁一致（同 repository）
- [x] 搜尋 + 排序三態
- [x] 連結 + 空狀態
- [x] i18n codes 群組 page_translations；nav/common shared；無硬編碼中文
- [x] 舊 Blade index() 未改（CodesControllerTest 綠）；flag old；nav 節點 flag-aware
