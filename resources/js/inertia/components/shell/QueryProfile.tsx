import React, { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Modal } from '../ui/Modal';
import { Button } from '../ui/Button';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

/**
 * SQL 查詢明細（頁尾的效能除錯輔助）。
 *
 * 舊版 layouts/dashboard-v3.blade.php 底部有「本次查詢共 N 筆，耗時 X ms」＋管理員可開的明細
 * modal；React layout 沒有搬過來，於是 DB::listen 照樣把每筆 SQL 收進記憶體、卻沒人看得到。
 * 這個元件補回顯示端。
 *
 * 對齊舊版的權限分界：**摘要行對所有人顯示**（舊版該行沒有任何權限閘），**明細只給管理員**
 * （非管理員的 queries 為空陣列，後端連 SQL 都不送——見 HandleInertiaRequests::queryProfile()
 * 與 AppServiceProvider::shouldRetainQueryDetails()）。
 *
 * props.query_profile 為 null 時（本次沒有查詢）整區不渲染。
 */
export default function QueryProfile() {
    // 型別在 types/page.ts 的 SharedProps 上（這是 shared prop，每頁都有），
    // 不在此另立一份區域型別以免與後端輸出漂移。
    const { query_profile: profile } = usePage<SharedProps>().props;
    const t = useTranslation('common');
    const [open, setOpen] = useState(false);

    // 局部重載（切換分頁等）刻意不帶明細——每個 XHR 夾帶上百句 SQL 只為了一個偶爾打開的
    // modal，並不划算，且 Inertia 會把 props 留在 window.history.state 裡。這裡記住上一次
    // 整頁載入的明細，讓「查看詳細」不會在局部更新後忽然消失。
    //
    // ⚠️ 只有後端明說 details_omitted 時才沿用舊值。不能改成「本次沒帶就沿用」——同一個
    // React 殼會活過登出／被降權／換 session，那時回應是 queries: [] 且 details_omitted=false，
    // 若沿用舊值就等於把管理員的 SQL 繼續顯示給已經不是管理員的人。
    const [details, setDetails] = useState<NonNullable<SharedProps['query_profile']>['queries']>([]);
    useEffect(() => {
        if (profile?.details_omitted) {
            return;
        }

        setDetails(profile?.queries ?? []);
    }, [profile]);

    if (!profile || !profile.count) {
        return null;
    }

    // 舊版用 number_format(…, 2)，有千分位；一個慢頁面的總毫秒數正是最需要一眼看懂的地方。
    const ms = profile.time_ms.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
    // 局部重載時用記住的明細（下方會標明來源），其餘一律以本次回應為準。
    const rows = profile.details_omitted ? details : profile.queries;
    const hasDetails = rows.length > 0;
    const detailsFromPageLoad = profile.details_omitted && rows.length > 0;

    return (
        <>
            {/* 外層留白由本元件自帶，讓 layout 在回傳 null 時不留下空的 padding 容器。 */}
            <div className="px-4 pb-2 text-sm text-muted-foreground">
                {t('query_profile_count', { count: profile.count.toLocaleString(), ms })}
                {hasDetails && (
                    <button
                        type="button"
                        className="ml-2 text-primary hover:underline"
                        onClick={() => setOpen(true)}
                    >
                        {t('query_profile_view')}
                    </button>
                )}
            </div>

            {/* 用共用 Modal（Radix：focus trap、Esc、a11y 內建）而非自製對話框。 */}
            <Modal
                open={open}
                onOpenChange={setOpen}
                title={t('query_profile_title')}
                className="max-w-5xl"
                footer={
                    // 舊版 modal 底部有「關閉」鈕（dashboard-v3.blade.php:410），一併補回。
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        {t('close')}
                    </Button>
                }
            >
                <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">
                        {t('query_profile_summary', { count: profile.count.toLocaleString(), ms })}
                    </p>
                    {detailsFromPageLoad && (
                        <p className="text-xs text-muted-foreground">{t('query_profile_from_page_load')}</p>
                    )}
                    {!detailsFromPageLoad && profile.truncated && (
                        <p className="text-xs text-muted-foreground">
                            {t('query_profile_truncated', { n: String(rows.length) })}
                        </p>
                    )}

                    {/* 寬內容自行橫向滾動，不讓整頁橫向溢出 */}
                    <div className="max-h-[60vh] overflow-auto">
                        <table className="w-full border-collapse text-xs">
                            <thead className="sticky top-0 bg-card">
                                <tr className="border-b border-border text-left">
                                    <th scope="col" className="w-12 px-2 py-1">{t('query_profile_no')}</th>
                                    <th scope="col" className="w-20 px-2 py-1">{t('query_profile_time')}</th>
                                    <th scope="col" className="px-2 py-1">{t('query_profile_sql')}</th>
                                    <th scope="col" className="w-56 px-2 py-1">{t('query_profile_bindings')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((q, i) => (
                                    // 同一頁可能出現一模一樣的 SQL，故 key 用序號＋內容組合而非只用 SQL。
                                    <tr key={`${i}-${q.sql.length}`} className="border-b border-border align-top">
                                        <td className="px-2 py-1 text-muted-foreground">{i + 1}</td>
                                        <td className="px-2 py-1 tabular-nums">{q.time.toFixed(2)}</td>
                                        <td className="px-2 py-1">
                                            <code className="whitespace-pre-wrap break-all">{q.sql}</code>
                                        </td>
                                        <td className="px-2 py-1">
                                            <code className="whitespace-pre-wrap break-all text-muted-foreground">{q.bindings}</code>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </Modal>
        </>
    );
}
