import React from 'react';
import { router, usePage } from '@inertiajs/react';
import { APP_THEME } from '../theme';
import { useTranslation } from '../hooks/useTranslation';
import { hasUnsavedChanges } from '../hooks/useDirtyGuard';

interface AppShellProps {
    children: React.ReactNode;
}

export default function AppShell({ children }: AppShellProps) {
    const { app, locale, locale_url } =
        usePage<{ app?: { version?: string }; locale?: string; locale_url?: string }>().props;
    const version = app?.version || 'unknown';
    const currentLocale = locale ?? 'zh-TW';
    const localeEndpoint = locale_url ?? '/locale';
    const tNav = useTranslation('nav');

    const switchLocale = () => {
        if (hasUnsavedChanges()) {
            const msg = currentLocale === 'zh-TW'
                ? '切換語言將會遺失未儲存的修改。確定要繼續嗎？'
                : 'Switching language will discard unsaved changes. Continue?';
            if (!window.confirm(msg)) return;
        }
        const next = currentLocale === 'zh-TW' ? 'en' : 'zh-TW';
        router.post(localeEndpoint, { locale: next });
    };

    return (
        <div style={{ minHeight: '100vh', backgroundColor: APP_THEME.canvas, fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif', display: 'flex', flexDirection: 'column' }}>
            <header style={{ backgroundColor: APP_THEME.brand, color: '#fff', padding: '12px 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <h1 style={{ margin: 0, fontSize: '1.25rem', fontWeight: 600 }}>
                    {tNav('app_title')}
                </h1>
                <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                    <a href="/" style={{ color: APP_THEME.brandOnDark, textDecoration: 'none', fontSize: '0.875rem' }}>
                        {tNav('back_to_home')}
                    </a>
                    <button
                        onClick={switchLocale}
                        style={{ background: 'none', border: '1px solid rgba(255,255,255,0.6)', borderRadius: 4, color: '#fff', cursor: 'pointer', fontSize: '0.8rem', fontWeight: 600, letterSpacing: '0.05em', padding: '3px 10px' }}
                        title="Switch language / 切換語言"
                    >
                        {currentLocale === 'zh-TW'
                            ? tNav('language_switch_to_en')
                            : tNav('language_switch_to_zh')}
                    </button>
                </div>
            </header>
            <main style={{ padding: 0, margin: 0, flex: 1 }}>
                {children}
            </main>
            <footer style={{ backgroundColor: '#fff', borderTop: '1px solid #dee2e6', padding: '12px 16px', fontSize: '0.875rem', color: '#495057' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                    <div>
                        <strong>Copyright &copy; </strong>
                        <a href="https://cbdb.hsites.harvard.edu/" target="_blank" rel="noreferrer" style={footerLinkStyle}>
                            Chinese Biographical Database Project (CBDB)
                        </a>
                        . Content licensed under{' '}
                        <a href="https://creativecommons.org/licenses/by-nc-sa/4.0/" target="_blank" rel="noreferrer" style={footerLinkStyle}>
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
    );
}

const footerLinkStyle: React.CSSProperties = {
    color: APP_THEME.brandText,
    textDecoration: 'none',
};
