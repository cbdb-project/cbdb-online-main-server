/**
 * NL 查詢日誌（/app/query-playground/nl-query-logs）在展開 `llm_response` 時，要決定每個字串
 * 值該以 Markdown 渲染還是原樣顯示。這裡抽成純函式是因為判斷條件很容易寫反：預設分支
 * （原樣顯示）才是安全的那一邊，任何「多渲染一點」的改動都可能默默打壞 SQL。
 */
export type LogTextRendering = 'block-markdown' | 'inline-markdown' | 'plain';

/**
 * LLM 依 system prompt 以 Markdown 撰寫的欄位（見 NaturalLanguageQueryService 的 QA prompt）。
 * 只有這幾個 key 會被渲染，其餘一律原樣顯示。
 */
const BLOCK_MARKDOWN_KEYS = new Set(['answer_markdown']);
const INLINE_MARKDOWN_KEYS = new Set(['summary', 'caveat', 'explanation']);

/**
 * @param fieldKey 該字串在 JSON 結構中的所屬 key；`undefined` 代表它是整個回應的頂層內容
 *                 （`choices[0].message.content` 解析不出 JSON 時的原文）。
 * @param isQa     該筆日誌是否為 QA 模式（後端依 `question` 的 `[QA] ` 前綴判定）。
 */
export function classifyLogFieldRendering(fieldKey: string | undefined, isQa: boolean): LogTextRendering {
    if (fieldKey === undefined) {
        // 沒有 key 就沒有語義可依據。QA 模式解析失敗時，parseQaResponse 會把原文整段當成
        // answer_markdown，所以那串確實是 Markdown 回答；NL→SQL 模式的同一條路徑則可能是
        // 模型直接吐出的 SQL，一旦以 Markdown 渲染，單獨一行 `---` 會把前一行變成標題、
        // 成對的 `*` 會變斜體，故只在 QA 模式渲染。
        return isQa ? 'block-markdown' : 'plain';
    }
    if (BLOCK_MARKDOWN_KEYS.has(fieldKey)) {
        return 'block-markdown';
    }
    if (INLINE_MARKDOWN_KEYS.has(fieldKey)) {
        return 'inline-markdown';
    }

    return 'plain';
}
