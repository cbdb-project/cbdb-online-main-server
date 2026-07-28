# 授權聲明 / License

## 主要授權 / Main License

本專案（CBDB Online Main Server）的源代碼和數據（除下述例外情況外）採用 [CC BY-NC-SA 4.0 International](https://creativecommons.org/licenses/by-nc-sa/4.0/) 授權。

The source code and data of this project (CBDB Online Main Server), except where otherwise noted, are licensed under [CC BY-NC-SA 4.0 International](https://creativecommons.org/licenses/by-nc-sa/4.0/).

---

## 第三方授權例外 / Third-Party License Exceptions

### OpenCC 繁簡字典數據 / OpenCC Traditional-Simplified Character Mapping Data

本專案中的 `third_party/opencc/TSCharacters.txt`（原 `CBDB__TRAD_SIMP_MAP` 資料表，2026-07 起改為原封不動 vendor 進版控的原始字典檔）數據來自 [OpenCC（Open Chinese Convert）項目](https://github.com/BYVoid/OpenCC)，該數據以 [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0) 授權，授權全文另附於 [third_party/opencc/LICENSE](./third_party/opencc/LICENSE)。

The data in `third_party/opencc/TSCharacters.txt` (formerly the `CBDB__TRAD_SIMP_MAP` table, vendored unmodified into version control in 2026-07) comes from the [OpenCC (Open Chinese Convert) project](https://github.com/BYVoid/OpenCC) and is licensed under the [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0), whose full text is also included at [third_party/opencc/LICENSE](./third_party/opencc/LICENSE).

**相關文件 / Related Files:**
- `third_party/opencc/TSCharacters.txt` (vendored 原始字典檔，未經修改)
- `third_party/opencc/LICENSE` (Apache License 2.0 全文)
- `app/Console/Commands/SyncOpenccTradSimpSource.php` (更新 vendored 檔案的指令)
- `app/Support/TradSimpMap.php` (讀取入口，於讀取當下直接解析，不另外產生衍生檔)

**Apache License 2.0 摘要 / Apache License 2.0 Summary:**

該授權允許使用者自由使用、修改、分發該數據，包括商業用途，但需保留版權聲明和授權聲明。詳見 [Apache License 2.0 全文](https://www.apache.org/licenses/LICENSE-2.0)。

This license permits users to freely use, modify, and distribute the data, including for commercial purposes, provided that copyright and license notices are retained. See [full text of Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0).

---

## 引用說明 / Attribution

如使用本專案數據或代碼，請引用：

When using data or code from this project, please cite:

**China Biographical Database (CBDB)**
https://cbdb.hsites.harvard.edu/

**對於 OpenCC 數據 / For OpenCC Data:**

BYVoid et al. OpenCC - Open Chinese Convert.
https://github.com/BYVoid/OpenCC

---

## 免責聲明 / Disclaimer

本軟件按「現狀」提供，不提供任何明示或暗示的保證。在任何情況下，作者或版權持有人均不對因使用本軟件而產生的任何索賠、損害或其他責任負責。

THIS SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY ARISING FROM THE USE OF THE SOFTWARE.
