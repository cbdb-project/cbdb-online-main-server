#!/usr/bin/env bash
# OIDC Provider 的端到端驗證：以隔離的伺服器與資料庫，用標準函式庫（openid-client）走一遍真實流程。
# 用法：scripts/oidc-e2e/run.sh        （需要 PHP 與 Node >= 20；第一次會自動 npm ci）
# 說明見 scripts/oidc-e2e/README.md。
set -euo pipefail

cd "$(dirname "$0")/../.."
ROOT="$(pwd)"
PORT="${OIDC_E2E_PORT:-8765}"
WORK="$(mktemp -d)"
SERVER_PID=""

cleanup() {
  if [ -n "$SERVER_PID" ]; then
    # 只結束「這個腳本自己啟動的」那一個伺服器行程。
    kill "$SERVER_PID" 2>/dev/null || true
    if command -v taskkill >/dev/null 2>&1; then
      WINPID="$(ps -p "$SERVER_PID" 2>/dev/null | awk 'NR==2{print $4}')"
      [ -n "${WINPID:-}" ] && taskkill //F //PID "$WINPID" >/dev/null 2>&1 || true
    fi
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT

touch "$WORK/e2e.sqlite"

# ── 隔離：明確覆寫所有與資料庫、快取、金鑰有關的環境變數 ────────────────────────
# 行程環境變數優先於 .env，所以這裡的值一定勝出；APP_CONFIG_CACHE／APP_ROUTES_CACHE 指向不存在的檔案，
# 讓伺服器不讀開發機上可能殘留的 config:cache／route:cache（那會讓上面所有覆寫失效）。
export APP_ENV=local
export APP_DEBUG=false
export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
export APP_URL="http://127.0.0.1:${PORT}"
export APP_CONFIG_CACHE="bootstrap/cache/config.e2e-absent.php"
export APP_ROUTES_CACHE="bootstrap/cache/routes.e2e-absent.php"
export DB_CONNECTION=sqlite
export DB_DATABASE="$WORK/e2e.sqlite"
unset DB_URL
export CACHE_DRIVER=array
# cookie 驅動：session 存在（加密的）cookie 裡，伺服器端不寫任何檔案，不會在倉庫的 storage/ 留下 session 檔。
# （php -S 每個請求都是獨立的 PHP 行程，array 驅動撐不過請求；file 驅動則會把檔案留在 storage/framework/sessions。）
export SESSION_DRIVER=cookie
export QUEUE_CONNECTION=sync
export LOG_CHANNEL=errorlog
export SKIP_CSRF_TOKEN=true           # 只用於這個隔離的測試伺服器（VerifyCsrfToken 的測試開關）
export OIDC_PROVIDER_ENABLED=true
export OIDC_ISSUER="http://127.0.0.1:${PORT}"

echo "== 準備隔離環境（SQLite：$WORK/e2e.sqlite）"
php scripts/oidc-e2e/seed.php "$WORK"

# 金鑰用環境變數提供，不碰 storage/ 下既有的 oauth-*.key。
export PASSPORT_PRIVATE_KEY="$(cat "$WORK/oauth-private.key")"
export PASSPORT_PUBLIC_KEY="$(cat "$WORK/oauth-public.key")"

echo "== 上線前檢查（oidc:check）"
php artisan oidc:check

echo "== 啟動伺服器 127.0.0.1:${PORT}"

# 埠必須是空的：否則 php -S 會啟動失敗，而下面的就緒探測會連到「別人的」伺服器，
# e2e 就會對一個非預期的服務跑（甚至是別的專案）。
if curl -sS -o /dev/null --max-time 2 "http://127.0.0.1:${PORT}/" 2>/dev/null; then
  echo "中止：127.0.0.1:${PORT} 已經有服務在回應。請換一個埠：OIDC_E2E_PORT=8800 scripts/oidc-e2e/run.sh" >&2
  exit 1
fi

php -S "127.0.0.1:${PORT}" -t public >"$WORK/server.log" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 40); do
  # 我們啟動的那個行程若已經死了（例如埠被占用、PHP 啟動失敗），就不必再等。
  if ! kill -0 "$SERVER_PID" 2>/dev/null; then
    break
  fi
  if curl -fsS "http://127.0.0.1:${PORT}/.well-known/openid-configuration" >/dev/null 2>&1; then
    READY=1
    break
  fi
  sleep 0.5
done
if [ -z "${READY:-}" ]; then
  echo "伺服器沒有在時限內就緒。server.log:" >&2
  tail -n 30 "$WORK/server.log" >&2 || true
  exit 1
fi

echo "== 安裝並執行 openid-client 端到端驗證"
cd "$ROOT/scripts/oidc-e2e"
[ -d node_modules ] || npm ci --no-audit --no-fund --silent
set +e
node e2e.mjs "$WORK/e2e.json"
STATUS=$?
set -e

if [ "$STATUS" -ne 0 ]; then
  echo "---- server.log（最後 40 行）" >&2
  tail -n 40 "$WORK/server.log" >&2 || true
fi
exit "$STATUS"
