import React, { useEffect, useState } from 'react';
import { useTranslation } from '../../hooks/useTranslation';

/**
 * 「典籍出處候選」聯動（textperson_pair）—— 對齊 legacy 各子資源 _form 底部的同款控件。
 *
 * 進頁 GET /api/select/search/textperson?q={personId} 預載該人物相關「典籍-頁碼」候選；
 * 選一項 → 其 value 以 `&and&` 拆出 [textid, pages] → GET /api/select/search/text?q={textid}
 * 取標題 → 回呼 onPick({ source, pages, sourceLabel })，由父元件回填 c_source + c_pages 並高亮。
 *
 * 12 個子資源編輯器共用此元件。
 */
interface TextpersonOption {
    value: string;
    text: string;
}

interface Props {
    personId: number;
    label?: string;
    hint?: string;
    onPick: (picked: { source: string; pages: string; sourceLabel: string }) => void;
    disabled?: boolean;
}

export default function TextpersonPair({ personId, label, hint, onPick, disabled = false }: Props) {
    const t = useTranslation('biogmains');
    const resolvedLabel = label ?? t('candidate_source_title');
    const [options, setOptions] = useState<TextpersonOption[]>([]);
    const [value, setValue] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let cancelled = false;
        fetch(`/api/select/search/textperson?q=${encodeURIComponent(String(personId))}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((json) => {
                if (cancelled) return;
                const rows: TextpersonOption[] = Array.isArray(json?.data)
                    ? json.data.map((d: Record<string, unknown>) => ({ value: String(d.value ?? ''), text: String(d.text ?? '') }))
                    : [];
                setOptions(rows.filter((o) => o.value));
            })
            .catch(() => { if (!cancelled) setOptions([]); });
        return () => { cancelled = true; };
    }, [personId]);

    const handleChange = async (v: string) => {
        setValue(v);
        if (!v) return;
        const parts = v.split('&and&');
        const textid = parts[0] ?? '';
        const pages = parts[1] ?? '';
        if (!textid) return;
        setBusy(true);
        try {
            const json = await fetch(`/api/select/search/text?q=${encodeURIComponent(textid)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            }).then((r) => r.json()).catch(() => null);
            const rows = Array.isArray(json?.data) ? json.data : [];
            const sourceLabel = rows.length ? String(rows[0].text ?? textid) : textid;
            onPick({ source: textid, pages, sourceLabel });
        } finally {
            setBusy(false);
        }
    };

    return (
        <div style={rowStyle}>
            <label style={labelStyle}>{resolvedLabel}</label>
            <div style={fieldStyle}>
                <select
                    value={value}
                    disabled={disabled || busy}
                    onChange={(e) => void handleChange(e.target.value)}
                    style={selectStyle}
                >
                    <option value="">{hint ?? t('textperson_pick_hint')}</option>
                    {options.map((o) => (
                        <option key={o.value} value={o.value}>{o.text}</option>
                    ))}
                </select>
            </div>
        </div>
    );
}

const rowStyle: React.CSSProperties = { display: 'flex', gap: 12, alignItems: 'flex-start', padding: '6px 0' };
const labelStyle: React.CSSProperties = { width: 160, flexShrink: 0, fontSize: '1rem', color: '#374151', paddingTop: 6 };
const fieldStyle: React.CSSProperties = { flex: 1, minWidth: 0 };
const selectStyle: React.CSSProperties = { width: '100%', height: 36, padding: '0 10px', borderRadius: 6, border: '1px solid #cbd5e1', fontSize: '1rem' };
