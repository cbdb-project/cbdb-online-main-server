import React from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '../types/page';

interface AuthLayoutProps {
    children: React.ReactNode;
    /** 卡片上方的副標題（如「系統登入」）。 */
    subtitle?: string;
    /** 卡片內標題（如「歡迎回來」）。 */
    heading?: string;
    /** 卡片下方頁尾（互轉連結等）。 */
    footer?: React.ReactNode;
}

/**
 * Phase 6：極簡認證版面（置中卡片、全頁），供 login / register / 忘記密碼 / 重設密碼共用。
 *
 * 刻意「不」套 DashboardLayout（無側邊欄/導覽列），與舊 Blade 認證頁的全頁置中卡片一致。
 * 品牌標題連到 /home，與舊頁行為相同。
 */
export default function AuthLayout({ children, subtitle, heading, footer }: AuthLayoutProps) {
    const { app } = usePage<SharedProps>().props;
    const appName = app?.name ?? 'CBDB';

    return (
        <div className="flex min-h-screen items-center justify-center bg-muted px-4 py-8 text-foreground">
            <div className="w-full max-w-md">
                <div className="mb-6 text-center">
                    <a href="/home" className="inline-block no-underline text-foreground">
                        <div className="text-2xl font-semibold">{appName}</div>
                        {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
                    </a>
                </div>

                <div className="rounded-lg border border-border bg-card shadow-sm">
                    <div className="p-6">
                        {heading && <h1 className="mb-5 text-center text-lg font-semibold">{heading}</h1>}
                        {children}
                    </div>
                    {footer && (
                        <div className="rounded-b-lg border-t border-border bg-background px-6 py-3 text-center text-sm">
                            {footer}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
