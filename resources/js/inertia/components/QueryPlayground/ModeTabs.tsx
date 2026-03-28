import React from 'react';

export type PlaygroundMode = 'sql' | 'nl' | 'qbe' | 'qa';

interface Props {
    activeMode: PlaygroundMode;
    onModeChange: (mode: PlaygroundMode) => void;
}

const modes: { key: PlaygroundMode; label: string; icon: string }[] = [
    { key: 'sql', label: 'SQL 查詢', icon: '⌨' },
    { key: 'nl', label: '自然語言', icon: '💬' },
    { key: 'qbe', label: '查詢設計 (QBE)', icon: '🔧' },
    { key: 'qa', label: '歷史問答', icon: '📖' },
];

export default function ModeTabs({ activeMode, onModeChange }: Props) {
    return (
        <div style={{ display: 'flex', gap: 0, borderBottom: '2px solid #dee2e6' }}>
            {modes.map(({ key, label, icon }) => {
                const isActive = activeMode === key;
                return (
                    <button
                        key={key}
                        onClick={() => onModeChange(key)}
                        style={{
                            padding: '10px 20px',
                            fontSize: '0.9rem',
                            fontWeight: isActive ? 600 : 400,
                            color: isActive ? '#007bff' : '#495057',
                            backgroundColor: isActive ? '#fff' : 'transparent',
                            border: isActive ? '2px solid #dee2e6' : '2px solid transparent',
                            borderBottom: isActive ? '2px solid #fff' : '2px solid transparent',
                            marginBottom: isActive ? '-2px' : 0,
                            borderRadius: '6px 6px 0 0',
                            cursor: 'pointer',
                            transition: 'color 0.15s, background-color 0.15s',
                            whiteSpace: 'nowrap',
                        }}
                    >
                        <span style={{ marginRight: 6 }}>{icon}</span>
                        {label}
                    </button>
                );
            })}
        </div>
    );
}
