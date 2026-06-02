import React, { useMemo } from 'react';
import { useTranslation } from '../../hooks/useTranslation';

export type PlaygroundMode = 'sql' | 'nl' | 'qbe' | 'qa';

interface Props {
    activeMode: PlaygroundMode;
    onModeChange: (mode: PlaygroundMode) => void;
}

const MODE_ICONS: Record<PlaygroundMode, string> = {
    sql: '⌨',
    nl: '💬',
    qbe: '🔧',
    qa: '📖',
};

export default function ModeTabs({ activeMode, onModeChange }: Props) {
    const t = useTranslation('query');

    const modes = useMemo(() => [
        { key: 'sql' as PlaygroundMode, label: t('mode_sql'), icon: MODE_ICONS.sql },
        { key: 'nl'  as PlaygroundMode, label: t('mode_nl'),  icon: MODE_ICONS.nl  },
        { key: 'qbe' as PlaygroundMode, label: t('mode_qbe'), icon: MODE_ICONS.qbe },
        { key: 'qa'  as PlaygroundMode, label: t('mode_qa'),  icon: MODE_ICONS.qa  },
    ], [t]);

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
