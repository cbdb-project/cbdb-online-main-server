import React from 'react';
import AppShell from '../../Layouts/AppShell';

interface ViewDefinition {
    key: string;
    primary_alias: string;
    title: string;
    description: string;
    aliases: string[];
}

interface Props {
    views: ViewDefinition[];
    listUrl: string;
}

export default function List({ views, listUrl }: Props) {
    return (
        <AppShell>
            <div style={{ padding: '24px 24px 48px' }}>
                <div style={{ marginBottom: 20 }}>
                    <h2 style={{ fontSize: '1.4rem', fontWeight: 600, margin: 0, color: '#212529' }}>
                        檢視表總覽
                    </h2>
                    <p style={{ color: '#6c757d', fontSize: '0.9rem', marginTop: 6 }}>
                        以下列出目前系統支援的檢視表（View_*），點選可直接進入對應頁面。
                    </p>
                </div>

                <div style={{
                    backgroundColor: '#fff',
                    border: '1px solid #dee2e6',
                    borderRadius: 6,
                    overflow: 'hidden',
                }}>
                    <div style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch' }}>
                        <table style={{
                            width: '100%',
                            borderCollapse: 'collapse',
                            fontSize: '0.9rem',
                            minWidth: 600,
                        }}>
                            <thead>
                                <tr style={{ backgroundColor: '#f8f9fa' }}>
                                    <th style={thStyle}>檢視名稱 (ENG)</th>
                                    <th style={thStyle}>檢視名稱 (CHN)</th>
                                    <th style={thStyle}>說明</th>
                                </tr>
                            </thead>
                            <tbody>
                                {views.map((view, index) => (
                                    <tr
                                        key={view.key}
                                        style={{ backgroundColor: index % 2 === 0 ? '#fff' : '#f8f9fa' }}
                                    >
                                        <td style={tdStyle}>
                                            <a
                                                href={`${listUrl}/${view.key}`}
                                                style={{ color: '#007bff', textDecoration: 'none', fontFamily: 'monospace', fontSize: '0.85rem' }}
                                            >
                                                {view.primary_alias}
                                            </a>
                                            {view.aliases.length > 1 && (
                                                <div style={{ color: '#6c757d', fontSize: '0.8rem', marginTop: 2 }}>
                                                    {view.aliases.slice(1).join(', ')}
                                                </div>
                                            )}
                                        </td>
                                        <td style={tdStyle}>{view.title}</td>
                                        <td style={tdStyle}>{view.description || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppShell>
    );
}

const thStyle: React.CSSProperties = {
    padding: '10px 12px',
    textAlign: 'left',
    fontWeight: 600,
    fontSize: '0.85rem',
    color: '#495057',
    borderBottom: '2px solid #dee2e6',
    whiteSpace: 'nowrap',
};

const tdStyle: React.CSSProperties = {
    padding: '8px 12px',
    borderBottom: '1px solid #dee2e6',
    verticalAlign: 'top',
};
