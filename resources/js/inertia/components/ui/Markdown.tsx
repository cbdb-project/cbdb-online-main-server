import { useMemo, type CSSProperties } from 'react';
import { cn } from '../../lib/utils';
import { inlineMarkdownToSafeHtml, markdownToSafeHtml } from '../../utils/markdown';

interface MarkdownProps {
    /** Markdown 原文（通常為 LLM 產出）。空字串／null 時不渲染任何節點。 */
    source: string | null | undefined;
    /** true 時只解析行內語法（粗體、行內程式碼、連結），不產生段落等區塊元素。 */
    inline?: boolean;
    className?: string;
    /** 供尚未 Tailwind 化的內聯樣式呼叫端（如 QueryPlayground 面板）指定字級等外觀。 */
    style?: CSSProperties;
}

/**
 * 全站唯一的 Markdown 顯示元件。內容經 utils/markdown 逸出並 sanitize 後才注入，
 * 排版樣式集中在 resources/css/inertia.css 的 `.cbdb-markdown`（含深色模式）。
 */
export function Markdown({ source, inline = false, className, style }: MarkdownProps) {
    const html = useMemo(
        () => (inline ? inlineMarkdownToSafeHtml(source) : markdownToSafeHtml(source)),
        [source, inline],
    );

    if (html === '') {
        return null;
    }

    const Tag = inline ? 'span' : 'div';

    return (
        <Tag
            className={cn('cbdb-markdown', inline && 'cbdb-markdown-inline', className)}
            style={style}
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
