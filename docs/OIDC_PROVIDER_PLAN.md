# OIDC Provider 方案評估：把後台登入提供給其他專案

> 狀態：**§8 的第 0–5 步都已實作並合併**（2026-11-05）；總開關 `OIDC_PROVIDER_ENABLED` **預設關閉**，開啟前請逐項過 §8.1 的上線前檢查清單。
> 本文件前半（§1–§7）是當初的評估與決議，後半（§8）記錄各步實際做了什麼與實作中發現的事——**以 §8 為準**，兩者有出入時（例如 §1 曾列 refresh token，§8 第 4 步已決議不簽發），§8 是現況。
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

- **做：** OIDC 授權碼流程 + PKCE、`openid`／`profile`／`email` scope、id_token、discovery、JWKS、token 撤銷。**不簽發 refresh token**（原稿曾把它列在「做」裡；第 4 步決議不簽發，理由見 §8 第 4 步——下游只在登入那一刻要 id_token）。
- **不做：** 單點登出（含 RP-Initiated 與 Back-channel）、角色／權限 claim、同意頁（first-party client 跳過）、client 自助註冊、MFA、implicit／password grant。

## 2. 協定概述

OIDC = OAuth 2.0 授權碼流程 + 身份層：

1. RP 把使用者導到本專案 `/oauth/authorize`（帶 `client_id`、`redirect_uri`、`scope=openid profile email`、`state`、`nonce`、PKCE challenge）。
2. 使用者在本專案登入（沿用現有 session 登入）。
3. 本專案帶一次性 `code` 導回 RP 的 `redirect_uri`。
4. RP 後端以 `code` 呼叫 `/oauth/token`，換得 `access_token`、`id_token`（JWT）。（原稿還有 `refresh_token`；**實際不簽發**，見 §8 第 4 步。）
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
| `laravel/mcp` 的 OAuth | `Client/OAuth/*` 是客戶端側；但 `Server/Registrar.php` 會引用 `Laravel\Passport\Passport`，其 `Mcp::oauthRoutes()` 會註冊 `/.well-known/oauth-authorization-server`、`/.well-known/oauth-protected-resource`、`POST oauth/register`，並把 `mcp:use` 塞進 Passport scopes。應用目前**從未呼叫** `oauthRoutes()`（app／routes／config 皆無） | 現在無衝突。裝上 Passport 後**不要呼叫 `Mcp::oauthRoutes()`**——不是因為路徑與本計畫的 discovery 撞車（路徑不同），而是它會開放 `POST oauth/register`（動態 client 註冊）並新增 `mcp:use` scope，與「只有手動建立的 first-party client」的前提相反（建議加護欄測試） |
| 現有 token | Sanctum（`api` guard，`expiration => null`）；MCP 端點掛 `auth:sanctum` + `mcp.ability`；另有 `confirmation_token` 眾包憑證 | OIDC 是第三套憑證，**不要與之混用或重用**；`api` guard 仍是 sanctum，Passport 只用於 `/oauth/*` |
| 登入 | `web` guard（session）、`Auth\LoginController`；登入後 `authenticated()` 擋 `!isActive()` | 未登入訪問 `/oauth/authorize?...` 時走 `Handler::unauthenticated()` → `redirect()->guest(route('login'))`，**完整 URL（含 query）存進 `url.intended`**，登入後由 `redirect()->intended()` 回跳，所以 `state`／`nonce`／`code_challenge` 會保留（React 登入頁是否原樣回傳尚未驗證）。`storeIntendedRedirect`／`isSafeRedirectPath` 只服務顯式的 `?redirect=` 輸入，原本只擋 `//` 開頭、檢查偏弱，**第 0 步已加固**（拒絕反斜線／控制字元／空白及路徑中的編碼形式，見 §6.8） |
| `auth` middleware | 專案覆寫了 `auth` 別名（`App\Http\Middleware\Authenticate`，含 `isActive()` 檢查） | Passport 預設的 `/oauth/authorize` 路由中介層不保證用的是這個別名，**必須明確確認**授權端走的是會檢查 `isActive()` 的那一個 |
| 帳號狀態 | `is_active`：0 停用、1 啟用、**2 RESERVED**；`isActive()` 只認 1 | 授權與簽發 access token 都必須以 `isActive()` 為準（不簽發 refresh token，沒有 refresh 要把關） |
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

### 5.1 套件核實結果（2026-11-05，第 1 步調研，唯讀）

以 GitHub API 與 `composer require ... --dry-run`（不寫任何檔案）核實。維護度、約束、sodium 要求已由獨立 review 對照 GitHub API 複驗；「解析結果」與「副作用」兩列來自作者本機實際執行的 dry-run 輸出，**未經第二人複驗**，實際安裝時會再看到真實結果：

| 項目 | 結果 |
|---|---|
| 維護度 | `jeremy379/laravel-openid-connect`：最後推送 2026-06-30，3.3.0（2026-06-30）、3.2.2（2026-03-31）、3.2.1（2026-03-25），0 個未決 issue，未封存。上游 `ronvanderheijden/openid-connect` 最後更新 2024-07，已停滯。`laravel/passport` v13.10.0（2026-10-13）、`league/oauth2-server` 9.4.1 皆活躍。**README 自述維護者在找接手人這點仍成立，但目前不是停滯狀態**——依賴要封在薄層（§7） |
| 約束 | openid-connect 3.3.0：`php >=8.2`、`laravel/framework ^12\|^13`、`laravel/passport ^13`、`league/oauth2-server ^8.2\|^9.2`、`lcobucci/jwt ^4\|^5`、`ext-openssl`。Passport 13.10：`php ^8.2`、`league/oauth2-server ^9.2`、`phpseclib ^4`、`firebase/php-jwt ^6.4\|^7`、`symfony/console ^7.1\|^8`。本專案 Laravel 12／PHP 8.4，**約束相容** |
| 解析結果 | 忽略 sodium 後可解：新增 Passport 13.10.0、openid-connect 3.3.0、league/oauth2-server 9.4.1、lcobucci/jwt 5.6.1、phpseclib 4.0.3、firebase/php-jwt 7.2.1、defuse/php-encryption 2.4.0、nyholm/psr7、php-http/discovery 等；**沒有與現有鎖定版本衝突** |
| ⚠️ `ext-sodium` | `lcobucci/jwt` 5.4+ 要求 `ext-sodium`（PHP 8.4 只能用 5.4 以上；5.3 只支援到 PHP 8.3），而 `league/oauth2-server` 9.4 要求 `lcobucci/jwt ^5.6`——所以對 league 是**傳遞性**要求，它自己的 composer.json 沒有直接寫 sodium。**本機 PHP 8.4.15 沒有**（`php -m` 無 sodium），不處理就解析失敗。CI 用 `shivammathur/setup-php@v2`（PHP 8.4，`phpunit.yml` 的 `extensions` 清單沒列 sodium，但 setup-php 預設會啟用，**已於 CI 實測：可用**，見 §8 第 1 步）。**prod 的 PHP 是否有 sodium 未知，必須在部署前確認**（`docs/UPGRADE.md` 第 747–749 行的 apt 安裝清單沒有列 sodium 套件；多數發行版的 `php8.4-common` 已含，但要實測） |
| 副作用 | 加 `--with-all-dependencies` 會連帶升級 `laravel/framework`（12.70.2→12.71.0）等 16 個既有套件。正式安裝請只更新必要套件，並精確列出，避免把依賴升級混進同一個 PR |
| 本機安裝注意 | 這台 Windows 的 `composer install` 會因 `vlucas/phpdotenv` 的 `nul.env` 失敗、`^` 會被 `.bat` 吃掉、`vendor/` 跨分支共用（裝了 Passport 後，其他分支的 vendor 也會多出它）。見使用者記憶 `reference_windows_composer_vendor_quirks` |
| 未驗證 | 套件在本專案實際行為（id_token 內容、`kid`、與 `auth` 別名／`isActive()` 的整合）尚未安裝實測，屬第 2 步之後 |

**Passport 13 已知坑**：不會自動註冊授權頁面視圖，`/oauth/authorize` 會 `BindingResolutionException`，需要 `Passport::authorizationView(...)`。本專案是 React/Inertia，且 first-party client 要跳過同意頁，所以實作時要決定「自訂一個極簡授權頁」還是「全部 auto-approve」。

## 6. 設計決議與待辦

### 6.1 身分與 claims

- `sub` = `users.id`（內部穩定鍵），**不用 email**。**必須明確轉成字串**（`(string) $user->id`）：OIDC 規定 `sub` 是 JSON string，而 `users.id` 是整數，沒有轉型的話很可能被序列化成 JSON 數字（取決於套件實作），符合規範的 RP 庫會拒絕。實作時要有測試直接解碼 id_token 斷言 `sub` 的型別是 string。
- claims 預設僅 `sub`、`name`；**`email` 只在下游確實需要時才輸出**（越少越不易出帳號串接錯誤），輸出時 `email_verified` 恆為 `false`。
- **不輸出** `is_admin`、`is_active`、`settings`、`institution`、`confirmation_token` 等任何欄位。claims 一律走**顯式白名單**，不可序列化整個 `User`。⚠️ 不要照抄 `Api\UserController::show()` 的欄位清單：它含 `is_admin`／`is_active`。
- 軟刪除帳號（`deactivate_soft_deleted_users`）不得核發 token；`users.id` 不得在刪除後重用。

### 6.2 「誰可以登入下游」

本專案只證明「這是一個有效的 CBDB 帳號」。**任何有效帳號（含一般用戶與眾包用戶）都能登入下游。** 這是不做角色權限的直接後果：

- 預設接受，由下游自行維護 `sub` 白名單。
- 若不能接受，才考慮在 client 上加「允許的帳號範圍」——這會重新引入授權邏輯，先不做。

### 6.3 停用帳號與 token 壽命（沒有單點登出的補償）

> ⚠️ **本節是設計階段的分析。第 4 步決議不簽發 refresh token**（見 §8 第 4 步），所以下面所有關於 refresh token 的條目（標有「僅日後開放 refresh 時才需要」）**目前都不適用**；保留它們是因為日後若下游真的需要長工作階段，這些就是必須補的檢查。其餘條目已實作：access token 壽命在第 2 步，授權碼兌換檢查、停用時撤銷、停用與簽發的競態在第 4 步，現況以 §8 為準。

- access token 壽命要短（建議 10 分鐘量級，第 2 步已明確設定；Passport 預設是一年）。id_token 的有效期由套件寫死為 1 小時、無法設定（見 §8 第 2 步），所以它只能當「登入當下的斷言」，不能當工作階段。
- 【僅日後開放 refresh 時才需要；目前不簽發 refresh token】**refresh 時必須檢查 `isActive()`**，否則停用帳號可無限續期。league/oauth2-server 的 refresh grant 不會呼叫任何「使用者狀態」hook，所以這**不是一行設定**，需要明確機制，擇一：自訂 `RefreshTokenRepository`／覆寫 refresh grant，或在 `/oauth/token` 前掛 middleware，經 `oauth_refresh_tokens` → `oauth_access_tokens` 的關聯找出使用者再檢查（refresh token 是不透明憑證，不能假設可從 token 本身解出使用者；檢查必須覆蓋實際核發新 access token 與 id_token 的那條路徑）。同理 refresh 時簽發的 id_token 也要擋。
- **授權碼兌換（`authorization_code` grant）同樣要檢查 `isActive()`**：帳號可能在發碼之後、兌換之前被停用。兌換要擋（refresh 的部分僅日後開放時才需要），另外「停用帳號」與「撤銷 token」放在同一個交易內，只能避免**撤銷做到一半中斷**，擋不住**並發**：兌換可能和停用同時發生，仍簽出新 token。要補上其一：發放路徑在簽發前對 `users` 該列加鎖（`lockForUpdate`）並重查 `isActive()`，或簽發後再查一次、失敗就撤銷剛簽出的 token。（**第 4 步採用前者**：檢查與落庫在同一個交易、鎖同一列 `users`，與停用時的撤銷用同一把鎖，**對 access token** 兩種先後順序都不會漏；id_token 的最後確認不在鎖內，見 §8 第 4 步。）若做不到嚴格串行，退而接受的窗口＝access token 壽命：競態中簽出的 **access token 本身**無法被事後撤銷追回，它會活到自己的過期時間，所以 access token 壽命要夠短。（設計時還曾考慮「這個上限要求 refresh 路徑也重查 `isActive()`，否則競態中簽出的 refresh token 能存活到自己的壽命」——**因為不簽發 refresh token，這個前提不存在，access token 的上限無條件成立**。這個上限**只針對 access token**；id_token 另論：最長 1 小時、無法撤回，只是登入當下的斷言，見 `API.md` 15.5。）
- 【僅日後開放 refresh 時才需要；目前不簽發 refresh token】**refresh token 的發放不要假設一定有**：Passport 預設會發 refresh token，但 OIDC 規範上它對應 `offline_access` scope。要嘛明定「first-party client 一律發、不需 `offline_access`」並在文件中寫明這是 Provider 的決定（OIDC Core 要求發放離線存取的 refresh token 前要取得同意；first-party client 由我們自己預先授權、視為預先同意，這是刻意的決議，必須寫進文件而非默認），要嘛要求 RP 申請 `offline_access` 並在 §1 的 scope 清單加上它。實作時擇一，並測 token 回應確實含（或不含）`refresh_token`。
- **停用帳號當下就撤銷該用戶全部 oauth token**：在管理端停用路徑（`/manage` 的 `performUserUpdate()` 與 `cbdb:manage-user`）掛撤銷，把「已簽出的 access token 還能用多久」縮到 access token 壽命（不簽發 refresh token，沒有更長的憑證）。這裡說的只是 access token：已簽出的 **id_token** 有效期是 1 小時、無法撤回，但它只是登入當下的身分斷言、不授予對任何受保護資源的存取（見 `API.md` 15.5）。
- 【僅日後開放 refresh 時才需要；目前不簽發 refresh token】**refresh token 壽命也要設上限**（Passport 預設很長，會抵銷短 access token 的補償），並決定是否輪替與重用偵測。實作時要測：帳號被停用後 refresh 失敗、`/authorize` 被拒、token 已被撤銷。
- 下游若是自簽 JWT 本地驗證，**看不到撤銷**：access token 的窗口期 = 剩餘壽命（預設 10 分鐘）；id_token 則最長 1 小時（套件寫死、無法撤回）。實務上下游沒有任何受保護資源要用這兩張 token 去存取，所以真正要緊的是下游**自己的** session：需告知下游開發者，自己的 session cookie 不要設太長（見 `API.md` 15.7）。
- 使用者在本專案登出，下游**不會**同步登出（刻意不做）。

### 6.4 Client 管理

- 一到兩個 first-party client，用 artisan 命令建立（Passport 內建 `passport:client`）即可；管理頁（React/Inertia）延後。
- 僅授權碼 + PKCE，不開 refresh（第 4 步決議不簽發 refresh token）、implicit、password、client_credentials。
- **PKCE 只對 public client 強制**（league 的行為）。Node 伺服器端 RP 若是 confidential client（有 secret），預設可以不送 PKCE。要嘛註冊成 `--public`，要嘛由伺服器端強制所有 client 必須帶 `code_challenge` 且僅接受 `S256`。
- first-party 免同意頁要有明確機制（例如自訂 Client model 的 `skipsAuthorization()`），並決定 `prompt=login`／`prompt=consent`／`max_age` 的政策：`login` 與 `max_age` 要能強制重新驗證；本計畫沒有同意頁，所以 **`prompt=consent` 不可被靜默當成已同意**：OIDC Core 規定伺服器無法取得同意時必須回錯誤（規範寫「通常是 `consent_required`」，具體錯誤碼實作時對照規範與套件行為）；`prompt=none` 在未登入時回 `login_required`。auto-approve 下 `GET /oauth/authorize` 對任何已登入瀏覽器都會直接發 code，**因此 `redirect_uri` 精確比對（不得有萬用字元）與 PKCE 是把關的重點，但不是全部**：授權碼必須一次性、綁定 client 與 PKCE verifier，RP 端還必須驗 `state`／`nonce`（§6.9）。這幾道少任何一道，自動核准都不算安全。

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

- ✅ **已完成（第 0 步）**：`isSafeRedirectPath()` 原本只擋 `//` 開頭，`/\evil.com`、`/<TAB>/evil.com`、`/%09/evil.com`、`/%5cevil.com` 這類值會通過。現在拒絕反斜線、控制字元與空白（原始形式），以及路徑部分的百分號編碼形式（`%00–%1f`、`%7f`、`%5c`）；查詢串裡的 `%20`、`%2F`、`%3A` 之類是正常內容，**不誤殺**（OIDC 的 `state`／`redirect_uri` 參數會用到）。測試見 `tests/Feature/LoginRedirectTest.php`。
- **實際可利用性要如實說**：這是**縱深防禦，不是已知可被利用的開放轉址**。`url.intended` 由 `redirect()->intended()` → `UrlGenerator::to()` 消費，單斜線開頭的值會被補上本站根網址（`formatRoot()`），所以上述 payload 的主機換不掉；`//…` 與 `https://…` 本來就被擋。加固的理由是這個欄位之後會承載 OIDC 授權請求，而且一道只擋 `//` 的檢查不該靠下游的補根行為才安全。
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
| 停用帳號仍可續期 | 不簽發 refresh token，所以不存在續期路徑（第 4 步決議）；access token 簽發前檢查 `isActive()` 並在停用時撤銷 |
| `email` 未驗證被下游當身份 | `email_verified=false`，文件明講以 `sub` 為準 |
| 任何有效帳號都能進下游 | 6.2：明確寫進下游接入說明，由下游白名單 |
| 與 Sanctum／眾包 token 混淆 | 保持 `api` guard 為 sanctum；OIDC 只用於 `/oauth/*`；文件分清三種憑證 |
| 登入回跳被當開放轉址 | §6.8：✅ 已加固 `isSafeRedirectPath()` 並補測試（原本屬縱深防禦缺口，非已知可利用） |
| 與 `Mcp::oauthRoutes()`、舊 drop migration 衝突 | §4、§6.7：護欄測試 + 全新／既有 DB 各驗一次 `migrate` |
| 簽章金鑰外洩或遺失 | 不入版控、部署流程管理、規劃輪換 |
| `/oauth/token` 被暴力破解 | 具名 limiter，並核對 `ThrottleFailedAuthentication` 的行為 |
| Passport migration 與專案規則衝突 | 6.5：逐表檢查 cascade／SQLite／型別 |

## 8. 建議的實作拆分（每步獨立 review、獨立 PR）

0. ✅ **已完成：加固 `isSafeRedirectPath()`**（§6.8），它與 OIDC 無關、獨立先上。
1. ✅ **已完成：安裝（惰性）**——`laravel/passport` 13.10.0 與 `jeremy379/laravel-openid-connect` 3.3.0 已裝上（`composer.lock` 純新增 16 個套件、既有套件 0 變動），**所有端點關閉**：`AuthServiceProvider::register()` 呼叫 `Passport::ignoreRoutes()`、`config/openid.php` 把 discovery／jwks／userinfo 路由全關。護欄：`tests/Feature/OidcProviderDormantTest.php`。實作中的發現：
   - **`ext-sodium`**：本機已在 `C:\php\php.ini` 啟用（備份在 `php.ini.bak-before-sodium-2026-11-05`）；**GitHub Actions 已實測可用**（本步 PR 的 `composer install` 與完整測試在 CI 通過）。已顯式寫進 `composer.json` 的 `require`（`ext-sodium: *`），缺它時錯誤訊息直接指向本專案。**prod 仍須在部署前確認**，否則 `composer install` 會失敗。
   - **Passport 的 migration 發佈時保留 2016／2024 年檔名**（`vendor:publish` 不會換成當下時戳）。已改為 `2026_11_05_00000{1..5}_*` 並加 `hasTable` 保護（§6.7 的風險確實存在）。遷移**沒有外鍵、沒有 CASCADE**（符合 §1.1）；`user_id` 是 `bigint unsigned` 而 `users.id` 是 `int unsigned`，因為沒有外鍵約束所以無害，將來若要加外鍵必須對齊。
   - **`oauth_*` 五張表已列入 `VariantReplaceScope::EXCLUDED_TABLES`**（§1.3：token、密鑰、redirect URI 必須保留原字）。這是 `VariantReplaceRegistryDriftTest` 抓到的，新增帶憑證的表時要記得做。
   - **`ExportMysqlToSqlite::CREDENTIAL_TABLES` 要同步**（Sourcery 指出）：清單裡原本就有四張 `oauth_*`（移除 Passport 時留下，註解寫「舊表」），現在它們是**現役**憑證表，已更正註解並補上 `oauth_device_codes`。每週對外釋出走 allowlist（第一道防線）不受影響，但裸跑 `db:export-to-sqlite` 會。**新增任何存憑證的表，都要檢查這份清單、`VariantReplaceScope`、以及釋出 allowlist 三處。**
   - **遷移保護改為 `PassportMigrationGuard`**（Sourcery 指出單純 `hasTable → return` 的兩個洞）：表已存在但**缺欄位 → 直接報錯**（不悄悄標成已套用，否則 Passport 在執行期才失敗）；`down()` 只在表為**空**時才刪（可能是跳過建立的既有表，或存著未過期憑證）。刻意**不**處理的：兩個部署行程同時跑 migrate 的競態——那是全站所有遷移共有的問題，部署本來就單行程。
   - **還沒做、仍待確認**：MariaDB 實機跑一次 migration（目前只在 SQLite 驗證）；prod 的 `migrations` 表是否殘留舊 Passport 檔名（不影響本方案，因為已改名）；`docs/API_AUTHENTICATION.md`「為什麼選擇 Sanctum」要等端點真的放行時再改寫。
2. ✅ **已完成：授權端與令牌端**（總開關 `OIDC_PROVIDER_ENABLED`，**預設關閉**）。只註冊 `GET /oauth/authorize` 與 `POST /oauth/token` 兩條路由（`OidcServiceProvider`）；id_token 的 `sub`／`name`／`email`／`email_verified` 走白名單（`App\Support\Oidc\UserIdentityRepository`）；端到端測試 `tests/Feature/OidcAuthorizationFlowTest.php`（24 個，八組變異檢查確認每道保護都有測試守著）；對外說明見 `API.md` §十五。**實作中發現的事**（每一項都是不照著做就會出事的）：
   - 🔴 **套件預設的 `IdentityEntity` 回傳寫死的演示身分**：Jon Snow、`jon.snow@dorne.com`、`email_verified => true`、電話、地址。不覆寫的話，每個 id_token 都帶著假身分與「已驗證」的 email。已改用自己的白名單實作，並有測試斷言不出現 `Jon Snow`／`dorne.com`／`phone_number`／`address`／`is_admin`／`confirmation_token` 等。
   - 🔴 **兩個 provider 同時被自動發現，Passport 的授權服務器會蓋掉帶 id_token 的那個**：症狀是 token 回應完全沒有 `id_token`，而且沒有任何錯誤。套件 README 要求在 `composer.json` 的 `extra.laravel.dont-discover` 加 `laravel/passport`（OIDC 包的 provider 繼承了它）。已加。**這條只有端到端測試抓得到**，單元測試與 migration 測試都看不出來。
   - 🔴 **`mcp:use` scope 會被 `laravel/mcp` 悄悄塞進 Passport**：它在 `app->booted()` 回調裡**合併**進 `Passport::$scopes`，於是 RP 能申請 `mcp:use`。`OidcServiceProvider` 的 `tokensCan()` 也放進 `booted()`、整個取代（我們的回調註冊得比它晚、所以後執行）。這是**順序依賴**，由 `only_openid_profile_and_email_scopes_exist` 與 `scopes_the_project_does_not_offer_are_rejected`（含 `mcp:use`）鎖住。
   - 🔴 **Passport 的完整路由集不能整套放行**：它含 `/oauth/clients` 的增刪改（任何已登入使用者都能建立 OAuth client）、`/oauth/personal-access-tokens`、`/oauth/tokens`、裝置授權。所以保持 `Passport::ignoreRoutes()`，只註冊需要的兩條；`POST/DELETE /oauth/authorize`（核准／拒絕）也不註冊，因為沒有同意頁。
   - **Passport 的授權路由只掛 `web`、沒有 `isActive()` 檢查**：被停用但 session 仍有效的帳號會直接拿到授權碼。`EnsureOidcUserIsActive` 把這種帳號當成未登入處理（登出＋作廢 session），`prompt=none` 時 RP 看到的是 `login_required`，與沒登入無法區分。
   - **PKCE 只對 public client 強制**：`RequirePkceS256` 對所有 client 一律要求 `S256`；缺漏直接回 `400`、不重導向（此時 `redirect_uri` 還沒驗證）。
   - **`prompt` 處理 Passport 13 已內建**（`none`／`login`／`consent`），不必重寫；沒有同意頁，所以 `prompt=consent` 與非 first-party client 以 `OidcConsentRequiredResponse` 回 `consent_required`（Passport 13 沒綁這個視圖會 `BindingResolutionException`）。OIDC Core 規定伺服器無法取得同意時必須回錯誤（規範寫「通常是 `consent_required`」）。
   - **套件寫死的時間**：id_token 有效期固定 **1 小時**（`PT1H`）、授權碼固定 10 分鐘，都無法設定。Passport 自己的 access／refresh token 預設是**一年**，所以 `OidcServiceProvider` 明確指定 access token 壽命（預設 10 分鐘，env 可調）。（第 2 步當時 refresh token 也設為 7 天；第 4 步決議不簽發 refresh token 後，`OIDC_REFRESH_TOKEN_TTL_DAYS` 已移除。）
   - 🔴 **停用帳號的 `isActive()` 檢查只在 token 帶 `openid` scope 時才會被呼叫**（code review 發現，已實測證實）：檢查掛在 `UserIdentityRepository`，而套件的 `IdTokenResponse` 在沒有 `openid` scope 時整段跳過（含身分查詢）。`scope` 又是授權請求的選用參數。**實測**：臨時拿掉強制後，用 `scope=profile` 走完「授權 → 停用帳號 → 兌換」，token 端點回 **200 並給出 `access_token` 與 7 天的 `refresh_token`**，完全沒經過任何 `isActive()`。修法：`RequireOpenIdScope` 強制授權請求必須含 `openid`（缺漏回 400、不重導向），每張授權碼與其衍生的 token 因此都含 `openid`。這是把關能成立的**前提之一**，不是形式要求。
   - 🔴 **`POST /oauth/token` 上 `refresh_token` 與 `client_credentials` 是無條件註冊的**，只靠 client 自己的 `grant_types` 欄位擋。`client_credentials` 不需要使用者（管理員誤建 `passport:client --client` 就能無使用者簽發 token）；`refresh_token` 的把關在第 2 步當時還沒做。`RestrictOidcGrantTypes` **只放行 `authorization_code`**，其餘一律 `400 unsupported_grant_type`。（第 4 步的結果：**不簽發 refresh token，refresh 路徑永久關閉**，見第 4 步。若日後要開放，才需要把 `refresh_token` 加進 `RestrictOidcGrantTypes::ALLOWED` 並補「重查 `isActive()`、輪換、停用時撤銷」的測試。）
   - ✅ **（已於第 4 步解決）兌換授權碼時的 `isActive()` 檢查，第 2 步當時只掛在 `UserIdentityRepository`**（簽發 id_token 時才拒絕），所以失敗的兌換雖不會把 token 交給 RP，但 token 在檢查之前就已寫進資料庫、沒有被撤銷。第 4 步改為在 `ActiveUserAccessTokenRepository` 於簽發 access token **之前**檢查（落庫前拒絕、不留任何記錄、不論 scope），並在停用帳號時撤銷 OIDC 憑證；細節見下方第 4 步。這條只是保留當時發現問題的來龍去脈。
   - **PKCE S256 的 challenge 長度恆為 43**（Sourcery 指出）：SHA-256 經 base64url、去掉填充固定 43 字元；RFC 7636 的 43–128 是給 `plain` 用的。原本放行 43–128 會發出永遠兌換不了的授權碼，已改為恰好 43（並限定 `[A-Za-z0-9-_]`）。
   - **令牌端點用具名限流器 `oidc-token`**（Sourcery 指出）：數值型 `throttle:60,1` 的 key 是 `sha1(domain|ip)`，會與其他數值型 throttle 路由共用同一個 IP 計數器（`RouteServiceProvider` 裡 `mcp` 限流器的註解早就寫過這個坑）。以 IP 為鍵、不含 client_id（否則換個 client_id 就換一個桶，擋不住 secret 暴力猜測）；上限有夾範圍（0／負數退回 60，上限 600）。**多節點部署必須用共享 cache store**，否則實際上限是「節點數 × 上限」。
   - **「issuedBy 會變成字面值 `laravel`」是誤會**（Sourcery 指出、核實後不成立）：`IssuedByGetter` 讀到 `'laravel'` 時是**從請求推導 scheme＋host**，不是字面字串。但放在反向代理後面時推導可能漂移，所以仍建議明確設 `OIDC_ISSUER`。
   - **CI 與本機不同的陷阱**（這個 PR 的 CI 一度 20 個失敗）：CI 會 `cp .env.example .env`，Laravel 的 `.env` 載入器是 immutable 且記得自己載入過什麼，所以「在建立 app 前設環境變數來開啟開關」在同一個行程裡會被後續的 `.env` 載入蓋回去，本機的 `.env` 沒有該變數所以永遠看不出來。測試改用 `CreatesApplication::bootConfigOverrides()`。**以後涉及環境變數的改動，先用 `.env.testing`（`.env.example` 的副本）在本機模擬 CI 再跑。**
   - **【第 2 步當時的待辦清單，四項都已在第 4 步解決，僅供追溯；現況見下方第 4 步】第 4 步必須處理的（Sourcery 指出、核實後成立）**：① 授權碼消耗不是原子的（league 先讀「是否已撤銷」再撤銷，並發兌換理論上兩者都成功）——需要自訂 `AuthCodeRepository` 以條件式 UPDATE 消耗；② 兌換失敗時 token 已持久化卻沒撤銷；③ 停用帳號只撤銷 Sanctum token，不撤銷 Passport token；④ 停用與簽發之間的競態（鎖定 `users` 列並重查，或簽發後再查、失敗就撤銷）。這四項都寫在 `API.md` 15.5 對外說明。
   - **本機 PHP 的 openssl 沒有設定檔**，`openssl_pkey_new` 不能用；測試改用 Passport 自帶的 `passport:keys`（phpseclib），整個測試類只生成一次金鑰。
   - **開關是在 route 註冊時讀的**：`route:cache` 之後改 `OIDC_PROVIDER_ENABLED` 要重跑 `route:cache` 與 `config:cache`（與 `routes/ai.php` 依 `MCP_ENABLED` 同理）。
3. ✅ **已完成：discovery、JWKS、`kid`、金鑰部署與上線前檢查**（id_token／claims 白名單與 `email_verified` 規則在第 2 步完成）。測試：`OidcDiscoveryAndJwksTest`（10）、`OidcCheckCommandTest`（21，其中權限檢查在 Windows 跳過、CI 的 Linux 才真正跑）、流程測試新增「RP 視角」驗章；對外說明見 `API.md` 15.6。**實作中發現的事**：
   - 🔴 **套件自己的 discovery 對外宣稱了我們不支援的東西**：`grant_types_supported` 寫死含 `client_credentials` 與 `refresh_token`、`code_challenge_methods_supported` 含 `plain`、沒有 `claims_supported`。RP 的函式庫會據此行事（看到 refresh_token 就去用，然後吃 `unsupported_grant_type`）。所以**不用套件的 discovery**（`openid.routes.discovery` 永遠 false，有測試鎖住且斷言只有一條該路徑的路由），改由 `OidcDiscoveryController` 提供，**每一項都對應一個強制點**：grant 取自 `RestrictOidcGrantTypes::ALLOWED`（單一真源——日後若開放 refresh，discovery 自動跟上；目前不簽發 refresh token）、PKCE 只有 `S256`、scope 取自 `config/openid.php`、claims 對應 `UserIdentityRepository` 的白名單。測試用「禁止字串」斷言套件預設會宣稱的那些值都不出現。
   - **JWKS 用套件自帶的路由**，但只在總開關開啟時才打開：必須在 `OidcServiceProvider::register()` 設 `openid.routes.jwks`，因為套件在**自己的 `boot()`** 裡 `loadRoutesFrom()` 並當下讀設定，所有 `register()` 都先於任何 `boot()`。
   - **`kid` 是公鑰的 RFC 7638 指紋**（`SigningKey`），由公鑰決定、不必另外維護；id_token 頭部與 JWKS 都讀 `openid.token_headers.kid`，所以一定一致。測試由 JWKS 公布的 `n`／`e` **獨立重算**指紋，並以 `JWK::parseKeySet` + `JWT::decode` 做「只靠 JWKS 驗章」的 RP 視角驗證。**金鑰缺漏或格式錯誤時 `kid` 為 null，不在啟動時丟例外**（否則缺一個檔案整個站掛掉），改由 `oidc:check` 報出來。
   - ⚠️ **測試陷阱：金鑰目錄必須在 app 啟動『之前』設好**。`kid` 在 provider 的 `boot()` 計算，所以測試的金鑰助手 `Tests\Support\OidcTestKeys::install()` 要放在 `bootConfigOverrides()` 裡（它早於 provider 啟動）；放在 `setUp()` 會得到沒有 `kid` 的 id_token。
   - **金鑰輪換沒有新舊並存的過渡期**（套件一次只發布一把鑰）。換鑰＝換檔案＋部署，`kid` 自動跟著變，RP 的函式庫遇到不認得的 `kid` 會重抓 JWKS；換鑰前簽出的 id_token（最長 1 小時）之後驗不過，這沒有影響（id_token 只是登入當下的斷言）。換鑰瞬間進行中的登入會失敗一次、重試即可。
   - 🔴 **`docs/UPGRADE.md` 裡有舊 Passport 時代的 `passport:keys --force` 指示，照做會換掉簽章金鑰**。已加警示。新的規則：第一次部署只跑**一次** `passport:keys`，**之後不要加 `--force`**，私鑰權限 0600。`deploy.sh` 每次部署做 `config:cache`／`route:cache` 但不動 `storage/*.key`（已被 `.gitignore`），所以金鑰會在部署之間保留。
   - **`oidc:check`**（`App\Console\Commands\OidcCheck`，**要登記在 `app/Console/Kernel.php` 的 `$commands`**，本專案不自動載入命令）：檢查 `ext-sodium`、`APP_KEY`、**公鑰與私鑰各自獨立**檢查存在（來源可為環境變數或檔案）與私鑰權限、公鑰是有效 RSA 公鑰、**公私鑰是同一對**、**RSA 至少 2048 位元**、`APP_KEY` 能被 encrypter 接受（不只非空）、issuer 是有效 URL 且**不帶路徑**（discovery 只在主機根路徑）、非 local／testing 必須 https、Passport scope 沒被 `mcp:use` 污染；cache store 是 `file`／`array` 時警告；**無條件**提醒**多節點必須用同一對簽章金鑰**（命令只看得到本機；一度只在 cache 是 file／array 時才提醒，用 redis 的多節點部署反而收不到，review 發現後改為無條件）。issuer 另拒絕帶使用者資訊的值，scheme 大小寫不拘。**這些問題都不會讓應用啟動失敗，而是第一個下游登入時才以 500 或「id_token 驗不過」冒出來**，所以需要主動檢查。`deploy.sh` 新增 4b 步驟在開關開啟時自動跑，**失敗只警告、不中斷部署**（與 CHGIS 步驟一致）。總開關關閉時直接成功。
   - 🔴 **`oidc:check` 一度會誤報通過**（review 發現）：原本只要公鑰或私鑰『任何一邊』來自環境變數，就把整段存在與權限檢查跳過，於是「私鑰在環境變數、公鑰根本不存在」顯示『檢查通過（kid: ）』，實際上 `/oauth/jwks` 會 500；而且從未檢查公私鑰是不是同一對。已改為兩把鑰各自獨立檢查並比對 modulus／exponent，有測試守著（含變異檢查）。**上線閘門本身的漏洞比功能漏洞更危險，因為它讓人放心。**
   - 🔴 **discovery 的端點網址不可依請求的 Host 產生**（Sourcery 指出、已實測）：issuer 來自設定（可信）、`Host` 標頭不是，`route()` 會用後者，兩者不一致時文件會公布指向別處的端點。改為**以 issuer 為基底**（`route(..., [], false)` 取相對路徑再接上 issuer），測試用偽造的 `HTTP_HOST` 驗證。連帶移除了 `URL::forceScheme('https')`（它改動共用的 URL 產生器，常駐行程會影響後續請求）與 `OIDC_FORCE_HTTPS` 開關——scheme 由 issuer 決定，`oidc:check` 要求非 local／testing 的 issuer 是 https。
   - 🔴 **沒有明確 issuer 時，所有 OIDC 端點必須在執行期 fail-closed**（codex 指出、比它說的更大）：`OIDC_ISSUER` 與 `APP_URL` 都沒設時 `issuedBy` 退回 `'laravel'`，套件會**從請求的 Host 標頭推導 issuer**——不只 discovery，連 **id_token 的 `iss`** 也是。`oidc:check` 雖然會報錯，但那是人要記得去跑。`RequireExplicitIssuer` 掛在 authorize、token、discovery 三條路由上（排在所有其他檢查之前），`''`／`laravel`／`server` 一律回 503；測試涵蓋三個端點、三種推導值、以及『即使請求本身也不合法也先報 503』。
   - **issuer 不可帶路徑**：discovery 只在主機根路徑提供，OIDC Discovery 規定的 `{issuer}/.well-known/openid-configuration` 在帶路徑時是 404。`oidc:check` 會擋，並驗證 issuer 是有效 URL（`https://` 這種殘缺值原本會通過前綴檢查）。
   - **多節點部署必須用同一對簽章金鑰**（Sourcery 指出）：JWKS 只公布一把鑰，每個節點若各用自己的 `storage/oauth-*.key`，一個節點簽的 id_token 在另一個節點的 JWKS 下驗不過，登入成不成功取決於請求落在哪個節點。`oidc:check` 只能檢查本機，所以只能提醒；已寫進 `API.md` 15.6。
   - **JWKS 測試要斷言成員集合『恰好』等於公鑰允許的那幾個**（Sourcery 指出）：原本只排除 `d`／`p`／`q`，`dp`／`dq`／`qi`／`oth` 同樣是私鑰材料。
   - **issuer 結尾斜線只警告**：它是 `iss` 與 discovery 的 `issuer`，RP 會**逐字**比對，下游設定必須完全相同。
   - **沒有加 CORS、沒有給 discovery／JWKS 限流**：下游是伺服器端的 Node 程式抓取，不需要 CORS；兩者都是廉價的靜態回應。若日後被濫用再補。
4. ✅ **已完成：憑證撤銷與 token 持久化層加固**（測試：`OidcAuthorizationFlowTest` 新增 8 個、`OidcTokenRevocationTest` 8 個；每道保護都做過變異檢查）。
   - **設計決議：不簽發 refresh token，也不接受 `refresh_token` grant。** 重新審視需求：下游只需要在**登入那一刻**拿到 id_token 確認身分，沒有單點登出、下游自己管工作階段——refresh token 對我們沒有任何用處，卻是風險最大的一塊（長壽憑證，要在每次使用時重查帳號狀態、處理輪換與重用偵測、停用時撤銷）。與其把這些都做對，不如**根本不簽發**，攻擊面整個消失。做法：`NoRefreshTokenRepository::getNewRefreshToken()` 回 `null`，league 的 `issueRefreshToken()` 就不簽發、不落庫，回應也沒有 `refresh_token` 欄位。**這縮減了原本計畫的範圍（§1 曾列 refresh token）**；若日後下游真的需要長工作階段，要有意地改回來，並補「refresh 時重查 isActive()、輪換、停用時撤銷」的測試（`RestrictOidcGrantTypes` 與 `NoRefreshTokenRepository` 的 docblock 都寫了怎麼做）。連帶移除了 `OIDC_REFRESH_TOKEN_TTL_DAYS` 與 `Passport::refreshTokensExpireIn`（沒有東西要過期）。
   - **簽發 access token 之前檢查 `isActive()`**（`ActiveUserAccessTokenRepository`）：掛在 `persistNewAccessToken`，在落庫**之前**拒絕，失敗不留任何記錄，且**不論 scope**。這修掉了第 2 步發現的根因（`isActive()` 只在簽發 id_token 時被呼叫、沒有 `openid` 就整段跳過）；`RequireOpenIdScope` 因此成為縱深防禦。
   - **停用與簽發之間的競態用同一把列鎖關閉**：檢查與落庫在同一個交易、`lockForUpdate` 同一列 `users`；`AccountAccessRevoker::revokeOidcTokens()` 撤銷時鎖同一列。停用是「先改 `is_active`、再撤銷」，所以兩種先後都不會漏：簽發交易持鎖時，停用的 UPDATE 必須等它結束，之後的撤銷一定看得到那張新 token；停用先提交，簽發端讀到的就是 `is_active=0` 而被拒絕。⚠️ **`lockForUpdate` 在 SQLite 是 no-op，PHPUnit 只能驗證語意；列鎖序列化後來已在 MariaDB 10.11.14 上以兩個並發連線手動實測（見 §8.1 第 7 項，範圍限於原生 SQL 形狀）**（實測用隔離容器，不連使用者的 `.env` 指向的資料庫）。已寫進 `API.md` 15.5，上線前建議補一次。
   - **授權碼原子消耗**（`AtomicAuthCodeRepository`）：league 的順序是 `validateAuthorizationCode()` 先檢查 `isAuthCodeRevoked()`、簽發 token 之後才 `revokeAuthCode()`，兩個並發請求可以都讀到未撤銷。把 `isAuthCodeRevoked()` 改成一條 `UPDATE ... SET revoked=1 WHERE id=? AND revoked=0`，影響 0 列＝已用過，資料庫保證只有一個請求得到 1 列。測試驗證 SQL 形狀（有條件式 UPDATE、沒有對 `oauth_auth_codes` 的 SELECT）。**取捨**：消耗發生在 PKCE 驗證之前，所以一次失敗的兌換（`code_verifier` 不對）也會燒掉這張碼，使用者重新登入即可。**注意：Passport 預設就能擋住「串行」的第二次兌換，所以『同一張碼不能用兩次』這種測試抓不到原子性問題，必須驗證 SQL 形狀。**
   - **停用帳號時撤銷 OIDC 憑證**：`AccountAccessRevoker::revokeOidcTokens()` 撤銷 access token、尚未兌換的授權碼與（若存在的）refresh token；撤銷而非刪除（保留列供追溯，過期滿一天的列由 `passport:purge --expired` 清理（已撤銷但未過期者保留到過期後一天））；寫 `oidc_tokens_revoked_on_deactivation` 審計；表不存在時 no-op。兩個呼叫點（`ManagementController::revokeApiTokens()` 的停用／軟刪除／角色變更，與 `ManageUser` CLI）都已接上，並有靜態守衛。`passport:purge --expired --hours=24` 已排程（`Kernel::schedule()`，每日 04:15、僅在總開關開啟時，測試 `OidcPurgeScheduleTest`）；**但 prod 是否有 `schedule:run` 的 cron 仍未知**，沒有的話排程不會執行。
   - 🔴 **兩個測試自身的缺陷，都是變異檢查抓到的**：① 靜態守衛原本找 `revokeOidcTokens(` 字串，而被守的檔案**註解裡**也有這個字樣，所以呼叫被刪掉守衛照樣通過——改成只認 `->revokeOidcTokens($user,`；② 我自己的變異腳本一度沒有真正施加變異（`sed` 的反斜線模式沒匹配上，我把「`::class` 出現次數沒變」誤讀成「已刪除」），所以報出「測試全過」。**變異檢查必須驗證變異確實施加了**（例如被刪除的那一行的計數降為 0），否則『全過』什麼都證明不了。
5. ✅ **已完成：以 `openid-client` 做端到端驗證，與下游接入說明**。`scripts/oidc-e2e/`（`run.sh` 啟動完全隔離的伺服器與 SQLite，`e2e.mjs` 以 `openid-client` v6.8.9 走真實的瀏覽器路徑，**19 項檢查**全過；說明見該目錄 README）；接入說明見 `API.md` 15.7（與 e2e 腳本用同一套 API，是被實際跑過的）。
   - **這一步證明了什麼**：前面所有測試都是我們自己寫的，驗證的是「我們以為的 OIDC」。讓下游真正會用的函式庫去做 discovery（並逐字比對 issuer）、抓 JWKS、驗 id_token 的簽章與 `iss`／`aud`／`exp`／`nonce`／`state`，才證明兩邊互通。**它沒有發現產品缺陷**；失敗的三次都是腳本自己的假設錯誤：① Laravel 保存 `url.intended` 時會把查詢串**規範化**（按字母序、空格從 `+` 變 `%20`），參數集合完全相同，所以要比較集合而不是原始字串；② 已登入的 session 訪問授權端會直接帶 code 重導向，不經登入頁；③ 登入失敗的 `ValidationException` 會 `back()` 到 `Referer`，腳本沒帶 `Referer`（瀏覽器會帶）所以落到 `/`——真正的性質是「登入失敗後仍是未登入」，改為斷言這個結果。
   - **腳本本身有鑑別力**（變異檢查，並驗證變異確實施加）：去掉 `RequirePkceS256` → 「沒有 PKCE 被拒絕」那項變紅；去掉 `EnsureOidcUserIsActive` → 「停用帳號的舊 session 拿不到授權碼」那項變紅。
   - **隔離是這個腳本最重要的設計**：`seed.php` 會 drop／建立 `users` 表，指到開發或正式資料庫就是災難。護欄：資料庫不是位於工作目錄內的 SQLite 就**直接中止**——實測在這台機器（`.env` 指向 MySQL `cbdb_data`）直接執行會以 exit 3 中止、沒有任何寫入。另外：金鑰經環境變數提供、不碰 `storage/oauth-*.key`；`APP_CONFIG_CACHE`／`APP_ROUTES_CACHE` 指向不存在的檔案（否則開發機殘留的 `config:cache` 會讓所有隔離失效）；只結束自己啟動的那一個伺服器行程；`SKIP_CSRF_TOKEN` 只設在該行程的環境。
   - **不在 CI 裡跑**（需要網路與本機埠）；改動 OIDC 相關程式後請手動跑一次。驗證的是 `http://127.0.0.1` 的 issuer；https、反向代理、多節點要在實際環境另外驗證。
   - **`docs/openapi/openapi.yaml` 沒有更新**：它描述的是 v2 讀寫 API，OAuth／OIDC 端點是標準協定（discovery 文件本身就是機器可讀規格），`API.md` 第十五章已涵蓋。

### 8.1 上線前檢查清單（總開關保持預設關閉，直到逐項確認）

1. **prod 的 PHP 有 `ext-sodium`**：**已確認（2026-11-05，唯讀 SSH 檢查）**——prod 是 PHP 8.4.27，`php -m`（CLI）與 `php-fpm8.4 -m`（網頁請求實際用的）都列出 `sodium`，`php8.4-fpm` 服務為 active。
2. **第一次部署只跑一次 `php artisan passport:keys`**（之後不要加 `--force`），私鑰權限 0600；多節點部署所有節點必須使用**同一對**金鑰。
3. 設定 `OIDC_ISSUER`（對外 https 網址、不帶路徑）；沒有明確 issuer 時所有端點會回 503。
4. **`php artisan oidc:check` 通過**（`deploy.sh` 在開關開啟時自動跑，失敗只警告）。
5. 管理員以 `php artisan passport:client --name=… --redirect_uri=…` 為下游建立 client，把 `client_id`／`client_secret` 轉交下游。
6. 下游依 `API.md` 15.7 接入，**用 `sub` 識別使用者並自行維護白名單**。
7. **（已做，範圍有限）MariaDB 上的 `lockForUpdate` 序列化實測**：2026-11-05 在隔離容器（mariadb:10.11.14、REPEATABLE-READ）以兩個並發連線驗證——持鎖交易擋住同列的 `FOR UPDATE` 直到提交；停用先提交時，先做過一致性讀的簽發交易其鎖定讀仍讀到最新值。範圍限制：用原生 PDO 重現等價的主鍵等值鎖定讀，不是 `ActiveUserAccessTokenRepository` 本身在 MariaDB 上並發執行（測試套件強制 SQLite，無自動化回歸）；細節見 `API.md` 15.5。
8. **（選配，不擋上線）token 清理排程**：`passport:purge --expired --hours=24` 已排進 `Kernel::schedule()`，但它只在有 `* * * * * php artisan schedule:run` 的 cron 時才會自動跑。2026-11-05 唯讀 SSH 檢查：prod 目前沒有這條 cron（ubuntu crontab、`/etc/cron.*`、systemd timer 都沒有，也沒有 queue／schedule 類常駐服務）。**這不影響登入的正確性**——purge 只是清理過期的 token 列，下游只有一兩個、表長得很慢，不跑也沒有實際害處。要自動清理才需要由管理者加 cron（本次未改動 prod）；否則偶爾手動跑一次 `php artisan passport:purge --expired --hours=24` 即可。
   （同次檢查：prod 的 git 為 `66fce183`，尚未部署任何 OIDC 程式，`.env` 也沒有 `OIDC_PROVIDER_ENABLED`，開關自然是預設關閉。）
9. 設定 `OIDC_PROVIDER_ENABLED=true` 後重新部署（`route:cache`／`config:cache` 要重建才會生效），再跑一次 `scripts/oidc-e2e/run.sh` 以外的實際環境驗證（以測試帳號走一次完整登入）。

## 9. 開放問題

1. 一般用戶與眾包用戶是否也能登入下游？（§6.2 的預設決議是「是」，這裡只是請使用者最後確認；若否，需要 client 級範圍限制。）
2. ~~下游自己的 session 壽命上限是多少？決定 access token 該多短。~~ **已不相關**：不簽發 refresh token、access token 預設 10 分鐘且對下游沒有用途（沒有受保護的資源），下游的工作階段由它自己決定。
3. `RESERVED`（`is_active=2`）帳號是否視同停用？目前 `isActive()` 如此處理，OIDC 沿用即可，但請確認這符合意圖。

## 參考

- [jeremy379/laravel-openid-connect（Packagist）](https://packagist.org/packages/jeremy379/laravel-openid-connect)
- [mindtwo/laravel-identity（Packagist）](https://packagist.org/packages/mindtwo/laravel-identity)
- [ronvanderheijden/openid-connect（Packagist）](https://packagist.org/packages/ronvanderheijden/openid-connect)
- 本專案：[docs/API_AUTHENTICATION.md](./API_AUTHENTICATION.md)、[docs/MCP_HTTP_INTERFACE_PROPOSAL.md](./MCP_HTTP_INTERFACE_PROPOSAL.md)
