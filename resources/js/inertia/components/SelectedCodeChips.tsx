import React from 'react';
import { EntryCode } from './EntryCodeList';

interface Props {
    selectedCodes: number[];
    allCodes: EntryCode[];
    onRemove: (code: number) => void;
}

export default function SelectedCodeChips({ selectedCodes, allCodes, onRemove }: Props) {
    if (selectedCodes.length === 0) {
        return <span style={{ color: 'var(--muted-foreground)', fontSize: '0.85rem' }}>尚未選擇</span>;
    }

    const codeMap = new Map(allCodes.map((c) => [c.c_entry_code, c]));

    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4 }}>
            {selectedCodes.map((code) => {
                const info = codeMap.get(code);
                const label = info ? (info.c_entry_desc_chn || info.c_entry_desc || String(code)) : String(code);
                return (
                    <span
                        key={code}
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            padding: '2px 8px',
                            backgroundColor: 'var(--primary)',
                            color: 'var(--primary-foreground)',
                            borderRadius: 4,
                            fontSize: '0.75rem',
                        }}
                    >
                        {label}
                        <button
                            type="button"
                            onClick={() => onRemove(code)}
                            style={{
                                marginLeft: 4,
                                background: 'none',
                                border: 'none',
                                color: 'var(--primary-foreground)',
                                cursor: 'pointer',
                                padding: '0 2px',
                                fontSize: '0.85rem',
                                lineHeight: 1,
                            }}
                            aria-label={`移除 ${label}`}
                        >
                            ×
                        </button>
                    </span>
                );
            })}
        </div>
    );
}
