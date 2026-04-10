import React from 'react';
import { usePage } from '@inertiajs/react';
import { APP_THEME } from '../theme';

interface AppShellProps {
    children: React.ReactNode;
}

export default function AppShell({ children }: AppShellProps) {
    const { app } = usePage<{ app?: { version?: string } }>().props;
    const version = app?.version || 'unknown';

    return (
        <div style={{ minHeight: '100vh', backgroundColor: APP_THEME.canvas, fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif', display: 'flex', flexDirection: 'column' }}>
            <header style={{ backgroundColor: APP_THEME.brand, color: '#fff', padding: '12px 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <h1 style={{ margin: 0, fontSize: '1.25rem', fontWeight: 600 }}>CBDB 中國歷代人物傳記資料庫</h1>
                <a href="/" style={{ color: APP_THEME.brandOnDark, textDecoration: 'none', fontSize: '0.875rem' }}>← 返回首頁</a>
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
