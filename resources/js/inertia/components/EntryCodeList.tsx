import React from 'react';

export interface EntryCode {
    c_entry_code: number;
    c_entry_desc: string | null;
    c_entry_desc_chn: string | null;
}

interface Props {
    codes: EntryCode[];
    selectedCodes: number[];
    loading: boolean;
    error: string | null;
    onToggle: (code: number) => void;
    onSelectAll: () => void;
    onDeselectAll: () => void;
}

export default function EntryCodeList({ codes, selectedCodes, loading, error, onToggle, onSelectAll, onDeselectAll }: Props) {
    const selectedSet = new Set(selectedCodes);

    return (
        <div style={{ border: '1px solid #dee2e6', borderRadius: 4, backgroundColor: '#fff', overflow: 'hidden' }}>
            <div style={{ padding: '10px 14px', borderBottom: '1px solid #dee2e6', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <span style={{ fontWeight: 600, fontSize: '0.95rem' }}>入仕代碼</span>
                <div>
                    <button
                        type="button"
                        onClick={onSelectAll}
                        disabled={codes.length === 0}
                        style={{
                            padding: '2px 10px', marginRight: 4, fontSize: '0.8rem', cursor: 'pointer',
                            border: '1px solid #007bff', borderRadius: 3, backgroundColor: 'transparent', color: '#007bff',
                        }}
                    >
                        全選
                    </button>
                    <button
                        type="button"
                        onClick={onDeselectAll}
                        disabled={codes.length === 0}
                        style={{
                            padding: '2px 10px', fontSize: '0.8rem', cursor: 'pointer',
                            border: '1px solid #6c757d', borderRadius: 3, backgroundColor: 'transparent', color: '#6c757d',
                        }}
                    >
                        取消全選
                    </button>
                </div>
            </div>
            <div style={{ height: 400, overflowY: 'auto' }}>
                {loading && (
                    <div style={{ padding: 16, textAlign: 'center', color: '#6c757d' }}>載入中...</div>
                )}
                {error && (
                    <div style={{ padding: 16, color: '#dc3545' }}>{error}</div>
                )}
                {!loading && !error && codes.length === 0 && (
                    <div style={{ padding: 16, color: '#6c757d' }}>請先選擇入仕類型</div>
                )}
                {!loading && !error && codes.map((code) => {
                    const isChecked = selectedSet.has(code.c_entry_code);
                    return (
                        <label
                            key={code.c_entry_code}
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                padding: '6px 12px',
                                borderBottom: '1px solid #f0f0f0',
                                cursor: 'pointer',
                                margin: 0,
                                fontSize: '0.875rem',
                            }}
                            onMouseEnter={(e) => (e.currentTarget.style.backgroundColor = '#f8f9fa')}
                            onMouseLeave={(e) => (e.currentTarget.style.backgroundColor = '')}
                        >
                            <input
                                type="checkbox"
                                checked={isChecked}
                                onChange={() => onToggle(code.c_entry_code)}
                                style={{ marginRight: 8 }}
                            />
                            <span>{code.c_entry_desc_chn || code.c_entry_desc || String(code.c_entry_code)}</span>
                        </label>
                    );
                })}
            </div>
        </div>
    );
}
