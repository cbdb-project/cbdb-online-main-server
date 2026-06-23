import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '../types/page';
import { useDarkMode } from '../hooks/useDarkMode';
import Sidebar from '../components/shell/Sidebar';
import Navbar from '../components/shell/Navbar';
import FlashMessages from '../components/shell/FlashMessages';
import Breadcrumbs, { type Crumb } from '../components/shell/Breadcrumbs';

interface DashboardLayoutProps {
    children: React.ReactNode;
    title?: string;
    description?: string;
    breadcrumbs?: Crumb[];
    /**
     * 關閉內容區預設內距（p-4 md:p-6）。供既有自帶內距/全幅的 React 工具
     * （PersonBrowser / QueryPlayground / SearchByEntry / ViewTables）沿用其原版面，
     * 避免改套 DashboardLayout 後與自身 padding 疊加。新遷移頁不傳此 prop，行為不變。
     */
    disableContentPadding?: boolean;
    /**
     * 標頭（麵包屑/標題/描述）對齊方式。預設 'left'（其餘頁面不受影響）；
     * 'center' 供人物詳情中樞/編輯器頁，使身份標頭「人物記錄 / 分頁」+「id - 名 · 朝代」置中。
     * 注意：僅置中此標頭區塊，子資源分頁導航（PersonBanner）仍維持靠左（tab 慣例）。
     */
    headerAlign?: 'left' | 'center';
}

/**
 * Phase 0 F2：正式版 React 殼（AdminLTE 風格），供遷移後的後台/CRUD 頁面使用。
 *
 * 既有 React 工具（PersonBrowser / QueryPlayground / SearchByEntry / ViewTables）亦已改套
 * 本元件（以 disableContentPadding 保留自身內距），與遷移頁版面一致（見附錄 D.1，2026-06-19）。
 *
 * 組成：深色側邊欄（單一來源 nav）＋ 導覽列（pushmenu/深色模式/語言/使用者）＋
 * 內容標頭（標題/描述/麵包屑）＋ flash 訊息 ＋ 內容 ＋ 頁尾。
 */
export default function DashboardLayout({ children, title, description, breadcrumbs, disableContentPadding, headerAlign = 'left' }: DashboardLayoutProps) {
    const { app } = usePage<SharedProps>().props;
    const { isDark, toggle } = useDarkMode();
    const [collapsed, setCollapsed] = useState(false);
    const version = app?.version ?? 'unknown';

    return (
        <div className="flex min-h-screen bg-background text-foreground">
            <Sidebar collapsed={collapsed} />

            <div className="flex min-w-0 flex-1 flex-col">
                <Navbar
                    onToggleSidebar={() => setCollapsed((v) => !v)}
                    isDark={isDark}
                    onToggleDark={toggle}
                />

                <main className={`flex-1${disableContentPadding ? '' : ' p-4 md:p-6'}`}>
                    {(title || breadcrumbs) && (
                        <div className="mb-4 flex flex-col gap-1 border-b border-border pb-3">
                            {/* headerAlign='center'：僅標題（身份）置中，麵包屑維持靠左（使用者指定）。 */}
                            <Breadcrumbs crumbs={breadcrumbs} />
                            {title && <h1 className={`text-xl font-semibold${headerAlign === 'center' ? ' text-center' : ''}`}>{title}</h1>}
                            {description && <p className={`text-sm text-muted-foreground${headerAlign === 'center' ? ' text-center' : ''}`}>{description}</p>}
                        </div>
                    )}

                    <div className="mb-4">
                        <FlashMessages />
                    </div>

                    {children}
                </main>

                <footer className="border-t border-border bg-card px-4 py-3 text-sm text-muted-foreground">
                    <div className="flex flex-wrap justify-between gap-2">
                        <div>
                            <strong>Copyright &copy; </strong>
                            <a
                                href="https://cbdb.hsites.harvard.edu/"
                                target="_blank"
                                rel="noreferrer"
                                className="text-primary hover:underline"
                            >
                                Chinese Biographical Database Project (CBDB)
                            </a>
                            . Content licensed under{' '}
                            <a
                                href="https://creativecommons.org/licenses/by-nc-sa/4.0/"
                                target="_blank"
                                rel="noreferrer"
                                className="text-primary hover:underline"
                            >
                                CC BY-NC-SA 4.0 International
                            </a>
                            .
                        </div>
                        <div>
                            <strong>Version:</strong> {version}
                        </div>
                    </div>
                </footer>
            </div>
        </div>
    );
}
