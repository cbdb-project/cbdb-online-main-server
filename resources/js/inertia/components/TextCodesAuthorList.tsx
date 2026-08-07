import React from 'react';
import { formatBilingualLabel } from './PersonBrowser/shared/formatters';

/**
 * TEXT_CODES 編輯頁的作者／編者等關係人清單（唯讀，作為錄入參考）。
 *
 * 對齊舊版 codes/edit.blade.php 的作者區塊（2022-01 #186、2025-12 #655）——該區塊在 2026-06-26
 * React/Inertia 上線（3f131d6）時漏移植，正式站上就此消失；此元件補回。
 *
 * 資料由後端 CodesController::textCodesAuthors() 隨頁面一次取回（非 AJAX），故無載入態；
 * 但保留失敗態：後端查詢失敗時降級成提示，讓表單本身仍可編輯可儲存（對齊舊版 AJAX 失敗行為）。
 */

export interface TextAuthor {
    c_personid: number;
    c_role_id: number;
    name_chn: string | null;
    name: string | null;
    role_chn: string | null;
    role: string | null;
    /** 該作者的著述分頁；c_personid=0（未詳哨兵）為 null，不給連結。 */
    url: string | null;
}

export interface TextAuthors {
    /** 關係列總數（非人數：同一人的多個角色分列計算）。 */
    total: number;
    /** 後端的顯示上限。 */
    limit: number;
    items: TextAuthor[];
    /**
     * 後端判定「還有更多未顯示」。刻意由後端多取一筆判斷，而非在前端比較 total 與 items.length——
     * total 來自另一次 count 查詢，兩者之間若有寫入就會相等而漏掉提示。
     */
    truncated: boolean;
    /** 後端查詢失敗（降級態）；此時 items 為空但不代表這本書沒有作者。 */
    failed: boolean;
}

/** 超過此筆數改為可滾動清單（對齊舊版的 5 筆門檻）。 */
const SCROLL_THRESHOLD = 5;

interface Props {
    authors: TextAuthors;
    t: (key: string, replace?: Record<string, string>) => string;
}

export function TextCodesAuthorList({ authors, t }: Props) {
    const { total, items, failed, truncated } = authors;

    // 失敗態與空態都不顯示操作提示（提示在講「點人名可跳轉」，此時無人名可點）。
    if (failed) {
        return <p className="text-sm text-destructive">{t('load_failed')}</p>;
    }

    if (items.length === 0) {
        return <p className="text-sm text-muted-foreground">{t('no_author_data')}</p>;
    }

    const scrolling = items.length > SCROLL_THRESHOLD;
    // 截斷時 total 至少是「已顯示 + 1」：count 與 select 之間若有寫入，count 可能落後，
    // 但提示本身已由後端的 truncated 決定，不會消失。
    const reportedTotal = Math.max(total, items.length + 1);

    return (
        <div className="space-y-1">
            <ul
                className={
                    'm-0 list-none space-y-1 p-0 text-sm'
                    // 可滾動時給邊框與底色（對齊舊版 .author-list-scroll）：細/覆蓋式滾動條在
                    // Windows/macOS 預設看不見，沒有這個框使用者不會知道下面還有列。
                    + (scrolling ? ' max-h-40 overflow-y-auto rounded-md border border-border bg-muted/30 p-2 pr-2' : '')
                }
            >
                {items.map((a) => {
                    const person = formatBilingualLabel(a.name_chn, a.name) ?? t('author_unknown_person');
                    const role = formatBilingualLabel(a.role_chn, a.role);

                    return (
                        // 同一人可在同一本書掛多個角色（BIOG_TEXT_DATA 主鍵
                        // (c_personid, c_role_id, c_textid) 含 c_role_id），
                        // 故 key 必須是 personid + role_id 這組複合鍵，不可只用 personid。
                        <li key={`${a.c_personid}-${a.c_role_id}`}>
                            {a.url ? (
                                <a
                                    href={a.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-primary hover:underline"
                                >
                                    [{a.c_personid}] {person}
                                </a>
                            ) : (
                                // c_personid=0（未詳）：跳過去沒有意義，顯示為純文字。
                                <span className="text-muted-foreground">[{a.c_personid}] {person}</span>
                            )}
                            {role && <span className="text-muted-foreground"> — {role}</span>}
                        </li>
                    );
                })}
            </ul>
            {truncated && (
                <p className="text-xs text-muted-foreground">
                    {t('author_truncated', { total: String(reportedTotal), shown: String(items.length) })}
                </p>
            )}
            <p className="text-xs text-muted-foreground">{t('author_hint')}</p>
        </div>
    );
}
