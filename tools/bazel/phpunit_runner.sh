#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${TEST_SRCDIR:-}" || -z "${TEST_WORKSPACE:-}" ]]; then
    echo "phpunit_runner.sh must be run by bazel test." >&2
    exit 2
fi

src_root="${TEST_SRCDIR}/${TEST_WORKSPACE}"
work_root="${TEST_TMPDIR}/cbdb-phpunit"
home_root="${TEST_TMPDIR}/home"

mkdir -p "${work_root}" "${home_root}"

find "${src_root}" -mindepth 1 -maxdepth 1 \
    ! -name '.git' \
    ! -name 'bazel-*' \
    -exec cp -R -L {} "${work_root}/" \;

cd "${work_root}"

export HOME="${home_root}"
export XDG_CACHE_HOME="${TEST_TMPDIR}/xdg-cache"
export COMPOSER_CACHE_DIR="${TEST_TMPDIR}/composer-cache"
export COMPOSER_HOME="${TEST_TMPDIR}/composer-home"
export npm_config_cache="${TEST_TMPDIR}/npm-cache"
export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${PATH:-}"

if ! command -v php >/dev/null 2>&1; then
    echo "PHP is required in the Bazel execution environment." >&2
    exit 127
fi

if [[ -f composer_vendor.tar.gz ]]; then
    tar -xzf composer_vendor.tar.gz
else
    if ! command -v composer >/dev/null 2>&1; then
        echo "Composer is required in the Bazel execution environment." >&2
        exit 127
    fi

    composer install --prefer-dist --no-progress --no-interaction --no-ansi
fi

if [[ ! -f .env && -f .env.example ]]; then
    cp .env.example .env
fi

mkdir -p \
    bootstrap/cache \
    storage/app \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover --ansi

mkdir -p public/build
# 沒有真 build 時給一份假 manifest，讓 @vite() 不會拋例外。
# ⚠️ 這份清單必須與 vite.config.js 的 input 完全一致——少一個 entry，任何 @vite 到它的
# Blade（含 partials/chgis-map-assets）都會在請求時拋 manifest 例外。
# 2026-09-15（環節 5b）：移除已刪的 app.js／datatables.js，並補上一直漏掉的 chgis-map/app.js。
if [[ ! -f public/build/manifest.json ]]; then
    cat > public/build/manifest.json <<'JSON'
{
  "resources/js/historical-maps/app.js": {
    "file": "assets/historical-maps.js",
    "src": "resources/js/historical-maps/app.js",
    "isEntry": true
  },
  "resources/js/chgis-map/app.js": {
    "file": "assets/chgis-map.js",
    "src": "resources/js/chgis-map/app.js",
    "isEntry": true
  },
  "resources/js/inertia/app.tsx": {
    "file": "assets/inertia-app.js",
    "src": "resources/js/inertia/app.tsx",
    "isEntry": true
  }
}
JSON
fi

php ./vendor/bin/phpunit "$@"
