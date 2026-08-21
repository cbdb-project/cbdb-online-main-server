import { describe, expect, it } from 'vitest';
import { classifyLogFieldRendering } from './nlLogMarkdownFields';

describe('classifyLogFieldRendering', () => {
    it('answer_markdown 以區塊模式渲染', () => {
        expect(classifyLogFieldRendering('answer_markdown', true)).toBe('block-markdown');
        expect(classifyLogFieldRendering('answer_markdown', false)).toBe('block-markdown');
    });

    it('散文欄位以行內模式渲染', () => {
        for (const key of ['summary', 'caveat', 'explanation']) {
            expect(classifyLogFieldRendering(key, true)).toBe('inline-markdown');
        }
    });

    it('SQL 相關欄位一律原樣顯示', () => {
        for (const key of ['sql', 'sql_used', 'generated_sql', 'arguments', 'query']) {
            expect(classifyLogFieldRendering(key, true)).toBe('plain');
        }
    });

    it('陣列索引鍵（#0）落在預設分支，不會因為父層是白名單 key 而被渲染', () => {
        expect(classifyLogFieldRendering('#0', true)).toBe('plain');
        expect(classifyLogFieldRendering('#12', true)).toBe('plain');
    });

    it('label／detail 刻意不在白名單：裸 key 比對會誤中 tool_results 的原始資料庫值', () => {
        expect(classifyLogFieldRendering('label', true)).toBe('plain');
        expect(classifyLogFieldRendering('detail', true)).toBe('plain');
    });

    it('未知 key 一律原樣顯示（預設分支才是安全的那一邊）', () => {
        expect(classifyLogFieldRendering('content', true)).toBe('plain');
        expect(classifyLogFieldRendering('', true)).toBe('plain');
    });

    it('頂層無 key 的內容：QA 模式當成 Markdown 回答，NL→SQL 模式維持原樣（可能是裸 SQL）', () => {
        expect(classifyLogFieldRendering(undefined, true)).toBe('block-markdown');
        expect(classifyLogFieldRendering(undefined, false)).toBe('plain');
    });
});
