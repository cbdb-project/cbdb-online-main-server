# OIDC Provider 維運手冊

本專案可以當 **OpenID Connect Provider**，讓自家其他專案（下游，Relying Party）用 CBDB 後台帳號登入。本文件是這個功能的**總入口與維運手冊**：它是什麼、現況、怎麼開關、怎麼為下游建立與管理 client、怎麼驗證、出事時看哪裡。

> ⚠️ **本文件與所有維運紀錄都不放憑證與個人資訊**：`client_secret`、`client_id`、使用者的姓名與 email、伺服器位址一律不寫進版控。`client_secret` 只在建立／重新產生時顯示一次，直接交給下游放進它的 `.env`。

## 文件地圖

| 想知道 | 看哪裡 |
| ------ | ------ |
| 它是什麼、怎麼開關、怎麼建 client、出事看哪裡 | **本文件** |
| 協定細節：端點、參數、id_token、RP 必須做的驗證、撤銷的限制、金鑰、Node.js 接入範例 | [API.md 第十五章](../API.md#十五oidc-provider實驗性預設關閉) |
| 設計決議、風險、實作分步、上線前檢查清單與 prod 開啟紀錄 | [OIDC_PROVIDER_PLAN.md](./OIDC_PROVIDER_PLAN.md)（以 §8 為準，§8.1 是檢查清單） |
| 改了 OIDC 程式之後怎麼確認沒壞 | [scripts/oidc-e2e/README.md](../scripts/oidc-e2e/README.md) |
| 改動前不照做就會出事的不變量 | [AGENTS.md](../AGENTS.md)「高風險區域備忘」的 OIDC Provider 一條 |

## 1. 一句話說明

下游把使用者導到本站 `/oauth/authorize`，使用者用本站帳號登入，本站帶一次性授權碼導回下游，下游再以 `client_id`／`client_secret` 到 `/oauth/token` 換到 **id_token**，從裡面讀出 `sub`（＝本站 `users.id` 的字串）、`name`、`email`。

刻意**沒有**的東西（設計決議，見方案 §6）：同意頁、單點登出、角色／權限 claim、MFA、refresh token、`/oauth/userinfo`、client 自助註冊、client 管理介面。**下游要自己決定誰能用它**（以 `sub` 維護白名單或管理員名單）；本站只回答「這個人是誰、帳號是否啟用」。

## 2. 現況

| 項目 | 狀態 |
| ------ | ------ |
| 程式 | 第 0–5 步全部合併（見方案 §8） |
| prod 總開關 | **已開啟**（2026-11-05），issuer 為 `https://input.cbdb.fas.harvard.edu` |
| prod 簽章金鑰 | 已產生一次（2026-11-05），之後不可再加 `--force` |
| token 清理 | 週六釋出腳本順帶執行（見 §7） |
| 下游 | 見 §5 的登記表 |

## 3. 設定

| 環境變數 | 預設 | 說明 |
| ------ | ------ | ------ |
| `OIDC_PROVIDER_ENABLED` | `false` | 總開關。關閉時 `/oauth/*` 與 discovery 全部 404 |
| `OIDC_ISSUER` | 退回 `APP_URL` | 對外 https 網址、**不帶路徑**。prod 的 `APP_URL` 不是對外網址，所以**必須顯式設**；沒有明確 issuer 時所有端點回 503 |
| `OIDC_ACCESS_TOKEN_TTL_MINUTES` | `10` | access token 壽命。下游用不到 access token（沒有受保護的資源），保持短 |
| `OIDC_TOKEN_RATE_LIMIT_PER_MINUTE` | `60` | `/oauth/token` 每 IP 每分鐘上限（最多 600）。**一個下游的所有使用者共用它那台伺服器的 IP**，下游使用量大時調高 |
| `PASSPORT_PRIVATE_KEY`／`PASSPORT_PUBLIC_KEY` | 讀 `storage/oauth-*.key` | 簽章金鑰；多節點時所有節點必須用同一對 |

設定在 `config/oidc.php`、`config/openid.php`。**路由在開機時依開關決定**：prod 跑 `route:cache`／`config:cache`，改任何上述變數後都要重建快取（`deploy.sh` 會做）。

## 4. 開啟與關閉

**開啟**（prod 已做過，步驟與核對見方案 §8.1 的紀錄）：

1. 確認 PHP（CLI 與 FPM）有 `ext-sodium`。
2. 只在第一次跑 `php artisan passport:keys`，**之後永遠不要加 `--force`**（會換掉簽章金鑰）。私鑰不可被其他使用者讀取：擁有者是部署帳號、網頁伺服器是另一個使用者時，用 `部署帳號:網頁伺服器群組` 加 `0640`（`passport:keys` 產生的 `0600` 會讓 PHP-FPM 讀不到）。
3. `.env` 先備份，再設 `OIDC_ISSUER` 與 `OIDC_PROVIDER_ENABLED=true`。
4. `php artisan oidc:check` 通過（若有舊的 config 快取，用 `APP_CONFIG_CACHE=/nonexistent php artisan oidc:check` 忽略它）。
5. 重建快取，再從外部核對：discovery 200 且 issuer 正確、`/oauth/jwks` 200、`/oauth/authorize` 與 `POST /oauth/token` 不帶參數回 400。

**關閉**：設 `OIDC_PROVIDER_ENABLED=false` 並重建快取。所有下游立刻無法登入（已登入下游的使用者不受影響，下游的工作階段是它自己的）。client 與金鑰都保留，之後再開不用重建。

## 5. 下游 client

### 5.1 登記表

每建立、修改、撤銷一個 client，就在這裡更新一列（**不寫 `client_id`／secret**；要查 `client_id` 用 §5.3 的列出命令，以名稱對照）。

| client 名稱 | 下游專案 | redirect URI | 建立日期 | 狀態 |
| ------ | ------ | ------ | ------ | ------ |
| CBDB QA Lab | `cbdb-qa-lab` | 該服務對外網址的 `/auth/callback`（目前是暫用的 sslip.io 主機名；換正式網域時照 §5.4 更新） | 2026-11-09 | 啟用中 |

### 5.2 為新的下游建立 client

1. 向下游取得**完整、精確**的 redirect URI（https、不可有萬用字元；主站逐字比對）。
2. 在 prod 執行（**不要加** `--client`、`--password`、`--personal`、`--implicit`、`--device`；只有下游是無法保密 secret 的前端程式時才加 `--public`）：

   ```bash
   php artisan passport:client -n --name="下游名稱" --redirect_uri="https://下游網址/auth/callback"
   ```

   多個 redirect URI 用逗號分隔，但非必要不要登記多個。
3. 輸出的 `Client ID` 與 `Client Secret` **只顯示這一次**（資料庫存的是雜湊）。直接交給下游放進 `.env`（權限 600），不要貼進聊天、issue、文件或同步盤。
4. 核對（不需要登入、不留下任何 token）：
   - 用正確的 redirect URI 打授權端，應 **302 到 `/login`**。
   - 把 redirect URI 換成別的網址，應 **401 `invalid_client`**。
5. 下游照 [API.md 15.7](../API.md#157-下游nodejs接入說明) 接入。下游需要的值只有 issuer、`client_id`、`client_secret`、redirect URI。
6. 下游接好後，以測試帳號走一次完整登入，再更新 §5.1 的登記表。

說明：
- 用命令建立的 client 沒有擁有者，算 first-party，**不經同意頁自動核准**（`App\Models\OidcClient::skipsAuthorization()`）。所以 redirect URI 精確比對與 PKCE 是把關重點。
- Passport 會在 client 的 `grant_types` 預設寫進 `authorization_code` 與 `refresh_token`。不必處理：令牌端由 `RestrictOidcGrantTypes` **只放行 `authorization_code`**。
- 這些操作直接寫 `oauth_clients`，**不經 v2 API、沒有 audit log**，所以要靠 §5.1 的登記表與 commit 留下紀錄。

### 5.3 列出、換 secret、改 redirect URI、撤銷

Passport 只有建立的命令，其餘用 `php artisan tinker`。prod 的登入 shell 是 fish（不支援 heredoc），把下面的 PHP 存成本機檔案，用 stdin 送進去：`ssh … "bash -c 'cd <專案目錄> && php artisan tinker'" < script.php`。

```php
// 列出（不含 secret）
DB::table('oauth_clients')->get(['id', 'name', 'redirect_uris', 'revoked', 'created_at'])->each(fn ($c) => print(json_encode($c, JSON_UNESCAPED_SLASHES) . PHP_EOL));

// 以下先取得 client
$repo = app(Laravel\Passport\ClientRepository::class);
$c = Laravel\Passport\Passport::client()->findOrFail('<client_id>');

// 換 secret（舊的立即失效；新的只在這裡印一次）
$repo->regenerateSecret($c); echo $c->plainSecret, PHP_EOL;

// 改 redirect URI（整組取代；舊的要移除，不要新舊並存）
$repo->update($c, $c->name, ['https://新網址/auth/callback']);

// 撤銷（保留列供追溯；令牌端與授權端都會拒絕已撤銷的 client）
$c->forceFill(['revoked' => true])->save();
```

**什麼時候換 secret**：secret 可能外洩（貼錯地方、下游伺服器被入侵、離職交接）。換完把新值交給下游、重啟下游；換的瞬間正在進行的登入會失敗一次，重試即可。

### 5.4 下游換網域

1. 主站用 §5.3 把 redirect URI **整組換成**新網址（舊的不保留）。
2. 下游同時改它自己的對外網址設定並重啟。
3. 使用者重新登入一次即可；`sub` 不變，下游以 `sub` 為鍵的資料不受影響。
4. 更新 §5.1 的登記表。

## 6. 帳號停用與撤銷

管理介面停用、軟刪除帳號或變更角色時，以及 `cbdb:manage-user` 把帳號改成停用時，該帳號的 OIDC 憑證（access token、尚未兌換的授權碼）會一併撤銷，並寫 `oidc_tokens_revoked_on_deactivation` 審計。停用的帳號**之後無法再登入任何下游**；但**已經登入下游的工作階段由下游自己管理，本站撤不掉**——要立即踢人，請下游在它那邊停用該 `sub`。細節與已知限制見 [API.md 15.5](../API.md#155-停用帳號憑證撤銷與已知限制)。

**新增任何會停用／刪除帳號的程式路徑時，都要呼叫 `AccountAccessRevoker::revokeOidcTokens()`。**

## 7. 例行維運

- **token 清理**：`scripts/weekly-sqlite-sync-and-oidc-purge.sh`（prod 每週六的 cron）在釋出後跑 `php artisan passport:purge --expired --hours=24`，失敗只警告。不清理只是過期列留著，不影響登入。
- **每次部署**：`deploy.sh` 在開關開啟時自動跑 `oidc:check`，失敗只警告、不中斷部署——**部署輸出裡看到 oidc:check 的失敗要處理**。
- **金鑰輪換**（非例行，只在私鑰可能外洩時做）：換掉金鑰檔並重新部署，`kid` 自動跟著變，下游會自動重抓 JWKS；換的瞬間進行中的登入失敗一次。見 [API.md 15.6](../API.md#156-discoveryjwks-與金鑰)。

## 8. 驗證

| 什麼時候 | 做什麼 |
| ------ | ------ |
| 改了任何 OIDC 相關程式 | `scripts/oidc-e2e/run.sh`（約 20 秒，隔離的伺服器與 SQLite，以 `openid-client` 走真實流程）與 `./vendor/bin/phpunit --filter Oidc` |
| prod 開關、金鑰、issuer 有變動 | `php artisan oidc:check`，加上 §4 第 5 步的外部核對 |
| 新建或修改 client | §5.2 第 4 步的兩個核對 |

## 9. 疑難排解

| 現象 | 原因與處理 |
| ------ | ------ |
| 重建快取後立刻請求 `/oauth/*` 得到 404 | opcache 的重新驗證視窗（約 2 秒），等幾秒再試；若持續 404，確認開關為 true 且已重建 route／config 快取 |
| 所有端點 503 `server_error` | 沒有明確 issuer（`OIDC_ISSUER` 與 `APP_URL` 都沒設） |
| 授權端 401 `invalid_client` | `client_id` 錯、client 已撤銷，或 redirect URI 與登記的不是逐字相同（常見：http／https、結尾斜線、主機名改了） |
| 授權端 400 | 缺 `openid` scope 或缺 PKCE `S256`（不接受 `plain`） |
| 授權端回 `consent_required` | 帶了 `prompt=consent`，或 client 有擁有者（不是用命令建立的）。本站沒有同意頁 |
| 令牌端 400 `unsupported_grant_type` | 下游用了 `authorization_code` 以外的 grant（例如 refresh） |
| 令牌端 429 | 超過每 IP 限流；下游使用量確實大時調高 `OIDC_TOKEN_RATE_LIMIT_PER_MINUTE` |
| 下游驗不過 id_token 簽章 | 私鑰與公鑰不成對、多節點用了不同金鑰，或 PHP-FPM 讀不到私鑰（權限）。跑 `oidc:check` |
| 下游報 `iss` 不符 | 下游設定的 issuer 要與 discovery 文件的 `issuer` **逐字相同** |
| prod CLI 跑 `route:list` 拋 APC 錯誤 | 既有現象（Prometheus 的 APC 儲存在 CLI 不可用），與 OIDC 無關；看 `bootstrap/cache/routes-v7.php` 或直接 curl |
