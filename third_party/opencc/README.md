# OpenCC 繁簡對照資料（vendored）

`TSCharacters.txt` 是原封不動 vendor 進來的 [OpenCC（Open Chinese Convert）](https://github.com/BYVoid/OpenCC)
字典檔，以 [Apache License 2.0](./LICENSE) 授權（與本專案主體採用的
[CC BY-NC-SA 4.0 International](../../LICENSE.md) 授權不同，見專案根目錄
[LICENSE.md](../../LICENSE.md) 的「第三方授權例外」一節）。

## 內容

- `TSCharacters.txt`：OpenCC 原始字典檔，**未經任何修改**，字元級繁體→簡體對照
  （`trad\tsimp1 simp2 ...`，`#` 起為註解）。
- `LICENSE`：Apache License 2.0 全文。

## 來源版本

| 項目 | 值 |
|---|---|
| 上游倉庫 | https://github.com/BYVoid/OpenCC |
| 檔案路徑 | `data/dictionary/TSCharacters.txt` |
| Vendor 時對齊的 commit | [`fd8e6bf`](https://github.com/BYVoid/OpenCC/commit/fd8e6bfe1a73ada14e9e654b7df27b51c49f6ba2)（2026-07-25，`master` HEAD） |
| 該檔案最後一次修改的 commit | [`56d028a`](https://github.com/BYVoid/OpenCC/commit/56d028aa324c407a51b74da7891450e408432569)（2026-07-07，#758 繁简转换「牴→抵」；「復、複、覆」相关修正） |

每次執行 `php artisan cbdb:sync-opencc-trad-simp` 更新後，請更新上表的 commit 資訊（可用
`gh api repos/BYVoid/OpenCC/commits/master --jq '.sha,.commit.committer.date'` 查詢），讓這裡
的記錄跟檔案內容保持同步，方便回溯「這份 vendored 資料對應上游哪個版本」。

**這裡刻意不放任何衍生檔案**（例如預先解析好的 PHP 陣列）：`App\Support\TradSimpMap` 會在
讀取當下直接解析 `TSCharacters.txt`（行程內快取一次），更新這份 vendored 檔案後不需要任何
「重新產生」的額外步驟——下次讀取就會直接反映新內容。

## 更新

```bash
php artisan cbdb:sync-opencc-trad-simp
```

下載最新版本並覆蓋 `TSCharacters.txt`。覆蓋後用 `git diff` 審查變化，提交後隨一般部署流程
上線——**不在生產環境執行**。

## 讀取入口

`App\Support\TradSimpMap`（唯一讀取入口，供 `NameSearchIndexService`／`RebuildNameSearchIndex`
建置姓名搜尋索引使用）：
- `TradSimpMap::baseMap()` — 只解析 `TSCharacters.txt`，同形映射（trad === simp）一律排除
- `TradSimpMap::full()` — 疊加人工補充映射後的完整對照表

人工補充映射（OpenCC 未收錄的異體字，如 栢→柏）獨立存放在
`config/trad_simp_manual_overrides.php`，不寫入這份 vendored 檔案，詳見該檔案內註解。
