import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import type { SharedProps } from '../../types/page';
import { useTranslation } from '../../hooks/useTranslation';
import { hasUnsavedChanges } from '../../hooks/useDirtyGuard';
import { cn } from '../../lib/utils';
import { type Crumb } from './Breadcrumbs';

interface NavbarProps {
    onToggleSidebar: () => void;
    isDark: boolean;
    onToggleDark: () => void;
    /** 麵包屑：渲染於頂部導覽列「首頁」之後（同一行），對齊 legacy header-v3 的頂端麵包屑。 */
    breadcrumbs?: Crumb[];
}

/**
 * 頂部導覽列：pushmenu 切換、首頁、深色模式、語言切換、使用者下拉/登入註冊。
 * 對齊舊 header-v3.blade.php 的功能與行為（含語言切換的未存變更確認）。
 */
export default function Navbar({ onToggleSidebar, isDark, onToggleDark, breadcrumbs }: NavbarProps) {
    const { auth, locale, locale_url, shell } = usePage<SharedProps>().props;
    const tNav = useTranslation('nav');
    const tCommon = useTranslation('common');
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const user = auth?.user ?? null;
    const currentLocale = locale ?? 'zh-TW';

    const switchLocale = () => {
        if (hasUnsavedChanges()) {
            if (!window.confirm(tNav('locale_switch_unsaved_warning'))) {
                return;
            }
        }
        const next = currentLocale === 'zh-TW' ? 'en' : 'zh-TW';
        router.post(locale_url ?? '/locale', { locale: next }, { preserveScroll: true });
    };

    const logout = () => {
        router.post(shell.logout_url);
    };

    const langLabel = currentLocale === 'zh-TW'
        ? tNav('language_switch_to_en')
        : tNav('language_switch_to_zh');

    return (
        <nav className="flex h-14 items-center gap-2 border-b border-border bg-card px-3 text-card-foreground">
            <button
                type="button"
                className="rounded p-2 hover:bg-muted"
                aria-label="Toggle sidebar"
                onClick={onToggleSidebar}
            >
                <i className="fas fa-bars" aria-hidden />
            </button>
            <a href={shell.home_url} className="hidden px-2 text-sm hover:text-primary sm:inline">
                {tCommon('home')}
            </a>

            {/* 頂端麵包屑：併入「首頁」同一行（#113），最後一項為 active 不可點。 */}
            {breadcrumbs && breadcrumbs.length > 0 && (
                <ol className="hidden min-w-0 items-center gap-1 overflow-hidden text-sm text-muted-foreground md:flex">
                    {breadcrumbs.map((crumb, i) => {
                        const isLast = i === breadcrumbs.length - 1;
                        return (
                            <li key={i} className="flex min-w-0 items-center gap-1">
                                <span className="opacity-50">/</span>
                                {isLast || !crumb.url ? (
                                    <span className="truncate font-medium text-foreground">{crumb.label}</span>
                                ) : (
                                    <a href={crumb.url} className="truncate hover:text-primary">{crumb.label}</a>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}

            <div className="ml-auto flex items-center gap-1">
                <button
                    type="button"
                    className="rounded p-2 hover:bg-muted"
                    title={tCommon('dark_mode_toggle')}
                    aria-label={tCommon('dark_mode_toggle')}
                    onClick={onToggleDark}
                >
                    <i className={cn('fas', isDark ? 'fa-sun' : 'fa-moon')} aria-hidden />
                </button>
                <button
                    type="button"
                    className="rounded px-2 py-1 text-sm font-semibold tracking-wide hover:bg-muted"
                    title={langLabel}
                    onClick={switchLocale}
                >
                    {langLabel}
                </button>

                {user ? (
                    <div className="relative">
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded px-2 py-1 hover:bg-muted"
                            onClick={() => setUserMenuOpen((v) => !v)}
                            aria-haspopup="menu"
                            aria-expanded={userMenuOpen}
                        >
                            {user.avatar ? (
                                <img
                                    src={`/images/avatar/${user.avatar}`}
                                    alt=""
                                    className="h-8 w-8 rounded-full"
                                />
                            ) : (
                                <i className="fas fa-user-circle text-xl" aria-hidden />
                            )}
                            <span className="hidden text-sm md:inline">{user.name}</span>
                        </button>
                        {userMenuOpen && (
                            <>
                                {/* 點擊外部關閉 */}
                                <div className="fixed inset-0 z-10" onClick={() => setUserMenuOpen(false)} />
                                <div
                                    role="menu"
                                    className="absolute right-0 z-20 mt-1 w-56 overflow-hidden rounded-md border border-border bg-popover py-1 text-popover-foreground shadow-lg"
                                >
                                    <div className="px-4 py-2">
                                        <div className="truncate font-semibold">{user.name}</div>
                                        {user.institution ? (
                                            <div className="truncate text-xs text-muted-foreground">{user.institution}</div>
                                        ) : null}
                                    </div>
                                    <div className="my-1 border-t border-border" />
                                    {shell.profile_url ? (
                                        <a
                                            href={shell.profile_url}
                                            role="menuitem"
                                            className="flex items-center gap-2.5 px-4 py-2 text-sm hover:bg-muted"
                                        >
                                            <i className="fas fa-user-cog w-4 text-center text-muted-foreground" aria-hidden />
                                            {tCommon('profile_settings')}
                                        </a>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={logout}
                                        role="menuitem"
                                        className="flex w-full items-center gap-2.5 px-4 py-2 text-left text-sm text-destructive hover:bg-destructive/10"
                                    >
                                        <i className="fas fa-sign-out-alt w-4 text-center" aria-hidden />
                                        {tCommon('sign_out')}
                                    </button>
                                </div>
                            </>
                        )}
                    </div>
                ) : (
                    <>
                        {/* 帶上目前位址，登入後可返回原頁（對齊 header-v3 的 redirect 參數）。 */}
                        <a
                            href={`${shell.login_url}?redirect=${encodeURIComponent(
                                typeof window !== 'undefined'
                                    ? window.location.pathname + window.location.search
                                    : ''
                            )}`}
                            className="px-2 text-sm hover:text-primary"
                        >
                            Login
                        </a>
                        <a href={shell.register_url} className="px-2 text-sm hover:text-primary">Register</a>
                    </>
                )}
            </div>
        </nav>
    );
}
