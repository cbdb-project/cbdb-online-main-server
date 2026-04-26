# Bazel 與 BuildBuddy

本專案提供最小 Bazel 入口，用來在本機或 BuildBuddy Remote Execution 執行 PHPUnit。
`//:phpunit` 是由 `tests/**/*Test.php` 生成的 `test_suite`，每個 PHPUnit test 檔案會對應一個 Bazel test target，方便遠端平行執行。Composer vendor 會先由 `//:composer_vendor` 依 `composer.json` / `composer.lock` 產生 tarball，再提供給各測試 target 共用，避免每個測試檔重複安裝 Composer 依賴。

## 本機執行

```bash
bazelisk test //:phpunit
```

傳遞 PHPUnit 參數時使用 `--test_arg`：

```bash
bazelisk test //:phpunit --test_arg=--filter --test_arg=QueryPlaygroundTest
```

也可以直接跑單一生成 target：

```bash
bazelisk test //:phpunit__Feature_QueryPlaygroundTest
```

## BuildBuddy 遠端執行

專案 `.bazelrc` 只保存通用端點與公開執行環境，不保存 API key。請在使用者自己的 `~/.bazelrc` 設定：

```bazelrc
build:buildbuddy --remote_header=x-buildbuddy-api-key=<YOUR_API_KEY>
```

不要在包含 `--remote_header` 的環境中開啟 `--announce_rc`，否則 Bazel 會把 rc 來源與 header 內容印到本機 console log。

然後執行：

```bash
bazelisk test --config=buildbuddy-phpunit //:phpunit
```

`buildbuddy-phpunit` 目前使用公開 Laravel 測試容器：

```bazelrc
build:buildbuddy-phpunit --remote_default_exec_properties=OSFamily=Linux
build:buildbuddy-phpunit --remote_default_exec_properties=container-image=docker://kirschbaumdevelopment/laravel-test-runner:8.4
```

若 BuildBuddy 帳號使用自有 executor pool 或需要固定內部 image，可在 `~/.bazelrc` 覆蓋同一個 flag，例如：

```bazelrc
build:buildbuddy-phpunit --remote_default_exec_properties=OSFamily=Linux
build:buildbuddy-phpunit --remote_default_exec_properties=container-image=docker://registry.example.com/cbdb/phpunit:8.4
```

## 設計限制

- Bazel target 會在測試暫存目錄建立可寫工作副本，再執行 `composer install` 與 `vendor/bin/phpunit`，避免修改原始 checkout。
- API key、私有 registry 帳密等祕密一律放在使用者或 CI 的 Bazel 設定，不提交到 repo。
- 本機 `.env` 不會作為 Bazel input 上傳；遠端測試只會從 `.env.example` 產生測試用 `.env`。`bootstrap/cache/**` 也刻意排除，避免上傳可能含有本機環境值的 Laravel config cache。
- 遠端 executor image 必須提供 PHP 8.2+、Composer、SQLite/PDO SQLite 與常見 Laravel 測試所需 extension。
