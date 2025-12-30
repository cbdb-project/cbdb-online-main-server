# PHP 擴展文件目錄

本目錄存放 Claude Code 網頁版環境所需的 PHP 擴展文件，用於解決網絡受限環境下無法通過 `apt-get` 安裝擴展的問題。

## 目錄結構

```
.claude/php-extensions/
├── x86_64/          # x86_64 架構的擴展文件
│   ├── pdo_sqlite.so
│   └── sqlite3.so
├── aarch64/         # ARM64 架構的擴展文件
│   ├── pdo_sqlite.so
│   └── sqlite3.so
└── README.md
```

## 如何獲取擴展文件

### 方法 1：從 Debian/Ubuntu 包中提取（推薦）

在**有網絡連接**的 Linux 環境中執行以下命令：

```bash
# 1. 下載對應架構的 deb 包
# x86_64 架構：
wget https://ppa.launchpadcontent.net/ondrej/php/ubuntu/pool/main/p/php8.4/php8.4-sqlite3_8.4.15-1+ubuntu24.04.1+deb.sury.org+1_amd64.deb

# aarch64 架構：
wget https://ppa.launchpadcontent.net/ondrej/php/ubuntu/pool/main/p/php8.4/php8.4-sqlite3_8.4.15-1+ubuntu24.04.1+deb.sury.org+1_arm64.deb

# 2. 提取 deb 包內容
dpkg-deb -x php8.4-sqlite3_*.deb extracted/

# 3. 找到擴展文件
find extracted/ -name "*.so"

# 4. 複製到對應的架構目錄
# 通常位於 extracted/usr/lib/php/20240924/ 下
cp extracted/usr/lib/php/20240924/pdo_sqlite.so .claude/php-extensions/x86_64/
cp extracted/usr/lib/php/20240924/sqlite3.so .claude/php-extensions/x86_64/
```

### 方法 2：從已安裝的系統複製

如果你有一台已安裝 `php8.4-sqlite3` 的 Linux 系統：

```bash
# 檢查 PHP 擴展目錄
php-config --extension-dir
# 輸出示例：/usr/lib/php/20240924

# 複製擴展文件
cp /usr/lib/php/20240924/pdo_sqlite.so .claude/php-extensions/x86_64/
cp /usr/lib/php/20240924/sqlite3.so .claude/php-extensions/x86_64/
```

## 版本兼容性

- **PHP 版本**：8.4.x（API 版本 20240924）
- **架構**：x86_64、aarch64
- **包來源**：ondrej/php PPA for Ubuntu 24.04

⚠️ **重要提醒**：
- 擴展文件必須與當前 PHP 版本的 API 號匹配（可通過 `php -i | grep "PHP API"` 查看）
- 不同架構的擴展文件**不可混用**
- 文件大小參考：`pdo_sqlite.so` 約 35KB，`sqlite3.so` 約 60KB

## 自動加載機制

Session Start Hook（`.claude/hooks/session-start.md`）會在每次會話啟動時：

1. 檢測當前系統架構（`uname -m`）
2. 檢查 `pdo_sqlite` 擴展是否已加載
3. 如果未加載且對應架構的擴展文件存在，自動軟鏈接到 PHP 擴展目錄
4. 驗證擴展是否成功加載

## 測試驗證

獲取並放置擴展文件後，可以通過以下命令驗證：

```bash
# 檢查擴展是否存在
ls -lh .claude/php-extensions/$(uname -m)/

# 測試加載（需要在 Session Start Hook 執行後）
php -m | grep -i sqlite

# 運行 PHPUnit 測試
./vendor/bin/phpunit
```

## 故障排查

### 錯誤：Extension version does not match

**原因**：擴展文件的 PHP API 版本與當前 PHP 不匹配。

**解決方案**：
1. 檢查當前 PHP API 版本：`php -i | grep "PHP Extension"`
2. 確保下載對應版本的擴展文件

### 錯誤：Cannot load extension

**原因**：架構不匹配或文件損壞。

**解決方案**：
1. 確認架構：`uname -m` 與擴展文件目錄一致
2. 驗證文件完整性：`file .claude/php-extensions/x86_64/pdo_sqlite.so`
3. 檢查文件權限：`chmod 644 .claude/php-extensions/x86_64/*.so`

## 貢獻指南

如果你成功獲取了擴展文件，歡迎將其提交到 repo：

```bash
# 添加到 Git
git add .claude/php-extensions/

# 提交（使用繁體中文）
git commit -m "新增：添加 PHP 8.4 SQLite 擴展（x86_64/aarch64）"

# 推送
git push
```

---

**提示**：如果環境支持網絡訪問，Session Start Hook 會自動嘗試通過 `apt-get` 安裝，無需手動獲取擴展文件。
