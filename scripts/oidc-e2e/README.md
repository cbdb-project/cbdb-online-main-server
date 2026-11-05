# OIDC Provider 端到端驗證

用下游真正會用的標準函式庫（[`openid-client`](https://github.com/panva/openid-client)，Node.js）對本專案的 OIDC Provider 走一遍真實流程。

Provider 的其餘測試（`tests/Feature/Oidc*Test.php`）都是我們自己寫的，驗證的是「我們以為的 OIDC」。只有讓下游的函式庫實際去做 discovery、抓 JWKS、驗 id_token 的簽章與 `iss`／`aud`／`exp`／`nonce`，才能證明兩邊真的互通。

設計背景與決議見 [docs/OIDC_PROVIDER_PLAN.md](../../docs/OIDC_PROVIDER_PLAN.md)，對外說明見 [API.md](../../API.md) 第十五章。

## 執行

```bash
scripts/oidc-e2e/run.sh
```

需要 PHP 與 Node.js ≥ 20.11（第一次會自動 `npm ci`，依賴只有 `openid-client` 與它的兩個相依）。大約 20 秒。結束碼 0 表示全部通過；任何一項失敗都會印出原因與伺服器日誌。

## 它做了什麼

`run.sh` 啟動一個**完全隔離**的測試環境，`e2e.mjs` 在上面走流程：

1. **Discovery**：`openid-client` 抓 `/.well-known/openid-configuration` 並逐字比對 `issuer`；discovery 只宣稱真正支援的東西；偽造的 `Host` 標頭不改變任何端點。
2. **授權碼流程（真實的瀏覽器路徑）**：未登入訪問授權端 → 被導到 `/login` → 登入 → 回跳到**原本那條完整的授權網址**（`state`／`nonce`／`code_challenge` 一個都不能丟）→ 帶著 code 回到 RP → `openid-client` 兌換並**自己驗證** id_token（簽章、`iss`、`aud`、`exp`、`nonce`、`state`）。另外確認 id_token 只有白名單內的 claim、不簽發 refresh token、`kid` 與 JWKS 一致。
3. **必須失敗的情況**：授權碼重放、錯的 `code_verifier`、竄改 `state`、`nonce` 不符、沒有 PKCE、沒有 `openid` scope、未註冊的 `redirect_uri`、不開放的 scope（`phone`、`mcp:use`）、其他 grant（`refresh_token`、`client_credentials`、`password`）。
4. **停用帳號**：已停用的帳號無法登入；授權後、兌換前被停用則兌換失敗；停用之前登入的瀏覽器 session 拿不到新的授權碼。

## 隔離保證（為什麼可以放心跑）

- **資料庫**：臨時目錄裡的 SQLite 檔案，不是你 `.env` 指向的資料庫。`seed.php` 有護欄：資料庫不是位於工作目錄內的 SQLite 就**直接中止**（它會 drop／建立 `users` 表，指到開發或正式資料庫就是災難）。
- **金鑰**：每次新生成，經環境變數 `PASSPORT_*_KEY` 提供，**不碰** `storage/oauth-*.key`。
- **設定快取**：`APP_CONFIG_CACHE`／`APP_ROUTES_CACHE` 指向不存在的檔案，伺服器不會讀開發機上可能殘留的 `config:cache`／`route:cache`（那會讓上面所有隔離失效）。
- **行程**：只結束這個腳本自己啟動的那一個 PHP 伺服器，並刪除臨時目錄。
- `SKIP_CSRF_TOKEN=true` 只設在這個測試伺服器的行程環境，不會寫進任何檔案。

## 限制

- **不在 CI 裡跑**：它需要網路（`npm ci`）、本機埠與 Node 的 `openid-client`；Provider 的行為已有 PHP 測試在 CI 把關。改動 OIDC 相關程式（`OidcServiceProvider`、中介層、`app/Support/Oidc/*`、`config/openid.php`）後，請手動跑一次。
- 驗證的是 **http://127.0.0.1** 的 issuer（本機開發環境 `oidc:check` 允許）。https、反向代理、多節點部署要在實際環境另外驗證。
- 鎖定行為（`lockForUpdate`）在 SQLite 是 no-op，這個腳本無法驗證列鎖的並發序列化；見 API.md 15.5。
