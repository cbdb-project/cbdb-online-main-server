import DOMPurify from 'dompurify';
import { Marked } from 'marked';

/**
 * LLM（Query Playground 的 NL 查詢／歷史問答）產出的內容一律是 Markdown。此模組是全站
 * 唯一的「Markdown → 可安全注入的 HTML」轉換入口，取代先前散落在元件內、以正則硬湊的
 * 簡易渲染器（有序清單、連結、巢狀清單皆無法處理，且會把 `</p><p>` 插進 ``` 程式碼區塊裡）。
 *
 * 安全前提：內容來自 LLM，等同不可信輸入（提示注入可誘導模型吐出 <img onerror=...>）。
 * 因此採兩道防線，兩道都必須獨立成立：
 *   1. marked 關閉任何原始 HTML 透傳（見 createMarked 的 html renderer），Markdown 語法
 *      以外的標籤直接當文字。
 *   2. sanitizeHtml() 以白名單再過濾一次，並限制連結協定與 target/rel。
 * 第 2 道刻意不依賴第 1 道：即使日後 marked 升版或 renderer 被改壞，白名單仍應擋下攻擊，
 * 故測試需分別覆蓋兩層（見 markdown.test.ts）。
 *
 * 註：DOMPurify 需要 window。本專案未啟用 Inertia SSR（config/inertia.php 的 ssr.enabled
 * 為 false），故僅在瀏覽器執行。若日後啟用 SSR，此處會直接拋錯而非靜默放行未過濾的 HTML
 * ——這是刻意的失敗方向，但屆時需改為 isomorphic 的 sanitize 方案。
 */

/** 允許輸出的標籤：涵蓋 GFM 常用區塊與行內語法，刻意不含 img／iframe／form／input 等可外連或互動的元素。 */
const ALLOWED_TAGS = [
    'p', 'br', 'hr',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'strong', 'em', 'del', 'code', 'pre', 'blockquote',
    'ul', 'ol', 'li',
    'table', 'thead', 'tbody', 'tr', 'th', 'td',
    'a',
];

/**
 * `align` 供 GFM 表格對齊（marked 會輸出 <th align="right">）；`target`／`rel` 由下方的
 * afterSanitizeAttributes hook 強制成對出現。刻意不放行 `class`／`style`：Markdown 語法
 * 產不出有意義的 class，而本站是 Tailwind 且無 CSP，放行等於送出可覆蓋整頁的排版原語。
 */
const ALLOWED_ATTR = ['href', 'title', 'align', 'target', 'rel'];

/** 連結只放行這幾種協定；其餘（javascript:、data:、vbscript: 等）在 sanitize 階段被移除。 */
const SAFE_LINK_PROTOCOLS = ['http:', 'https:', 'mailto:'];

const EXTERNAL_LINK_REL = 'noopener noreferrer nofollow';

function isSafeHref(href: string): boolean {
    const value = href.trim();
    // `//host/path`（協定相對）與 `/\host` 都會被瀏覽器當成外部連結，不能歸類為站內相對路徑。
    if (value.startsWith('//') || value.startsWith('/\\')) {
        return false;
    }
    try {
        // base 只是為了讓相對 URL 也能解析；有協定的絕對 URL 會忽略 base。
        // WHATWG URL 會先剝除 tab／換行並小寫化協定，故 `java&#9;script:`、`JaVaScRiPt:` 等
        // 混淆寫法在此一併被判為不安全。
        return SAFE_LINK_PROTOCOLS.includes(new URL(value, 'https://cbdb.invalid/').protocol);
    } catch {
        return false;
    }
}

/**
 * marked 自 v5 起移除內建 sanitize，改由 renderer 覆寫關閉原始 HTML：
 * 區塊級（Tokens.HTML）與行內（Tokens.Tag）的 raw HTML 都會走 html()，一律逸出成純文字，
 * 避免任何標籤在 sanitize 之前就先成形。
 */
function createMarked(): Marked {
    const instance = new Marked({
        gfm: true,
        // LLM 回答常在同一段落內用單一換行斷句（舊渲染器亦是 \n → <br/>），保持一致。
        breaks: true,
    });

    instance.use({
        renderer: {
            html(token) {
                return escapeHtml(token.text);
            },
            link(token) {
                const text = this.parser.parseInline(token.tokens);
                if (!isSafeHref(token.href)) {
                    return text;
                }
                const title = token.title ? ` title="${escapeHtml(token.title)}"` : '';

                return `<a href="${escapeHref(token.href)}"${title} target="_blank" rel="${EXTERNAL_LINK_REL}">${text}</a>`;
            },
            // 圖片一律不外連（會對第三方主機發出請求並洩漏瀏覽行為），退化為替代文字；
            // 沒有替代文字時輸出空字串，不要把追蹤網址當成內文顯示出來。
            image(token) {
                return escapeHtml(token.text || token.title || '');
            },
            // GFM 任務清單：<input type="checkbox"> 不在白名單內會被整個拿掉，導致「已完成」
            // 與「未完成」看起來一模一樣，故改以符號呈現。
            checkbox({ checked }) {
                return checked ? '☑ ' : '☐ ';
            },
        },
    });

    return instance;
}

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * href 專用逸出：刻意不動 `&`。網址中的 `&` 是合法的查詢字串分隔符，若比照內文再逸出一次，
 * 原本就含實體的網址會變成 `?a=1&amp;amp;b=2` 而連到錯的位址。
 */
function escapeHref(value: string): string {
    return value
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const marked = createMarked();

/**
 * 只要節點帶 target 就強制配上安全的 rel，並收斂成 _blank。
 * 第 1 道防線的 link renderer 本來就會寫上 rel，此處是為了讓白名單這層即使單獨運作也成立
 * （避免 target="_blank" 造成的 reverse tabnabbing）。
 */
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
    if (!(node instanceof Element)) {
        return;
    }
    if (node.hasAttribute('target')) {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', EXTERNAL_LINK_REL);
    }
    // ALLOWED_URI_REGEXP 會把 `//host/path` 當成相對網址放行，但瀏覽器視之為外部連結。
    // link renderer 已先擋過一次，這裡補上讓白名單這層單獨運作時也不會漏。
    const href = node.getAttribute('href');
    // 先移除瀏覽器會忽略的空白字元，再判斷是否為協定相對網址。
    if (href !== null && /^[/]{2}/.test(href.replace(/\s/g, ''))) {
        node.removeAttribute('href');
    }
});

/**
 * 第二道防線：白名單過濾。獨立匯出以便單獨測試（不能只透過 markdownToSafeHtml 間接驗證，
 * 否則第一道防線就足以讓測試全綠，這層壞掉不會被發現）。
 */
export function sanitizeHtml(html: string): string {
    return DOMPurify.sanitize(html, {
        ALLOWED_TAGS,
        ALLOWED_ATTR,
        // 只保留 http/https/mailto 與相對連結；DOMPurify 會先剝除屬性中的空白字元再比對，
        // 故 `jav&#9;ascript:` 這類混淆同樣擋得掉。
        ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i,
    });
}

/**
 * 區塊級 Markdown → 安全 HTML（標題、清單、表格、程式碼區塊等）。
 * 傳入非字串或空字串時回傳空字串，呼叫端可據此決定是否渲染容器。
 */
export function markdownToSafeHtml(source: string | null | undefined): string {
    if (typeof source !== 'string' || source.trim() === '') {
        return '';
    }

    return sanitizeHtml(marked.parse(source, { async: false }));
}

/**
 * 行內 Markdown → 安全 HTML：只解析粗體／斜體／行內程式碼／連結等行內語法，不產生
 * <p>／<ul>／<table> 等區塊元素。適用於 caveat、SQL explanation 這類要嵌在既有句子或
 * 單行容器裡的短文字。
 */
export function inlineMarkdownToSafeHtml(source: string | null | undefined): string {
    if (typeof source !== 'string' || source.trim() === '') {
        return '';
    }

    return sanitizeHtml(marked.parseInline(source, { async: false }));
}
