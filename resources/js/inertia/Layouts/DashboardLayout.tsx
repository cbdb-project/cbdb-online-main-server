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
}

/**
 * Phase 0 F2：正式版 React 殼（AdminLTE 風格），供遷移後的後台/CRUD 頁面使用。
 *
 * 注意：既有 5 個已上線 React 頁（PersonBrowser/QueryPlayground/SearchByEntry/
 * ViewTables）仍沿用精簡版 AppShell，本元件不強加於它們，避免對線上工具造成
 * 非預期的版面變動（見遷移計畫附錄 D 決策記錄）。新遷移頁一律改用 DashboardLayout。
 *
 * 組成：深色側邊欄（單一來源 nav）＋ 導覽列（pushmenu/深色模式/語言/使用者）＋
 * 內容標頭（標題/描述/麵包屑）＋ flash 訊息 ＋ 內容 ＋ 頁尾。
 */
export default function DashboardLayout({ children, title, description, breadcrumbs }: DashboardLayoutProps) {
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

                <main className="flex-1 p-4 md:p-6">
                    {(title || breadcrumbs) && (
                        <div className="mb-4 flex flex-col gap-1 border-b border-border pb-3">
                            <Breadcrumbs crumbs={breadcrumbs} />
                            {title && <h1 className="text-xl font-semibold">{title}</h1>}
                            {description && <p className="text-sm text-muted-foreground">{description}</p>}
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
