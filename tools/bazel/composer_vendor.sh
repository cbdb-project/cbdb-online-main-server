#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
    echo "usage: composer_vendor.sh <output-tar-gz>" >&2
    exit 2
fi

output="$1"
if [[ "${output}" != /* ]]; then
    output="${PWD}/${output}"
fi

work_root="${TMPDIR:-/tmp}/cbdb-composer-vendor-${RANDOM}-${RANDOM}"
home_root="${TMPDIR:-/tmp}/cbdb-composer-home-${RANDOM}-${RANDOM}"

cleanup() {
    rm -rf "${work_root}" "${home_root}"
}
trap cleanup EXIT

mkdir -p "${work_root}" "${home_root}"

for path in .env.example app artisan bootstrap composer.json composer.lock config database routes; do
    if [[ -e "${path}" ]]; then
        cp -R -L "${path}" "${work_root}/"
    fi
done

cd "${work_root}"

mkdir -p \
    bootstrap/cache \
    storage/app \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

export HOME="${home_root}"
export XDG_CACHE_HOME="${home_root}/xdg-cache"
export COMPOSER_CACHE_DIR="${home_root}/composer-cache"
export COMPOSER_HOME="${home_root}/composer-home"
export PATH="/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${PATH:-}"

composer install --prefer-dist --no-progress --no-interaction --no-ansi --no-scripts
tar -czf "${output}" vendor
