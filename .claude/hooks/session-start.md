# Session Start Hook

## 環境配置

在開始工作之前，請自動執行以下步驟以確保測試環境就緒。

### 1. 檢查並安裝 SQLite 擴展

本項目使用 SQLite in-memory 數據庫進行測試（配置於 `phpunit.xml`）。

**關於預編譯擴展文件：**
- `.claude/php-extensions/` 目錄中的 `.so` 文件僅供**沒有安裝 SQLite 擴展的 PHP 8.4 環境**使用
- 適用於網絡受限的環境（如 Claude Code 網頁版）
- 如果系統已安裝相應擴展或可通過 apt-get 安裝，則不需要使用這些文件

請使用 Bash 工具執行以下檢查和配置：

```bash
# 檢測當前架構和 PHP 版本
ARCH=$(uname -m)
PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")

echo "🔍 檢測環境："
echo "  - 系統架構: $ARCH"
echo "  - PHP 版本: $PHP_VERSION"

# 檢查擴展是否已加載
if php -m | grep -q pdo_sqlite; then
    echo "✅ pdo_sqlite 擴展已加載，無需額外配置"
else
    echo "⚠️  pdo_sqlite 擴展未加載，正在嘗試配置..."

    # 方案 A：使用項目提供的預編譯擴展（僅限 PHP 8.4）
    if [ "$PHP_VERSION" = "8.4" ]; then
        PREBUILT_DIR=".claude/php-extensions/$ARCH"
        PHP_EXT_DIR=$(php-config --extension-dir)

        if [ -f "$PREBUILT_DIR/pdo_sqlite.so" ] && [ -f "$PREBUILT_DIR/sqlite3.so" ]; then
            echo "🔧 發現預編譯擴展（PHP 8.4 專用），正在安裝..."

            # 複製擴展文件
            cp "$PREBUILT_DIR/pdo_sqlite.so" "$PHP_EXT_DIR/"
            cp "$PREBUILT_DIR/sqlite3.so" "$PHP_EXT_DIR/"
            chmod 644 "$PHP_EXT_DIR/pdo_sqlite.so" "$PHP_EXT_DIR/sqlite3.so"

            # 創建 PHP 配置文件
            MODS_AVAILABLE="/etc/php/$PHP_VERSION/mods-available"
            CLI_CONF_D="/etc/php/$PHP_VERSION/cli/conf.d"

            if [ ! -f "$CLI_CONF_D/20-pdo_sqlite.ini" ]; then
                echo "; configuration for php pdo_sqlite module" > "$MODS_AVAILABLE/pdo_sqlite.ini"
                echo "; priority=20" >> "$MODS_AVAILABLE/pdo_sqlite.ini"
                echo "extension=pdo_sqlite.so" >> "$MODS_AVAILABLE/pdo_sqlite.ini"

                echo "; configuration for php sqlite3 module" > "$MODS_AVAILABLE/sqlite3.ini"
                echo "; priority=20" >> "$MODS_AVAILABLE/sqlite3.ini"
                echo "extension=sqlite3.so" >> "$MODS_AVAILABLE/sqlite3.ini"

                ln -sf "$MODS_AVAILABLE/pdo_sqlite.ini" "$CLI_CONF_D/20-pdo_sqlite.ini"
                ln -sf "$MODS_AVAILABLE/sqlite3.ini" "$CLI_CONF_D/20-sqlite3.ini"
            fi

            # 驗證安裝
            if php -m | grep -q pdo_sqlite; then
                echo "✅ 擴展安裝成功（使用預編譯版本）"
            else
                echo "❌ 擴展安裝失敗，請檢查版本兼容性"
            fi
        else
            echo "⚠️  預編譯擴展不存在，嘗試其他方式..."
        fi
    fi

    # 方案 B：嘗試通過 apt-get 安裝（網絡環境）
    if ! php -m | grep -q pdo_sqlite; then
        echo "🌐 嘗試通過 apt-get 安裝..."
        if apt-get update -qq && apt-get install -y -qq php$PHP_VERSION-sqlite3 > /dev/null 2>&1; then
            echo "✅ 擴展安裝成功（通過 apt-get）"
        else
            echo "❌ 無法安裝擴展：網絡受限且缺少預編譯文件"
            echo "📖 詳細說明請參考 .claude/php-extensions/README.md"
        fi
    fi
fi

echo ""
echo "📋 當前已加載的 SQLite 相關擴展："
php -m | grep -i sqlite || echo "  （無）"
```

### 2. 閱讀項目文檔

請使用 Read 工具閱讀項目的 AGENTS.md 文件以了解專案背景、技術棧、測試策略和開發規範。

這個文件包含：
- 專案技術棧概覽（Laravel、PHP 8.2+、DB 版本等資訊）
- 核心功能說明
- 測試策略和最佳實踐
- 開發流程和規範
- 常見問題和注意事項

### 3. 開始工作

環境配置完成後，你就可以開始協助用戶完成任務了。
