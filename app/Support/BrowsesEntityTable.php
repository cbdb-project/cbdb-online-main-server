<?php

namespace App\Support;

/**
 * 實體聚合列表頁的瀏覽描述子契約（docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5）。
 *
 * 存在的理由是**可被機械化檢查**，不是為了多型：描述子的 `columns` 是一份手寫欄位清單，
 * 與資料表實際欄位之間沒有任何保證，而 EntityTableBrowser 的關鍵字搜尋會對其中每一欄
 * 下 LIKE、排序／篩選也以它為白名單 ⇒ 列了不存在的欄，使用者按「搜尋」拿到的是 500
 * 而不是空結果。把描述子從 appIndex() 內的字面陣列提出來，
 * tests/Feature/EntityBrowseColumnsSchemaDriftTest.php 才能逐一與 migration schema 比對。
 *
 * `EntityTableBrowser::payload()` 收的是**本介面的實作物件**而不是描述子陣列，
 * 所以「就地寫死一份字面陣列、繞過 browseDescriptor()」在型別層面就不可能——
 * 少了這一層，描述子可以乾乾淨淨、守衛可以全綠，而生產環境照樣 500。
 * 型別擋不住的那半邊（改傳另一個實作，例如匿名類別）由守衛的
 * `every_entity_controller_passes_itself_to_the_browser()` 把關：它用 PHP tokenizer 逐一
 * 檢查清冊上 controller 的每一處 `payload()`／`?->payload()` 呼叫，語義上全都要是
 * `payload($request, $this)`（具名引數與尾逗號算合格，參數展開與 first-class callable 不算），
 * 並禁止動態方法分派（`->$m()`／`->{…}()`／`call_user_func`）——那會讓方法名不再是字面
 * token、字面比對就看不到那次呼叫。
 * 注意這是**防無意漂移**的守衛，不是安全邊界：它擋的是「改著改著就繞過去了」，不是「存心要繞的人」。存心規避的寫法永遠列舉不完，所以清冊上這三個檔案改動時，code review 仍要看一眼描述子有沒有真的被用上。
 *
 * 新增實體列表頁時：**凡是原始碼碰到 EntityTableBrowser 的類別**一律 implement 此介面
 * （守衛以原始碼掃描判定，涵蓋子命名空間、方法注入與 app() 解析；漏了會紅），
 * 並讓 appIndex() 走 browseDescriptor()，同時把該 controller 補進守衛的
 * EXPECTED_CONTROLLERS 清冊。
 */
interface BrowsesEntityTable {
    /**
     * @return array{table: string, columns: array<int, string>, computed: array<string, array{expression: string, match_mode: string}>, key_column: string, search_expressions?: array<int, string>}
     */
    public function browseDescriptor(): array;
}
