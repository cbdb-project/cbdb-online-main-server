/**
 * 漢語拼音 v → ü 正規化（前端 Tier 2 偵測工具）。
 *
 * ⚠️ 規則必須與後端 `app/Support/PinyinUmlaut.php` **位元一致**：
 *   - 同一正則：`l/n` 之後、其後非 a/i/o/u 的 `v`（`e` 不在排除集，故 lve/nve 亦命中）。
 *   - 大小寫**只由被匹配的 `v`（group 2）決定**、聲母（group 1）原樣保留：`Lv→Lü`、`LV→LÜ`、`lV→lÜ`。
 *   - 對已是 `ü` 或不含 `l/n+v` 的字串為 no-op（冪等）。
 * 標準樣本與後端測試共用（見 tests/Unit/PinyinUmlautTest.php 與本檔 pinyinUmlaut.test.ts）。
 *
 * 用途：`c_alt_name` 這類**可能含西文別名**（如 Denver/Silver，nve/lve 為真實西文拼法）的欄位，
 * 於保存前偵測是否命中規則；命中才彈窗由使用者決定要不要轉，而非靜默轉。設計見
 * docs/PINYIN_SAVE_NORMALIZE_DESIGN.md §3／§4.2。
 */

/** l/n 之後、其後非 a/i/o/u 的 v（保留大小寫）。`/g` 為全域替換所需；規則與 lookahead 皆純 ASCII。 */
const PATTERN = /([LlNn])([Vv])(?![aiouAIOU])/g;

/** 由被匹配的 v 決定 ü 大小寫；聲母原樣保留。與後端 PinyinUmlaut.php:34 的替換契約一致。 */
function replaceUmlaut(_match: string, consonant: string, v: string): string {
    return consonant + (v === 'V' ? 'Ü' : 'ü');
}

/** 一處命中：原字（如 `Lv`）、轉換後（如 `Lü`）、於原字串中的起始索引。 */
export interface UmlautConversion {
    from: string;
    to: string;
    index: number;
}

/**
 * 掃描字串，回傳所有依規則會被轉換的位置（不改動原字串）。
 * 無命中回傳空陣列——呼叫端據此決定「直接提交」或「彈窗確認」。
 */
export function detectUmlautConversions(value: string | null | undefined): UmlautConversion[] {
    if (!value) {
        return [];
    }
    const out: UmlautConversion[] = [];
    // 每次呼叫用新的 regex 實例，避免 lastState 殘留。
    const re = new RegExp(PATTERN.source, 'g');
    let m: RegExpExecArray | null;
    while ((m = re.exec(value)) !== null) {
        const from = m[0];
        const to = replaceUmlaut(m[0], m[1], m[2]);
        out.push({ from, to, index: m.index });
    }
    return out;
}

/** 將字串中作為 ü 代寫的 v 依規則全部轉為 ü。null/空字串原樣返回。 */
export function applyUmlaut(value: string | null | undefined): string {
    if (!value) {
        return value ?? '';
    }
    return value.replace(new RegExp(PATTERN.source, 'g'), replaceUmlaut);
}

/** 一個 Tier 2 欄位的命中：欄名、命中清單、整欄轉換後的值。 */
export interface Tier2UmlautHit {
    field: string;
    conversions: UmlautConversion[];
    converted: string;
}

/**
 * 對一組（Tier 2）欄位掃描，回傳「規則有命中」的欄位清單（供通用 /codes 編輯器彈窗）。
 * 無命中的欄位不列入；全無命中回傳空陣列（呼叫端據此決定直接提交或彈窗）。
 */
export function collectUmlautConversions(
    fields: string[],
    data: Record<string, string | null | undefined>,
): Tier2UmlautHit[] {
    const out: Tier2UmlautHit[] = [];
    for (const field of fields) {
        const value = data[field];
        const conversions = detectUmlautConversions(value);
        if (conversions.length > 0) {
            out.push({ field, conversions, converted: applyUmlaut(value) });
        }
    }

    return out;
}
