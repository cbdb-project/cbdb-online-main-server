---
name: 代碼提交前檢查
description: Git 提交前的必要檢查流程，包含代碼格式化（PHP-CS-Fixer）、測試驗證（PHPUnit）、前端編譯規範
---

# 代碼提交前檢查規範

## 何時使用此技能

在提交任何代碼變更到 Git 之前，**必須**執行此檢查流程以確保代碼質量和項目規範。

## 必要檢查項目

### 1. 代碼格式化 (PHP-CS-Fixer)

使用 PHP-CS-Fixer 確保代碼符合項目的編碼規範：

```bash
# 格式化所有 PHP 文件
./vendor/bin/php-cs-fixer fix

# 僅檢查而不修改（dry-run）
./vendor/bin/php-cs-fixer fix --dry-run --diff

# 格式化特定目錄
./vendor/bin/php-cs-fixer fix app/
./vendor/bin/php-cs-fixer fix tests/
```

**重要提醒：**
- 格式化後的文件需要重新 `git add`
- 確保所有格式化變更都已加入 commit

### 2. 運行測試套件 (PHPUnit)

確保所有測試通過，避免引入回歸問題：

```bash
# 運行完整測試套件
./vendor/bin/phpunit

# 運行特定測試文件
./vendor/bin/phpunit tests/Feature/CodesControllerTest.php

# 運行特定測試方法
./vendor/bin/phpunit --filter testMethodName

# 運行測試並顯示詳細輸出
./vendor/bin/phpunit --verbose
```

**測試失敗處理：**
- ❌ **禁止**在測試失敗時提交代碼
- ✅ 修復失敗的測試或調整測試以匹配新的行為
- ✅ 如果是預期的行為變更，更新相應的測試

### 3. 前端資源編譯（如適用）

如果修改了 Vue/JS 或 SCSS 文件：

```bash
# 生產環境編譯
npm run prod

# 開發環境編譯
npm run dev
```

**注意：**
- `public/js/app.js` 和 `public/css/app.css` 需要一同提交
- AdminLTE v3 頁面使用 CDN，不受此影響

## 完整的提交前工作流程

### 標準流程

```bash
# 1. 檢查當前狀態
git status

# 2. 格式化代碼
./vendor/bin/php-cs-fixer fix

# 3. 運行測試
./vendor/bin/phpunit

# 4. 如修改了前端資源
npm run prod

# 5. 查看變更
git diff

# 6. 添加文件
git add .

# 7. 提交（使用繁體中文）
git commit -m "feat: 新增功能描述

🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Sonnet 4.5 <noreply@anthropic.com>"
```

### 快速檢查（適用於小改動）

```bash
# 組合命令：格式化 + 測試
./vendor/bin/php-cs-fixer fix && ./vendor/bin/phpunit
```

## 常見問題

### PHP-CS-Fixer 失敗

```bash
# 查看具體錯誤
./vendor/bin/php-cs-fixer fix --verbose

# 清除緩存後重試
rm -rf .php-cs-fixer.cache
./vendor/bin/php-cs-fixer fix
```

### PHPUnit 測試失敗

```bash
# 查看詳細錯誤信息
./vendor/bin/phpunit --verbose --stop-on-failure

# 清除測試緩存
php artisan cache:clear
php artisan config:clear
```

### Git Hooks（如果配置）

如果項目配置了 pre-commit hooks，這些檢查可能會自動執行。如果被 hook 阻止：
- **不要**使用 `--no-verify` 跳過檢查
- **應該**修復 hook 指出的問題後再提交

## 提交規範補充

根據 `AGENTS.md` 第 161 行：
- ✅ 所有 Git commit message **必須使用繁體中文**
- ✅ 使用者介面使用繁體中文
- ✅ commit message 需包含 Claude Code 署名（如適用）

## 檢查清單

提交前確認：
- [ ] 運行 `./vendor/bin/php-cs-fixer fix` 並通過
- [ ] 運行 `./vendor/bin/phpunit` 所有測試通過
- [ ] 如修改前端，運行 `npm run prod` 並提交編譯產物
- [ ] `git diff` 確認只包含預期改動
- [ ] commit message 使用繁體中文
- [ ] 沒有遺留的 `dd()`, `dump()`, `console.log()` 等調試代碼

## 參考資料

- `AGENTS.md` 第 143-161 行 - 迭代流程與守則
- `phpunit.xml` - 測試配置
- `.php-cs-fixer.php` 或 `.php-cs-fixer.dist.php` - 代碼風格配置
