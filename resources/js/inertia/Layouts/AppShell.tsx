import React from 'react';

interface AppShellProps {
    children: React.ReactNode;
}

export default function AppShell({ children }: AppShellProps) {
    return (
        <div style={{ minHeight: '100vh', backgroundColor: '#f4f6f9', fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif' }}>
            <header style={{ backgroundColor: '#343a40', color: '#fff', padding: '12px 24px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <h1 style={{ margin: 0, fontSize: '1.25rem', fontWeight: 600 }}>CBDB 中國歷代人物傳記資料庫</h1>
                <a href="/" style={{ color: '#adb5bd', textDecoration: 'none', fontSize: '0.875rem' }}>← 返回首頁</a>
            </header>
            <main style={{ padding: '24px', maxWidth: '1400px', margin: '0 auto' }}>
                {children}
            </main>
        </div>
    );
}
