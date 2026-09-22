<?php

use App\Models\BiogMain;
use App\Services\NameSearchIndexService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 一次性資料清理：把 `BIOG_MAIN` 姓名欄尾端的「數字消歧後綴」連同它前面那個空格一併刪除。
 *
 * ## 要刪的是什麼
 *
 * CBDB 用 `(n)` 區分同名者：`Jia Gongyan (2)`、`Chen Ji (2)`、`許瑤(2)`。這個序號**只**表達
 * 「他是第 n 個叫這個名字的人」，不承載任何人物資訊，但它長在姓名欄裡，於是每個消費端都得
 * 自己想辦法繞過它。最近的一例是拼音搜尋：#154（2021）為了讓 "hao yi" 撈不到 "Hao Yixing"
 * 把拼音欄改成整值精確比對，連帶讓搜 "Jia Gongyan" 找不到 "Jia Gongyan (2)"，直到
 * ca76dc88 才用「詞邊界前綴比對」繞開。把後綴從資料裡拿掉，才是那個註解裡說的根治方向。
 *
 * 處理四欄：`c_name`、`c_mingzi`（拼音，後綴前有一個半角空格）與 `c_name_chn`、
 * `c_mingzi_chn`（中文，後綴緊貼漢字、沒有空格）。dev 實測受影響列數：
 * 5,185 / 5,138 / 2 / 2。
 *
 * ## 刻意不做的事
 *
 *  - **不碰說明性括號。** `Guo Shi (Wife of Zhao Zhen)`、`Li Bai (zi)` 這類括號裡是**內容**
 *    而非序號，dev 上約 54,500 列。判準是括號內「全為數字」，不是「有括號」。
 *  - **不碰 3 位以上的數字括號。** 這純粹是**防禦性**的，不是在處理現有資料：
 *    「括號內是純數字且 3 位以上」在四欄的**任何位置**實測都是 **0 列**（消歧序號最大值是 33，
 *    即 2 位）。加這道限制的理由只有一個——這支 migration 不可逆（`down()` 是 no-op、
 *    不寫稽核），而 3 位數在姓名欄裡更可能是年份之類的內容而不是消歧序號，賭錯沒有救。
 *    真的出現時 `up()` 會逐列 echo 請人判斷，而不是靜默處理或靜默忽略。
 *    順帶一提，**真實存在**的「括號內帶數字」另有一類：`Zhao Shi (Zhao ji d33)`
 *    （趙佶第 33 女，c_name 37 列／c_mingzi 43 列）。那類括號內含字母，`[0-9…]+` 本來就不命中，
 *    與位數限制無關。
 *  - **不碰字串中段的括號。** 已知 4 列 `c_mingzi` 長成 `Fan (1) Baizhi`（personid 23881、
 *    24163、26374、35836）。那裡的 `(n)` 是 `pinyin` 表的**同音姓氏序號**、不是人物消歧
 *    序號——4 列全部符合 `c_mingzi = c_surname + " (n) " + 真正的 mingzi`，真正的病是姓氏
 *    誤植進了「不含姓氏的名」欄位。只刪括號會留下 `Fan Baizhi`，仍然是錯的，所以這裡一律
 *    只認**字串尾端**；那 4 列由維護者直接在系統裡逐列修正，不走這支 migration。
 *  - **後綴後面還跟著別的括號時，整格不動**（`Wang Wu (2) (zi)` 的 `(2)` 不會被清掉）。
 *    這是「只認字串尾端」的必然結果，比誤刪安全。實測 0 列（四欄皆 0）。
 *  - **不碰 `c_surname*`／`*_proper`／`*_rm`。** 全庫 0 列帶這個後綴（已實測）。
 *  - **不碰 `ALTNAME_DATA`。** 沒有這個樣式（已實測）。
 *  - **`CBDB__NAME_FTS` 會就地重建（只針對中文名真的變動的那幾列）。**
 *    `NameSearchIndexService::normalizeName()` 只剝**括號符號**、**保留內容**，所以
 *    `許瑤(2)` 在索引裡是 `search_term='許瑤2'`、`full_name='許瑤2'`（已查實際列確認）——
 *    「FTS 沒有這個樣式」是被剝掉的括號騙了，數字其實還黏在名字上。
 *    可搜性不會壞（查詢端是前綴 LIKE，`許瑤%` 照樣命中 `許瑤2`），壞的是兩件小事：
 *    `full_name` 會原樣回進 MCP `search_person_by_name` 的 `matched_terms`（於是後綴從姓名欄
 *    刪掉了、卻從搜尋結果冒出來），以及 `ORDER BY LENGTH(search_term)` 的相關度排序。
 *    而它**不會自己好**——migration 走 Query Builder、不觸發 model event，
 *    `BiogMainObserver::updated()` 不會被呼叫。
 *    只有中文名有進 FTS（拼音那 5,185／5,138 列與 FTS 完全無關），dev 上就 2 列，所以直接在
 *    這裡呼 `reindexPerson()`（與使用者編輯時同一條路）比留一個人工部署步驟可靠。
 *    ⚠️ **不要**改用 `php artisan cbdb:rebuild-name-search --id-from/--id-to`：那支只在
 *    `--truncate`（清**整張**表）時才刪舊列，ranged 模式是**新增**，會留下 `許瑤2` + `許瑤` 兩套。
 *  - **不動 `BiogMainRepository::applyPinyinNameMatch()`。** 清掉數字後綴之後，那個「詞邊界
 *    前綴比對」（ca76dc88）看起來像可以退回 #154 的整值精確比對——**不行**：說明性括號那
 *    ~54,500 列還在，搜 "Guo Shi" 仍然要能命中 "Guo Shi (Wife of Zhao Zhen)"。要等括號內容
 *    整批搬到獨立語義欄位之後才能退回。**不要跟著回收。**
 *  - **不保留序號。** 刪掉之後 `c_name` 的 5,185 列裡有 5,168 列會與既有人物同名，這是預期的
 *    ——姓名欄本來就沒有唯一索引（已確認 `BIOG_MAIN` 四個姓名欄上沒有任何索引），消歧靠
 *    `c_personid`。
 *
 * ## 為什麼不經 CharVariantMapService（AGENTS.md §1.3）
 *
 * §1.3 要求「任何會把文本寫進資料庫的新路徑」都要掛異體字落地替換，這支刻意不掛：
 *
 *  1. 它只做**刪除**（括號、數字、空白），不引入任何新文本，沒有一個漢字被寫成新字形——
 *     §1.3 要防的「查重用替換前的值、落庫用替換後的值」在這裡沒有成立的空間。
 *  2. 真掛上 `replaceRow()` 等於順手對 5 萬多列姓名做一次大規模字形改寫，那是**另一個範圍**，
 *     而且 §1.3 同時要求「替換發生了就要讓使用者知道」（`withVariantNotices()`／flash），
 *     migration 裡沒有那個回應通道。
 *  3. 前例 `2026_09_14_000000_normalize_zero_coordinates_in_addr_codes`（同樣是資料清理型
 *     migration）也沒掛；`tests/Unit/VariantReplaceHookCoverageTest.php` 的清冊覆蓋 handler／
 *     controller／repository／import service，不掃 `database/migrations`。
 *
 * ## 刻意不寫 operations／audit_log、不蓋 c_modified_*
 *
 * 比照 `2026_09_14_000000_normalize_zero_coordinates_in_addr_codes`：這是資料清理，不是
 * 五千次編輯行為。逐列寫稽核會用一批無資訊的列淹掉 operations 頁；而把這些列的最後修改者
 * 改成執行 migration 的人，會抹掉「這列上次真的被誰改過」這個更有價值的事實
 * （AGENTS.md §1.2 的語義是「最後一次**實際的**寫入」）。痕跡留在 CHANGELOG.md。
 *
 * ## 為什麼不需要配一支 artisan 指令
 *
 * 座標那支要配指令，是因為座標會隨上游 Access 重灌一再回來。姓名後綴不一樣：**本系統就是
 * 資料端的最上游**，沒有會把 `(n)` 重新灌進來的來源，所以清一次就是永久的。
 *
 * ## 不可逆（`down()` 是 no-op），而這是對的
 *
 * 序號被刪掉就沒了，而且**沒有記錄哪些列被改過**（刻意不寫稽核，見上），所以無法區分
 * 「本來就沒有後綴的 65 萬列」與「被這支 migration 刪掉後綴的 5 千列」。`down()` 只能是
 * 「什麼都不做」，而不是假裝能還原。真要回溯某一列的歷史值，來源是資料庫備份。
 */
return new class () extends Migration {
    /**
     * 要處理的四個姓名欄。
     *
     * 前兩個是拼音欄（後綴前有一個半角空格：`Chen Ji (2)`），後兩個是中文欄（後綴緊貼漢字：
     * `許瑤(2)`）。**兩組共用同一個 pattern**，這裡不分組——pattern 的空白部分是 `*`，
     * 兩種形狀都吃得下，列出來只是說明資料長相。
     */
    private const TARGET_COLUMNS = ['c_name', 'c_mingzi', 'c_name_chn', 'c_mingzi_chn'];

    /**
     * 每批處理幾列。
     *
     * 刻意壓在 999 以下：每批用 `whereIn` 綁定這麼多個佔位符，而 SQLite 3.32 以前的
     * `SQLITE_MAX_VARIABLE_NUMBER` 預設是 999（本機是 3.51、上限 32766，但測試環境的 SQLite
     * 版本不由本 repo 決定）。都是主鍵查詢，批次小一點不影響總成本。
     * 抽成常數也讓測試能鋪出跨批資料驗證分批邏輯（見對應測試的多批案例）。
     */
    private const CHUNK_SIZE = 500;

    /**
     * 尾端「數字消歧後綴」（可重複，見下）。
     *
     *  - `[(（]…[)）]`：半角與全角括號都認。dev 上實測只有半角，但寫入端的
     *    {@see \App\Services\BracketNormalizer} 做的是全角→半角折疊，意味著全角形出現過；
     *    這裡一併吃掉，免得留下兩三列漏網。
     *  - `[0-9０-９]{1,2}`：**只認 1–2 位**數字。實測 `c_name` 1 位 4,895／2 位 290、
     *    `c_mingzi` 1 位 4,850／2 位 288，**3 位以上 0 列**（序號最大值 33），所以這道限制
     *    不影響任何現有資料，純粹是不可逆操作下的防禦。見類註「不碰 3 位以上的數字括號」。
     *    下界**刻意不設**：`(1)` 確實存在（`c_name`／`c_mingzi` 各 1 列），而 `(0)`／`(00)`
     *    實測 0 列——真的出現也一併清掉、視為垃圾值，不值得為此讓 pattern 變複雜。
     *  - 前後的 `[\s\x{3000}]*`：吃掉後綴前的那個空格（需求裡的「最後一個空格也要刪除」）
     *    與後綴後可能殘留的尾隨空白。用 `*` 而非 `?` 是為了 `Chen Ji  (2)` 這種多空格形，
     *    dev 上目前 0 列，但多認不會錯。不斷行空格（U+00A0）形同樣吃得下：`/u` 會一併開
     *    `PCRE2_UCP`，`\s` 已經涵蓋 U+00A0／U+2002／U+3000，所以字元類裡的 `\x{3000}` 其實是
     *    **冗餘**的，留著只是讓意圖顯眼。
     *  - 外層 `(?:…)+`：連續多個後綴（`Wang Anshi (2) (3)`）一次吃乾淨。dev 上 0 列，但若只吃
     *    最後一組，這支 migration 就不是幂等的——跑一次留 `Wang Anshi (2)`、要跑第二次才乾淨。
     *  - `$` 之前不允許任何其他字元：只認字串尾端，理由見類註「不碰字串中段的括號」。
     */
    private const SUFFIX_PATTERN
        = '/(?:[\s\x{3000}]*[(（][\s\x{3000}]*[0-9０-９]{1,2}[\s\x{3000}]*[)）])+[\s\x{3000}]*$/u';

    /** 尾端帶 3 位以上數字括號：不處理，但要數出來請人判斷（見類註）。 */
    private const OUT_OF_RANGE_PATTERN
        = '/[\s\x{3000}]*[(（][\s\x{3000}]*[0-9０-９]{3,}[\s\x{3000}]*[)）][\s\x{3000}]*$/u';

    public function up(): void {
        // 測試環境（SQLite）只建立各測試自己需要的表；表不存在就沒什麼可清。
        if (!Schema::hasTable('BIOG_MAIN')) {
            return;
        }

        $columns = array_values(array_filter(
            self::TARGET_COLUMNS,
            fn (string $column): bool => Schema::hasColumn('BIOG_MAIN', $column)
        ));
        if ($columns === []) {
            return;
        }

        $updatedRows = 0;
        $updatedCells = array_fill_keys($columns, 0);
        $skippedWouldEmpty = [];
        $skippedPregError = [];
        $skippedOutOfRange = [];
        $skippedReindex = [];
        $reindexed = 0;

        // FTS 重建的前置條件在迴圈外備好一次（重建本身在迴圈**內**逐列做，理由見下方註解）。
        $indexService = Schema::hasTable('CBDB__NAME_FTS')
            ? app(NameSearchIndexService::class)
            : null;

        // 候選列用 LIKE 粗篩再由 PHP 精判：SQLite 沒有 REGEXP，MariaDB 與 SQLite 的
        // REGEXP_REPLACE 語法也不同，所以判斷與替換一律在 PHP 做（AGENTS.md §1）。
        // 半角與全角括號兩種 LIKE 都要下：不確定 `BIOG_MAIN` 的 collation 會不會把全角折疊成
        // 半角，而 SQLite 的 LIKE 對非 ASCII 是逐位元比對，只下半角有漏掉全角形的風險。
        //
        // ⚠️ 這裡刻意**先把候選 c_personid 全部 pluck 成快照**，再依主鍵分批取回處理，
        // 而不是用 `chunk()` 邊掃邊改。`chunk()` 是 offset 分頁且每頁重跑查詢
        // （vendor/laravel/framework/.../BuildsQueries.php::chunk()），而這裡 WHERE 篩的欄位
        // 正是要被 UPDATE 的欄位：`Chen Ji (2)` 一被清成 `Chen Ji` 就不再符合 `like '%(%'`、
        // 從結果集消失，於是下一頁的 offset 會往前跳過等量的列——每批漏檢「批次大小 × 9.3%」
        // （候選 55,779 列裡有 9.3% 要清理），全程漏掉約 450 列該清理的資料，而且 echo 出來的
        // 列數也會跟著少報，沒人會發現。用快照則完全不受寫入影響，順帶把「每批一次無索引全表
        // 掃描」（這個規模下約 112 次）降成 1 次，之後每批都是主鍵查詢。
        $candidateIds = DB::table('BIOG_MAIN')
            ->where(function ($query) use ($columns) {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', '%(%')
                        ->orWhere($column, 'like', '%（%');
                }
            })
            ->orderBy('c_personid')
            ->pluck('c_personid')
            ->all();

        foreach (array_chunk($candidateIds, self::CHUNK_SIZE) as $idBatch) {
            $rows = DB::table('BIOG_MAIN')
                ->select(array_merge(['c_personid'], $columns))
                ->whereIn('c_personid', $idBatch)
                ->get();

            foreach ($rows as $row) {
                $changes = [];
                foreach ($columns as $column) {
                    $original = $row->{$column};
                    if (!is_string($original)) {
                        continue;
                    }

                    [$status, $stripped] = self::stripSuffix($original);
                    if ($status === self::STATUS_PREG_FAILED) {
                        $skippedPregError[] = [$row->c_personid, $column, $original];

                        continue;
                    }
                    if ($status === self::STATUS_WOULD_EMPTY) {
                        $skippedWouldEmpty[] = [$row->c_personid, $column, $original];

                        continue;
                    }
                    // 原值與**剝完之後**的值都要判：`Wang (1148) (2)` 的尾端是合法的 1–2 位
                    // 後綴，剝掉它才會讓 `(1148)` 浮到字串尾端。只判原值的話這一列會被改動、
                    // 而殘留的可疑括號卻進不了人工清單，也不會有「下一次 migration」來補報。
                    // 實測 0 列，純防禦。
                    if (preg_match(self::OUT_OF_RANGE_PATTERN, $original) === 1
                        || preg_match(self::OUT_OF_RANGE_PATTERN, $stripped) === 1) {
                        $skippedOutOfRange[] = [$row->c_personid, $column, $original];
                    }
                    if ($stripped !== $original) {
                        $changes[$column] = $stripped;
                    }
                }
                if ($changes === []) {
                    continue;
                }

                // 只有 `c_name_chn` 會進 FTS（`indexPerson()` 只讀這一欄，`c_mingzi_chn`／
                // `c_mingzi`／`c_surname` 都不進索引），所以只在它真的變動時才重建。
                $needsReindex = $indexService !== null && array_key_exists('c_name_chn', $changes);

                // ⚠️ **姓名更新與索引重建必須在同一個交易裡**，而且是**每列一個**。
                // 這支 migration 整體沒有交易保護（Laravel 只在 grammar 支援 schema
                // transaction 時才包，MariaDB 不支援），所以 5,189 個 UPDATE 預設是逐列
                // 各自提交的。若讓 UPDATE 先提交、重建才失敗（FTS schema 不符、連線中斷、
                // insert 失敗），就會留下「名字已清、索引沒清」的列——而 operator 重跑
                // `migrate` 時 `許瑤` 已不含 `(`、**不再是 LIKE 候選**，那筆索引於是永久留著
                // `許瑤2`，輸出還只會說「已從 0 列…」，沒人會知道。
                // 包起來之後，重建失敗會把那一列的姓名一起回滾，重跑就能再處理到它。
                // （`reindexPerson()` 自己也開交易，巢狀時 Laravel 會降級成 savepoint。）
                // 名字本身的剝除是幂等的，只有索引這一步不是，所以綁定的是它。
                DB::transaction(function () use ($row, $changes, $needsReindex, $indexService, &$skippedReindex, &$reindexed) {
                    DB::table('BIOG_MAIN')
                        ->where('c_personid', $row->c_personid)
                        ->update($changes);

                    if (!$needsReindex) {
                        return;
                    }

                    // 刻意重新讀一次 model 而不是自己組 stdClass：`reindexPerson()` 的契約收的
                    // 是 `App\Models\BiogMain`，將來它多讀一個欄位時 stdClass 會靜默給 null。
                    $person = BiogMain::query()->find($row->c_personid);
                    if ($person === null) {
                        // 正常跑不到（PK 剛剛才 UPDATE 成功）。但正是「跑不到」才讓它在真的發生
                        // 時最危險——那正好是「名字改了、索引沒改」，一定要講出來。
                        // 這裡刻意**不拋**：這一列的姓名清理本身是對的，值得保留；漏的只有索引，
                        // 而它已經進了人工清單。拋掉會連帶回滾一個正確的更新。
                        $skippedReindex[] = [
                            $row->c_personid,
                            'c_name_chn',
                            (string) $changes['c_name_chn'],
                        ];

                        return;
                    }

                    $indexService->reindexPerson($person);
                    $reindexed++;
                });

                $updatedRows++;
                foreach (array_keys($changes) as $column) {
                    $updatedCells[$column]++;
                }
            }
        }

        // 讓 `php artisan migrate` 的輸出說出實際發生了什麼——尤其是「跳過了哪幾列」，
        // 那是需要人看的資訊，不該只留在區域變數裡（比照 2026_09_14 那支的 skipped 輸出）。
        if ($updatedRows > 0) {
            echo sprintf(
                '  BIOG_MAIN：已從 %d 列的姓名欄刪除數字消歧後綴（%s）。'.PHP_EOL,
                $updatedRows,
                implode('、', array_map(
                    fn (string $column): string => $column.' '.$updatedCells[$column].' 列',
                    $columns
                ))
            );
        }
        if ($reindexed > 0) {
            echo sprintf('  CBDB__NAME_FTS：已重建 %d 人的本名索引。'.PHP_EOL, $reindexed);
        }
        self::report($skippedOutOfRange, '括號內是 3 位以上數字（可能是年份而非序號），已跳過，請人工確認');
        self::report($skippedWouldEmpty, '整格只有一個後綴，刪掉會變成沒有名字，已跳過，請人工確認');
        self::report($skippedPregError, '不是合法的 UTF-8（或觸發 preg 限制），無從判斷，已跳過，請人工確認');
        self::report($skippedReindex, '姓名已更新但重新取列失敗，FTS 索引未重建，請人工確認');
    }

    public function down(): void {
        // 刻意不做任何事，理由見類註：序號已經沒了，而且沒有記錄哪些列被改過，
        // 無法區分「本來就沒有後綴」與「被這支 migration 刪掉後綴」。
    }

    /** {@see report()} 最多逐列印幾筆，超過只報總數。 */
    private const REPORT_LIMIT = 50;

    /** {@see stripSuffix()} 的狀態：正常（值可能有改、也可能原封不動）。 */
    private const STATUS_OK = 'ok';

    /** {@see stripSuffix()} 的狀態：`preg_replace` 失敗，這一格不能碰。 */
    private const STATUS_PREG_FAILED = 'preg_failed';

    /** {@see stripSuffix()} 的狀態：刪掉後綴會變成空字串，這一格不能碰。 */
    private const STATUS_WOULD_EMPTY = 'would_empty';

    /**
     * 刪除尾端的數字消歧後綴。
     *
     * 回傳 `[狀態, 值]`。狀態與值**分開兩個位置**是刻意的：用哨兵字串（或 `null`）表達狀態，
     * 會在「某一格的值剛好等於哨兵」時把狀態與資料混為一談，而 `BIOG_MAIN` 的姓名欄是自由文本、
     * 存得下任何位元組。兩種「刻意不改這一格」的情形也必須彼此可分，因為人工處置方式不同：
     *
     *  - {@see STATUS_PREG_FAILED}：`preg_replace` 回傳 `null`。在 `/u` 之下**任何非 UTF-8
     *    位元組**都會這樣（`PREG_BAD_UTF8_ERROR`），主檔有 latin1 時代的歷史，不能假設不會發生。
     *  - {@see STATUS_WOULD_EMPTY}：整格就是一個後綴（`"(2)"`、`" (2) "`）。把姓名清成空字串
     *    會製造一筆比後綴更糟的資料——一個沒有名字的人。dev 上實測 0 列。
     *
     * 這兩種情形的第二個元素一律是原值，呼叫端不會拿它去寫庫。
     *
     * @return array{0: string, 1: string}  [狀態常數, 要寫回的值（沒有後綴時就是原值）]
     */
    private static function stripSuffix(string $value): array {
        $stripped = preg_replace(self::SUFFIX_PATTERN, '', $value);
        if ($stripped === null) {
            return [self::STATUS_PREG_FAILED, $value];
        }
        if ($stripped === $value) {
            return [self::STATUS_OK, $value];
        }
        if (trim($stripped) === '') {
            return [self::STATUS_WOULD_EMPTY, $value];
        }

        return [self::STATUS_OK, $stripped];
    }

    /**
     * 逐列印出被跳過的格子。
     *
     * @param  array<int, array{0: int|string, 1: string, 2: string}>  $rows  [c_personid, 欄名, 原值]
     */
    private static function report(array $rows, string $reason): void {
        foreach (array_slice($rows, 0, self::REPORT_LIMIT) as [$personId, $column, $original]) {
            echo sprintf(
                '  BIOG_MAIN：c_personid=%s 的 %s（%s）%s。'.PHP_EOL,
                (string) $personId,
                $column,
                $original,
                $reason
            );
        }
        // 真實庫這四類都是 0 列，但若上游某次重灌造出上萬列，逐列印會把 migrate 的輸出淹掉。
        if (count($rows) > self::REPORT_LIMIT) {
            echo sprintf(
                '  BIOG_MAIN：另有 %d 格同樣「%s」，未逐列列出。'.PHP_EOL,
                count($rows) - self::REPORT_LIMIT,
                $reason
            );
        }
    }
};
