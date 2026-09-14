/**
 * 匯入結果的「複製 textid 與書名」剪貼簿內容。
 *
 * ── 2026-09-15（Blade 下架環節 4b-3）─────────────────────────────
 * 這段原本由 legacy Blade 在伺服器端預先拼好並塞進隱藏 textarea，所以
 * `AdminBatchLoadBookTitlesTest::test_results_page_renders_copy_button_with_payload`
 * 可以直接 `assertSee("801\t某某書")`——那條斷言釘的是**格式契約**：
 * 「textid、TAB、書名，逐列換行」。使用者把它貼進 Excel 靠的就是這個格式。
 *
 * 移植到 React 之後拼接變成前端的事，伺服器端的測試只驗得到「原料」（`c_textid`／`title`
 * 有傳下去）⇒ 有人把兩欄對調、TAB 換成逗號、或改成 `join(', ')`，**沒有任何測試會紅**
 * （review 指出）。所以抽成純函式並補 vitest，與環節 4b-2c-2 的
 * `Pages/Codes/showNavigation.ts` 同一個處置。
 */

export interface CopyableRow {
    c_textid: number | string;
    title: string;
}

/** 逐列 `textid<TAB>書名`，以換行串接。 */
export function buildCopyPayload(rows: CopyableRow[]): string {
    return rows.map((r) => `${r.c_textid}\t${r.title}`).join('\n');
}
