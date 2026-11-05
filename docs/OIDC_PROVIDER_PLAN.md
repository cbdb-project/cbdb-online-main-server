# OIDC Provider 方案評估：把後台登入提供給其他專案

> 狀態：**調研結論，尚未實作**（2026-11-05）。本文件只記錄評估與決議，沒有任何程式碼或依賴變更。
> 對外 API 一旦落地，必須同步 [API.md](../API.md) 與 `docs/openapi/openapi.yaml`。

## 1. 目標與範圍

讓本專案扮演 Google／Facebook 的角色：其他專案（Relying Party，RP）把使用者導來這裡登入，
本專案回傳「這個人是誰、而且是有效帳號」。**我們是 Provider，不是 Socialite 那一側的接入方。**

已確認的前提（使用者決議）：

| 項目 | 決議 |
|---|---|
| 下游數量 | 一到兩個，全部自己控制，無外部合作方 |
| 下游技術棧 | Node.js，無重型框架，直接用標準 OIDC 庫 |
| 單點登出 | **不做** |
| 角色／權限 claim | **不做**，只傳身份 |
| MFA | 不要求 |

由此收斂出的範圍：

- **做：** OIDC 授權碼流程 + PKCE、`openid`／`profile`／`email` scope、id_token、discovery、JWKS、refresh token、token 撤銷。
- **不做：** 單點登出（含 RP-Initiated 與 Back-channel）、角色／權限 claim、同意頁（first-party client 跳過）、client 自助註冊、MFA、implicit／password grant。

## 2. 協定概述

OIDC = OAuth 2.0 授權碼流程 + 身份層：

1. RP 把使用者導到本專案 `/oauth/authorize`（帶 `client_id`、`redirect_uri`、`scope=openid profile email`、`state`、`nonce`、PKCE challenge）。
2. 使用者在本專案登入（沿用現有 session 登入）。
3. 本專案帶一次性 `code` 導回 RP 的 `redirect_uri`。
4. RP 後端以 `code` 呼叫 `/oauth/token`，換得 `access_token`、`id_token`（JWT）、`refresh_token`。
5. RP 以 JWKS 驗 id_token 簽章，取出 `sub`／`name`／`email`。

需要提供的端點：`/oauth/authorize`、`/oauth/token`、`/.well-known/openid-configuration`、`/.well-known/jwks.json`（或套件預設的 `/oauth/jwks`）；`/userinfo` 可選。

## 3. 方案比較

| 方案 | 說明 | 結論 |
|---|---|---|
| **A. Laravel Passport + OIDC 套件** | Passport 提供 OAuth2 伺服器（league/oauth2-server），OIDC 層由第三方套件補 | **採用** |
| B. 獨立 IdP（Keycloak／Authentik／Hydra） | 另行部署，用戶需聯邦或同步 | 範圍太小，不值得多一個服務；若日後需要 MFA／登出同步再重估 |
| C. 自訂 token 驗證端點 | 非標準 | 不採用：每個下游都要客製、安全責任全在自己 |
| D. SAML／CAS | 學術機構常見 | 下游是自家 Node 專案，無需求 |

## 4. 現況盤點（本專案）

| 項目 | 現況 | 對方案的影響 |
|---|---|---|
| OAuth 伺服器 | **目前沒有 Passport、league/oauth2-server、任何 JWT 庫**（`composer.lock` 已確認）。⚠️ **但專案以前用過 Passport 並刻意換掉**：#622（`fe272a48`）換成 Sanctum、#628（`66a8df64`）清遺留設定，並留下 `2025_12_18_050122_remove_passport_oauth_tables`（`dropIfExists` 五張 `oauth_*` 表，`down()` 為空） | 這是**回頭路**，不是乾淨安裝：見 §6.7。`docs/API_AUTHENTICATION.md`「為什麼選擇 Sanctum」寫著「CBDB 不需要 OAuth2 的授權流程」，實作時要同步改寫 |
| `laravel/mcp` 的 OAuth | `Client/OAuth/*` 是客戶端側；但 `Server/Registrar.php` 會引用 `Laravel\Passport\Passport`，其 `Mcp::oauthRoutes()` 會註冊 `/.well-known/oauth-authorization-server`、`/.well-known/oauth-protected-resource`、`POST oauth/register`，並把 `mcp:use` 塞進 Passport scopes。應用目前**從未呼叫** `oauthRoutes()`（app／routes／config 皆無） | 現在無衝突；裝上 Passport 後**禁止呼叫 `Mcp::oauthRoutes()`**，否則與本計畫的 discovery 路由撞車（建議加護欄測試） |
| 現有 token | Sanctum（`api` guard，`expiration => null`）；MCP 端點掛 `auth:sanctum` + `mcp.ability`；另有 `confirmation_token` 眾包憑證 | OIDC 是第三套憑證，**不要與之混用或重用**；`api` guard 仍是 sanctum，Passport 只用於 `/oauth/*` |
| 登入 | `web` guard（session）、`Auth\LoginController`；登入後 `authenticated()` 擋 `!isActive()` | 未登入訪問 `/oauth/authorize?...` 時走 `Handler::unauthenticated()` → `redirect()->guest(route('login'))`，**完整 URL（含 query）存進 `url.intended`**，登入後由 `redirect()->intended()` 回跳，所以 `state`／`nonce`／`code_challenge` 會保留（React 登入頁是否原樣回傳尚未驗證）。`storeIntendedRedirect`／`isSafeRedirectPath` 只服務顯式的 `?redirect=` 輸入，且**檢查偏弱**：只擋 `//` 開頭，`/\evil.com` 之類會通過（見 §6.8） |
| `auth` middleware | 專案覆寫了 `auth` 別名（`App\Http\Middleware\Authenticate`，含 `isActive()` 檢查） | Passport 預設的 `/oauth/authorize` 路由中介層不保證用的是這個別名，**必須明確確認**授權端走的是會檢查 `isActive()` 的那一個 |
| 帳號狀態 | `is_active`：0 停用、1 啟用、**2 RESERVED**；`isActive()` 只認 1 | 授權與 refresh 都必須以 `isActive()` 為準 |
| email | `users_email_unique` 唯一，但**註冊沒有 email 驗證**，且使用者可在 `UserProfileController` 自行改 email（只驗格式與唯一性，**沒有所有權驗證**） | email **既未驗證又可變**：`email_verified` 不可宣稱為 true，下游**不可以 email 當身份鍵** |
| 註冊與啟用 | 註冊對匿名開放，但新帳號 `is_active=0`（欄位預設 0、不在 `$fillable`、`registered()` 立刻登出），須管理員在 `/manage` 或 `cbdb:manage-user` 手動啟用 | 「任何有效帳號」＝**任何被管理員啟用過的帳號**（含一般與眾包用戶）。啟用是唯一關卡，而它的門檻是人工審核未驗證 email 的自助註冊 |
| `users.id` | `int unsigned AUTO_INCREMENT`（`import_cbdb_schema.php`） | Passport 的 `user_id` 預期為 bigint／uuid，**型別必然不一致**，要調整 migration，不能照單全收 |
| 限流 | 全域 `ThrottleFailedAuthentication`（`Kernel`）：只在請求帶 Bearer token 時，按 IP 計 401／導向 `/login`，每分鐘 60 | `/oauth/token` 用 Basic／body secret 認證，不受影響；但 **Bearer 保護的 `/userinfo`（若啟用）會被計入**，而下游伺服器只有一兩個固定 IP，低額度＝自傷式停擺。`/oauth/token` 另需具名 limiter |
| 路由快取 | prod 跑 `route:cache` | 新路由不可在 `routes/*.php` 宣告具名函式／常數；Passport 自己的路由由 provider 註冊，沒有此問題，但自訂的 discovery／JWKS 路由要遵守 |

## 5. 套件選型（待實作前再核實）

Passport 本身**不含 OIDC**（無 id_token、discovery、JWKS），需第三方補：

| 套件 | 相容性（Packagist 描述） | 評估 |
|---|---|---|
| [jeremy379/laravel-openid-connect](https://packagist.org/packages/jeremy379/laravel-openid-connect) | `laravel/framework ^12`、`laravel/passport ^13`、`league/oauth2-server ^8.2 \|\| ^9.2`、PHP ≥ 8.2 | **首選候選**：id_token、discovery、JWKS、可選 userinfo。⚠️ README 自述維護者在找接手人，**採用前必須看 GitHub 近期活躍度與 issue** |
| [mindtwo/laravel-identity](https://packagist.org/packages/mindtwo/laravel-identity) | Laravel 13／Passport 13，需 PHP 8.4+ | 功能較全（含 introspection、登出），但本專案是 Laravel 12 且我們不做登出，**不符合** |
| ronvanderheijden/openid-connect | `league/oauth2-server ^8.2`、PHP ^7.4\|^8.0 | jeremy379 的上游，較舊 |
| 自行在 league/oauth2-server 上實作 id_token | — | 退路。id_token 簽章與 claims 不難，但 discovery／nonce／`at_hash` 等細節容易出合規性錯誤，優先用套件 |

**Passport 13 已知坑**：不會自動註冊授權頁面視圖，`/oauth/authorize` 會 `BindingResolutionException`，需要 `Passport::authorizationView(...)`。本專案是 React/Inertia，且 first-party client 要跳過同意頁，所以實作時要決定「自訂一個極簡授權頁」還是「全部 auto-approve」。

## 6. 設計決議與待辦

### 6.1 身分與 claims

- `sub` = `users.id`（內部穩定鍵），**不用 email**。
- claims 預設僅 `sub`、`name`；**`email` 只在下游確實需要時才輸出**（越少越不易出帳號串接錯誤），輸出時 `email_verified` 恆為 `false`。
- **不輸出** `is_admin`、`is_active`、`settings`、`institution`、`confirmation_token` 等任何欄位。claims 一律走**顯式白名單**，不可序列化整個 `User`。⚠️ 不要照抄 `Api\UserController::show()` 的欄位清單：它含 `is_admin`／`is_active`。
- 軟刪除帳號（`deactivate_soft_deleted_users`）不得核發 token；`users.id` 不得在刪除後重用。

### 6.2 「誰可以登入下游」

本專案只證明「這是一個有效的 CBDB 帳號」。**任何有效帳號（含一般用戶與眾包用戶）都能登入下游。** 這是不做角色權限的直接後果：

- 預設接受，由下游自行維護 `sub` 白名單。
- 若不能接受，才考慮在 client 上加「允許的帳號範圍」——這會重新引入授權邏輯，先不做。

### 6.3 停用帳號與 token 壽命（沒有單點登出的補償）

- access token 與 id_token 壽命要短（建議 10 分鐘量級）。
- **refresh 時必須檢查 `isActive()`**，否則停用帳號可無限續期。league/oauth2-server 的 refresh grant 不會呼叫任何「使用者狀態」hook，所以這**不是一行設定**，需要明確機制，擇一：自訂 `RefreshTokenRepository`／覆寫 refresh grant，或在 `/oauth/token` 前掛 middleware，經 `oauth_refresh_tokens` → `oauth_access_tokens` 的關聯找出使用者再檢查（refresh token 是不透明憑證，不能假設可從 token 本身解出使用者；檢查必須覆蓋實際核發新 access token 與 id_token 的那條路徑）。同理 refresh 時簽發的 id_token 也要擋。
- **停用帳號當下就撤銷該用戶全部 oauth token**：在管理端停用路徑（`/manage` 的 `performUserUpdate()` 與 `cbdb:manage-user`）掛撤銷，把暴露窗口從 refresh token 壽命縮到 access token 壽命。
- **refresh token 壽命也要設上限**（Passport 預設很長，會抵銷短 access token 的補償），並決定是否輪替與重用偵測。實作時要測：帳號被停用後 refresh 失敗、`/authorize` 被拒、token 已被撤銷。
- 下游若是自簽 JWT 本地驗證，**看不到撤銷**，窗口期 = access token 剩餘壽命。需告知下游開發者：自己的 session cookie 不要設太長。
- 使用者在本專案登出，下游**不會**同步登出（刻意不做）。

### 6.4 Client 管理

- 一到兩個 first-party client，用 artisan 命令建立（Passport 內建 `passport:client`）即可；管理頁（React/Inertia）延後。
- 僅授權碼 + PKCE + refresh，不開 implicit／password／client_credentials。
- **PKCE 只對 public client 強制**（league 的行為）。Node 伺服器端 RP 若是 confidential client（有 secret），預設可以不送 PKCE。要嘛註冊成 `--public`，要嘛由伺服器端強制所有 client 必須帶 `code_challenge` 且僅接受 `S256`。
- first-party 免同意頁要有明確機制（例如自訂 Client model 的 `skipsAuthorization()`），並決定 `prompt=login`／`max_age` 的政策，否則 RP 無法強制重新驗證。auto-approve 下 `GET /oauth/authorize` 對任何已登入瀏覽器都會直接發 code，**因此 `redirect_uri` 精確比對（不得有萬用字元）與 PKCE 是把關的重點，但不是全部**：授權碼必須一次性、綁定 client 與 PKCE verifier，RP 端還必須驗 `state`／`nonce`（§6.9）。這幾道少任何一道，自動核准都不算安全。

### 6.5 資料庫與專案規則

- Passport 的 migration **必須同時相容 MariaDB 與 SQLite**（AGENTS §1），並確認其外鍵：本專案**禁止 `CASCADE`**（§1.1），Passport 預設 migration 若帶 cascade 要改成 RESTRICT 或確認沒有外鍵。
- `oauth_*` 表的 `user_id` 型別必須與 `users.id` 一致，上線前核對。
- 簽章金鑰（`storage/oauth-private.key`）**不入版控**；prod 需部署流程產生與備份，並規劃輪換。
- 授權相關操作寫稽核（誰、何時、授權了哪個 client），署名走 `AuditActor`。
- 新增對外端點：更新 `API.md`、`docs/openapi/openapi.yaml`、`CHANGELOG.md`。
- 若新增使用者可見字串（授權頁），zh-TW／en 兩份翻譯同步。
- 授權頁／管理頁只做 React/Inertia，不新增 Blade。

### 6.6 下游（Node.js）接入

直接用標準庫，只需 issuer URL，由 discovery 自動配置：

- [`openid-client`](https://github.com/panva/node-openid-client)：通用，首選。
- `express-openid-connect`：Express 專案幾行設定。
- `passport-openid-connect`：已用 Passport 的專案。

前提：我們的 Provider 輸出必須合規（`iss`、`aud`、`exp`、`nonce`、JWKS `kid`），否則這些庫會拒絕。**所以實作後要用 `openid-client` 做一次真實端到端驗證，而不是只靠單元測試。**

### 6.7 回頭路：與歷史遷移的關係

- 重新安裝 Passport 的 migration 會建立 `oauth_*` 表，與 `2025_12_18_050122_remove_passport_oauth_tables` 的先後順序要核對：兩種順序結果不同。**用全新 DB 與既有 prod DB 各驗一次 `migrate`**，並確認 prod 的 `migrations` 表紀錄不會讓 Passport 的 migration 被跳過。
- **用 `vendor:publish` 把 Passport 的 migration 以「當下時戳」發佈進 `database/migrations/`，不要依賴套件自動載入**：自動載入的 2016 年代時戳在全新 DB 上會排在 drop migration 之前，結果是「先建後刪」，最終沒有 `oauth_*` 表；而既有 prod DB 的 drop 已記錄，新發佈的 migration 會在其後執行，沒問題。
- **本文對 Passport／league 內部行為的陳述**（refresh grant 不呼叫使用者狀態 hook、PKCE 僅對 public client 強制、`kid` 由公鑰衍生、access token 與 id_token 共用金鑰、Passport 13 不自動註冊授權視圖、`skipsAuthorization()` 機制）**來自調研與 review，實作時一律先對著當時安裝的版本原始碼核實**，不要當成已驗證事實。
- 那兩個 PR 是刻意移除 Passport 的決策，動手前先在 PR 描述說明「為何現在需要 OIDC」，並改寫 `docs/API_AUTHENTICATION.md` 的「為什麼選擇 Sanctum」。
- **不要新增 `passport` guard**：`api` 維持 sanctum，Passport 只用於 `/oauth/*`。`/userinfo` 需要能驗 Passport 簽發的 token，Sanctum guard 驗不了。**建議不啟用 `/userinfo`**：id_token 已含所需 claims，也避開 Bearer 失敗被 `ThrottleFailedAuthentication` 計入的問題。

### 6.8 登入回跳加固

- `isSafeRedirectPath()` 目前只擋 `//` 開頭，`/\evil.com`、`/%09/evil.com` 這類瀏覽器會當成協定相對 URL 的值會通過。**在讓授權流程依賴這條回跳之前先加固**（拒絕反斜線與控制字元），並補測試。
- 授權請求在登入後要**重新驗證**，不能信任 session 裡存的 URL。測試要涵蓋 `state`／`nonce`／`code_challenge` 經登入往返後完整保留。

### 6.9 RP 端驗證與金鑰管理

- RP 必須驗 `iss`、`aud=client_id`、`exp`、`nonce`、簽章與演算法（鎖定 RS256，拒絕 `none`／HS256）。Passport 的 access token 本身也是 JWT、與 id_token 共用金鑰，**RP 不可把 access token 當身分證明**，也不可鬆散比對 `aud`。
- 金鑰：不入版控；`kid` 由公鑰衍生，輪換時舊公鑰要留在 JWKS 直到舊簽發的 token 全部過期；prod 跑 `config:cache`，金鑰路徑或 `PASSPORT_PRIVATE_KEY` 需跨部署穩定。
- 自訂的 discovery／JWKS 路由若寫 closure，輔助函式放 `app/helpers.php`，不得在 `routes/*.php` 宣告具名函式或常數（見 AGENTS 高風險區域）。
- `VerifyCsrfToken` 的 `SKIP_CSRF_TOKEN` 逃生門在 prod 絕不可開啟（授權核准的 POST 依賴 CSRF）。

## 7. 風險

| 風險 | 緩解 |
|---|---|
| OIDC 套件維護者在找接手人 | 實作前看近期 commit／issue；把依賴封在薄層，保留退回「自行在 league/oauth2-server 上簽 id_token」的可能 |
| 停用帳號仍可續期 | 6.3：refresh 前檢查 `isActive()` + 測試 |
| `email` 未驗證被下游當身份 | `email_verified=false`，文件明講以 `sub` 為準 |
| 任何有效帳號都能進下游 | 6.2：明確寫進下游接入說明，由下游白名單 |
| 與 Sanctum／眾包 token 混淆 | 保持 `api` guard 為 sanctum；OIDC 只用於 `/oauth/*`；文件分清三種憑證 |
| 登入回跳被當開放轉址 | §6.8：加固 `isSafeRedirectPath()` 並補測試 |
| 與 `Mcp::oauthRoutes()`、舊 drop migration 衝突 | §4、§6.7：護欄測試 + 全新／既有 DB 各驗一次 `migrate` |
| 簽章金鑰外洩或遺失 | 不入版控、部署流程管理、規劃輪換 |
| `/oauth/token` 被暴力破解 | 具名 limiter，並核對 `ThrottleFailedAuthentication` 的行為 |
| Passport migration 與專案規則衝突 | 6.5：逐表檢查 cascade／SQLite／型別 |

## 8. 建議的實作拆分（每步獨立 review、獨立 PR）

0. **先加固 `isSafeRedirectPath()`**（§6.8），它與 OIDC 無關、可獨立先上。
1. 核實套件（維護度、與 Passport 13／Laravel 12 的實測相容），裝上並跑通 migration（MariaDB + SQLite、全新與既有 DB），處理 cascade、`int unsigned` 型別與歷史 drop migration（§6.7）。
2. 授權端：授權頁／auto-approve、`isActive()` 把關、登入回跳、client 建立命令。
3. id_token／claims 白名單、discovery、JWKS、`email_verified` 規則。
4. refresh 與停用帳號、token 壽命、限流。
5. 以 `openid-client` 做端到端驗證；文件（`API.md`、openapi、接入說明）。

## 9. 開放問題

1. 一般用戶與眾包用戶是否也能登入下游？（§6.2 的預設決議是「是」，這裡只是請使用者最後確認；若否，需要 client 級範圍限制。）
2. 下游自己的 session 壽命上限是多少？決定 access token 該多短。
3. `RESERVED`（`is_active=2`）帳號是否視同停用？目前 `isActive()` 如此處理，OIDC 沿用即可，但請確認這符合意圖。

## 參考

- [jeremy379/laravel-openid-connect（Packagist）](https://packagist.org/packages/jeremy379/laravel-openid-connect)
- [mindtwo/laravel-identity（Packagist）](https://packagist.org/packages/mindtwo/laravel-identity)
- [ronvanderheijden/openid-connect（Packagist）](https://packagist.org/packages/ronvanderheijden/openid-connect)
- 本專案：[docs/API_AUTHENTICATION.md](./API_AUTHENTICATION.md)、[docs/MCP_HTTP_INTERFACE_PROPOSAL.md](./MCP_HTTP_INTERFACE_PROPOSAL.md)
