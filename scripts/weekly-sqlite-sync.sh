#!/bin/bash
#
# 舊檔名的相容轉接——請改用 weekly-sqlite-sync-and-oidc-purge.sh。
#
# 這支腳本改名時，prod 的 crontab 還指著舊路徑；改名後若沒有這個轉接，舊路徑一消失，
# 每週的 SQLite 釋出會無聲地停掉（cron 只會寫一行 No such file）。
# crontab 改指新檔名之後，這個檔案就可以刪除。
exec "$(dirname "$0")/weekly-sqlite-sync-and-oidc-purge.sh" "$@"
