// @vitest-environment jsdom

import { describe, expect, it } from 'vitest';
import { inlineMarkdownToSafeHtml, markdownToSafeHtml, sanitizeHtml } from './markdown';

describe('markdownToSafeHtml', () => {
    it('空值與空白字串回傳空字串，讓呼叫端可以整塊不渲染', () => {
        expect(markdownToSafeHtml('')).toBe('');
        expect(markdownToSafeHtml('   \n  ')).toBe('');
        expect(markdownToSafeHtml(null)).toBe('');
        expect(markdownToSafeHtml(undefined)).toBe('');
    });

    it('渲染標題而非留下字面 #', () => {
        const html = markdownToSafeHtml('## 李白');
        expect(html).toContain('<h2>李白</h2>');
        expect(html).not.toContain('## ');
    });

    it('渲染有序清單（舊版正則渲染器會原樣輸出 "1. "）', () => {
        const html = markdownToSafeHtml('1. 生年：701\n2. 卒年：762');
        expect(html).toContain('<ol>');
        expect(html).toContain('<li>生年：701</li>');
        expect(html).not.toContain('1. 生年');
    });

    it('渲染巢狀清單而不讓子項漏到 <ul> 外面', () => {
        const html = markdownToSafeHtml('- 別名\n    - 太白\n    - 青蓮居士\n- 入仕方式：待考');
        expect(html).toMatch(/<li>[\s\S]*<ul>[\s\S]*<li>太白<\/li>/);
        expect(html).not.toContain('- 太白');
    });

    it('渲染 GFM 表格', () => {
        const html = markdownToSafeHtml('| 欄位 | 值 |\n|---|---|\n| c_personid | 12345 |');
        expect(html).toContain('<table>');
        expect(html).toContain('<th>欄位</th>');
        expect(html).toContain('<td>12345</td>');
    });

    it('程式碼區塊保留原始換行，不會被 <br> 或段落標籤切開', () => {
        const html = markdownToSafeHtml('```sql\nSELECT *\nFROM BIOG_MAIN\n\nWHERE c_personid = 1\n```');
        const codeBlock = html.slice(html.indexOf('<pre>'), html.indexOf('</pre>'));
        expect(codeBlock).toContain('SELECT *\nFROM BIOG_MAIN');
        expect(codeBlock).not.toContain('<br');
        expect(codeBlock).not.toContain('<p>');
    });

    it('段落內單一換行轉成 <br>（沿用舊行為）', () => {
        expect(markdownToSafeHtml('第一行\n第二行')).toContain('<br>');
    });

    it('GFM 任務清單以符號區分已完成／未完成（<input> 不在白名單內會被整個拿掉）', () => {
        const html = markdownToSafeHtml('- [x] 已完成\n- [ ] 未完成');
        expect(html).not.toContain('<input');
        expect(html).toContain('\u2611');
        expect(html).toContain('\u2610');
    });

    it('GFM 表格對齊語法輸出 align 屬性，供 CSS 以 :not([align]) 讓位', () => {
        const html = markdownToSafeHtml('| a | b |\n|:-:|--:|\n| 1 | 2 |');
        expect(html).toContain('align="center"');
        expect(html).toContain('align="right"');
    });

    it('區塊元素不會被包進 <p> 裡', () => {
        const html = markdownToSafeHtml('## 標題\n\n| a | b |\n|---|---|\n| 1 | 2 |');
        expect(html).not.toContain('<p><h2>');
        expect(html).not.toContain('<p><table>');
    });
});

describe('markdownToSafeHtml 的安全防線', () => {
    it('原始 HTML 標籤逸出為文字，不會成形', () => {
        const html = markdownToSafeHtml('<img src=x onerror="alert(1)">');
        // 整段以文字呈現：標籤沒有成形，屬性也就不是活的（只是逸出後的字元）。
        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;img');
        expect(html).toContain('onerror=&quot;alert(1)&quot;');
    });

    it('script 標籤不會被輸出', () => {
        const html = markdownToSafeHtml('正常內容\n\n<script>alert(1)</script>');
        expect(html).not.toContain('<script');
        expect(html).toContain('正常內容');
    });

    it('javascript: 連結被移除，只留下文字', () => {
        const html = markdownToSafeHtml('[點我](javascript:alert(1))');
        expect(html).not.toContain('javascript:');
        expect(html).not.toContain('<a ');
        expect(html).toContain('點我');
    });

    it('data: 連結被移除，只留下文字', () => {
        const html = markdownToSafeHtml('[點我](data:text/html;base64,PHNjcmlwdD4=)');
        expect(html).not.toContain('data:text/html');
        expect(html).not.toContain('<a ');
    });

    it('http(s) 連結保留並加上 noopener 外開屬性', () => {
        const html = markdownToSafeHtml('[CBDB](https://cbdb.fas.harvard.edu)');
        expect(html).toContain('href="https://cbdb.fas.harvard.edu"');
        expect(html).toContain('target="_blank"');
        expect(html).toContain('rel="noopener noreferrer nofollow"');
    });

    it('站內相對連結保留', () => {
        expect(markdownToSafeHtml('[人物](/app/basicinformation)')).toContain('href="/app/basicinformation"');
    });

    it('Markdown 圖片語法退化為替代文字，不對外送出請求', () => {
        const html = markdownToSafeHtml('![替代文字](https://evil.example/track.png)');
        expect(html).not.toContain('<img');
        expect(html).not.toContain('evil.example');
        expect(html).toContain('替代文字');
    });

    it('純文字中的角括號被逸出而非吃掉', () => {
        expect(markdownToSafeHtml('條件為 a < b 且 b > c')).toContain('a &lt; b');
    });
});

describe('inlineMarkdownToSafeHtml', () => {
    it('解析行內語法但不產生區塊元素', () => {
        const html = inlineMarkdownToSafeHtml('查詢 **BIOG_MAIN** 的 `c_personid` 欄位');
        expect(html).toContain('<strong>BIOG_MAIN</strong>');
        expect(html).toContain('<code>c_personid</code>');
        expect(html).not.toContain('<p>');
    });

    it('空值回傳空字串', () => {
        expect(inlineMarkdownToSafeHtml(null)).toBe('');
        expect(inlineMarkdownToSafeHtml('  ')).toBe('');
    });

    it('同樣擋掉危險連結與原始 HTML', () => {
        expect(inlineMarkdownToSafeHtml('[x](javascript:alert(1))')).not.toContain('javascript:');
        const html = inlineMarkdownToSafeHtml('<b onclick="x()">粗</b>');
        expect(html).not.toContain('<b');
        expect(html).toContain('&lt;b onclick=&quot;x()&quot;&gt;');
    });
});

/**
 * 這組刻意直接餵 HTML 給 sanitizeHtml，不經過 marked。marked 的 renderer 覆寫（第一道防線）
 * 已足以讓上面的安全測試全綠，若只從 markdownToSafeHtml 間接驗證，白名單這層整個拿掉也不會
 * 有測試變紅——那就等於沒有第二道防線。
 */
describe('sanitizeHtml（第二道防線，繞過 marked 直接驗證）', () => {
    it('移除 script 與其內容', () => {
        expect(sanitizeHtml('<p>ok</p><script>alert(1)</script>')).toBe('<p>ok</p>');
    });

    it('移除事件處理屬性', () => {
        const html = sanitizeHtml('<p onclick="alert(1)">文字</p>');
        expect(html).not.toContain('onclick');
        expect(html).toContain('文字');
    });

    it('移除 style 屬性（Tailwind 無 CSP，樣式即版面覆蓋原語）', () => {
        expect(sanitizeHtml('<p style="position:fixed">x</p>')).not.toContain('style');
    });

    it('移除 class 屬性', () => {
        expect(sanitizeHtml('<p class="fixed inset-0 z-50">x</p>')).not.toContain('class');
    });

    it('移除不在白名單的標籤（img／iframe／input／form）', () => {
        expect(sanitizeHtml('<img src="x">')).not.toContain('<img');
        expect(sanitizeHtml('<iframe src="https://e.example"></iframe>')).not.toContain('<iframe');
        expect(sanitizeHtml('<input type="checkbox">')).not.toContain('<input');
        expect(sanitizeHtml('<form action="/x"><button>go</button></form>')).not.toContain('<form');
    });

    it('擋掉 javascript: 與其空白／實體混淆寫法', () => {
        expect(sanitizeHtml('<a href="javascript:alert(1)">x</a>')).not.toContain('javascript:');
        expect(sanitizeHtml('<a href="jav&#9;ascript:alert(1)">x</a>')).not.toContain('javascript:');
        expect(sanitizeHtml('<a href="JaVaScRiPt:alert(1)">x</a>')).not.toMatch(/javascript:/i);
        expect(sanitizeHtml('<a href="vbscript:msgbox(1)">x</a>')).not.toContain('vbscript:');
        expect(sanitizeHtml('<a href="data:text/html,<b>x</b>">x</a>')).not.toContain('data:text/html');
    });

    it('帶 target 的連結一律補上 rel，避免 reverse tabnabbing', () => {
        const html = sanitizeHtml('<a href="https://e.example" target="_blank">x</a>');
        expect(html).toContain('rel="noopener noreferrer nofollow"');
    });

    it('target 收斂為 _blank，不放行 _top 之類的框架目標', () => {
        const html = sanitizeHtml('<a href="/ok" target="_top">x</a>');
        expect(html).toContain('target="_blank"');
        expect(html).not.toContain('_top');
    });

    it('協定相對網址的 href 被移除（白名單層單獨運作時也不漏）', () => {
        expect(sanitizeHtml('<a href="//evil.example/path">x</a>')).not.toContain('evil.example');
        expect(sanitizeHtml('<a href=" //evil.example/path">x</a>')).not.toContain('evil.example');
    });

    it('保留白名單內的標籤與 GFM 表格對齊屬性', () => {
        expect(sanitizeHtml('<strong>粗</strong>')).toBe('<strong>粗</strong>');
        // th 必須包在完整 table 內，否則 HTML 解析器會直接丟掉這個標籤（與 sanitize 無關）。
        expect(sanitizeHtml('<table><tr><th align="right">x</th></tr></table>')).toContain('align="right"');
    });

    it('擋掉 mXSS 常見的 mathml/svg 命名空間穿越', () => {
        const html = sanitizeHtml('<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>');
        expect(html).not.toContain('<img');
        expect(html).not.toContain('onerror');
    });
});
