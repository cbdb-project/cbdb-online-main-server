// @vitest-environment jsdom

import React from 'react';
import { act } from 'react';
import { createRoot, Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// SaveSplitButton 透過 useTranslation('common') 取箭頭鈕 aria-label 與選單說明；測試不起 Inertia，直接假造 usePage。
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            translations: {
                common: { saving: '儲存中…', more_submit_options: '更多送出方式', proposal_menu_hint: '需經審核後才會套用' },
            },
        },
    }),
}));

import { SaveSplitButton, SaveSplitButtonProps } from './SaveSplitButton';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

const labels = { saveDirect: '直接保存', submitProposal: '提交提案', resubmitProposal: '更新提案' };

let container: HTMLDivElement;
let root: Root;

function render(props: Partial<SaveSplitButtonProps> = {}) {
    const merged: SaveSplitButtonProps = {
        directAvailable: true,
        proposalAvailable: true,
        onSubmitProposal: () => {},
        labels,
        ...props,
    };
    act(() => { root.render(<SaveSplitButton {...merged} />); });
}

const q = <T extends HTMLElement = HTMLElement>(sel: string) => container.querySelector<T>(sel);
const click = (el: Element | null) => act(() => { el!.dispatchEvent(new MouseEvent('click', { bubbles: true })); });
const keydown = (el: Element | null, key: string) => act(() => {
    el!.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
});

beforeEach(() => {
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
});
afterEach(() => {
    act(() => { root.unmount(); });
    container.remove();
});

describe('SaveSplitButton', () => {
    it('可直接寫入且可提案：主按鈕直接保存、箭頭展開選單才有提案，點選單項送提案且選單關閉', () => {
        const onSaveDirect = vi.fn();
        const onSubmitProposal = vi.fn();
        render({ onSaveDirect, onSubmitProposal });

        const main = q<HTMLButtonElement>('[data-action="save-direct"]');
        const toggle = q<HTMLButtonElement>('[data-action="toggle-submit-menu"]');
        expect(main?.textContent).toBe('直接保存');
        expect(toggle).not.toBeNull();
        expect(toggle?.getAttribute('aria-haspopup')).toBe('menu');
        expect(toggle?.getAttribute('aria-expanded')).toBe('false');
        expect(toggle?.getAttribute('aria-label')).toBe('更多送出方式');
        // 未展開前選單與提案項都不存在（不是隱藏，是根本沒渲染）
        expect(q('[role="menu"]')).toBeNull();
        expect(q('[data-action="submit-proposal"]')).toBeNull();

        click(main);
        expect(onSaveDirect).toHaveBeenCalledTimes(1);
        expect(onSubmitProposal).not.toHaveBeenCalled();

        click(toggle);
        expect(toggle?.getAttribute('aria-expanded')).toBe('true');
        // 箭頭開啟時翻上（關閉時朝下，見下方）
        expect(toggle?.querySelector('i')?.className).toContain('fa-caret-up');
        const menu = q('[role="menu"]');
        expect(menu).not.toBeNull();
        const item = q<HTMLButtonElement>('[role="menuitem"][data-action="submit-proposal"]');
        expect(item?.textContent).toContain('提交提案');
        // 說明字放 tooltip，不佔版面，展開的項目看起來就是一顆按鈕
        expect(item?.getAttribute('title')).toBe('需經審核後才會套用');
        // 開啟後焦點落在第一個選單項
        expect(document.activeElement).toBe(item);

        click(item);
        expect(onSubmitProposal).toHaveBeenCalledTimes(1);
        expect(onSaveDirect).toHaveBeenCalledTimes(1);
        expect(q('[role="menu"]')).toBeNull();
        expect(toggle?.getAttribute('aria-expanded')).toBe('false');
        expect(toggle?.querySelector('i')?.className).toContain('fa-caret-down');
    });

    it('箭頭鈕再點一次收起；Esc 關閉並把焦點還給箭頭鈕；點擊元件外部關閉', () => {
        render();
        const toggle = q<HTMLButtonElement>('[data-action="toggle-submit-menu"]');

        click(toggle);
        expect(q('[role="menu"]')).not.toBeNull();
        click(toggle);
        expect(q('[role="menu"]')).toBeNull();

        click(toggle);
        keydown(q('[role="menuitem"]'), 'Escape');
        expect(q('[role="menu"]')).toBeNull();
        expect(document.activeElement).toBe(toggle);

        click(toggle);
        expect(q('[role="menu"]')).not.toBeNull();
        act(() => { document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })); });
        expect(q('[role="menu"]')).toBeNull();
    });

    it('鍵盤：箭頭鈕 ArrowDown 開啟並聚焦第一項', () => {
        render();
        const toggle = q<HTMLButtonElement>('[data-action="toggle-submit-menu"]');
        keydown(toggle, 'ArrowDown');
        expect(q('[role="menu"]')).not.toBeNull();
        expect(document.activeElement).toBe(q('[role="menuitem"]'));
    });

    it('disabled 同時停用主按鈕與箭頭，且選單打不開；disabled 途中變 true 時選單自動收起', () => {
        render({ disabled: true });
        expect(q<HTMLButtonElement>('[data-action="save-direct"]')?.disabled).toBe(true);
        const toggle = q<HTMLButtonElement>('[data-action="toggle-submit-menu"]');
        expect(toggle?.disabled).toBe(true);
        keydown(toggle, 'ArrowDown');
        expect(q('[role="menu"]')).toBeNull();

        render({ disabled: false });
        click(q('[data-action="toggle-submit-menu"]'));
        expect(q('[role="menu"]')).not.toBeNull();
        render({ disabled: true });
        expect(q('[role="menu"]')).toBeNull();
    });

    it('saving 時主按鈕顯示儲存中文案', () => {
        render({ saving: true, disabled: true });
        expect(q('[data-action="save-direct"]')?.textContent).toContain('儲存中…');
    });

    it('只可提案：單一「提交提案」主按鈕，沒有箭頭，點了直接送提案（不需再確認）', () => {
        const onSubmitProposal = vi.fn();
        render({ directAvailable: false, proposalAvailable: true, onSubmitProposal });
        expect(q('[data-action="save-direct"]')).toBeNull();
        expect(q('[data-action="toggle-submit-menu"]')).toBeNull();
        const btn = q<HTMLButtonElement>('[data-action="submit-proposal"]');
        expect(btn?.textContent).toBe('提交提案');
        expect(btn?.getAttribute('role')).toBeNull();
        click(btn);
        expect(onSubmitProposal).toHaveBeenCalledTimes(1);
    });

    it('修改提案模式：即使可直接寫入也只剩單一「更新提案」，沒有直接保存與箭頭', () => {
        const onSaveDirect = vi.fn();
        const onSubmitProposal = vi.fn();
        render({ isResubmit: true, onSaveDirect, onSubmitProposal });
        expect(q('[data-action="save-direct"]')).toBeNull();
        expect(q('[data-action="toggle-submit-menu"]')).toBeNull();
        const btn = q<HTMLButtonElement>('[data-action="submit-proposal"]');
        expect(btn?.textContent).toBe('更新提案');
        click(btn);
        expect(onSubmitProposal).toHaveBeenCalledTimes(1);
        expect(onSaveDirect).not.toHaveBeenCalled();
    });

    it('只可直接寫入（如 Codes 頁對訪客）：單一直接保存，type 可為 submit 讓原生 form 提交', () => {
        render({ directAvailable: true, proposalAvailable: false, saveDirectType: 'submit' });
        const main = q<HTMLButtonElement>('[data-action="save-direct"]');
        expect(main?.type).toBe('submit');
        expect(q('[data-action="toggle-submit-menu"]')).toBeNull();
        expect(q('[data-action="submit-proposal"]')).toBeNull();
    });

    it('兩者皆無：不渲染任何按鈕', () => {
        render({ directAvailable: false, proposalAvailable: false });
        expect(container.querySelector('button')).toBeNull();
    });
});
