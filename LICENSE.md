# 授權聲明 / License

## 主要授權 / Main License

本專案（CBDB Online Main Server）的源代碼和數據（除下述例外情況外）採用 [CC BY-NC-SA 4.0 International](https://creativecommons.org/licenses/by-nc-sa/4.0/) 授權。

The source code and data of this project (CBDB Online Main Server), except where otherwise noted, are licensed under [CC BY-NC-SA 4.0 International](https://creativecommons.org/licenses/by-nc-sa/4.0/).

---

## 第三方授權例外 / Third-Party License Exceptions

### OpenCC 繁簡字典數據 / OpenCC Traditional-Simplified Character Mapping Data

本專案中的 `CBDB__TRAD_SIMP_MAP` 表格數據來自 [OpenCC（Open Chinese Convert）項目](https://github.com/BYVoid/OpenCC)，該數據以 [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0) 授權。

The data in the `CBDB__TRAD_SIMP_MAP` table comes from the [OpenCC (Open Chinese Convert) project](https://github.com/BYVoid/OpenCC) and is licensed under the [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0).

**相關文件 / Related Files:**
- `database/migrations/2025_11_13_000000_create_internal_name_search_tables.php` (表結構定義)
- `app/Console/Commands/ImportTradSimpMap.php` (數據導入指令)
- OpenCC 源數據：`TSCharacters.txt` / `STCharacters.txt`

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
