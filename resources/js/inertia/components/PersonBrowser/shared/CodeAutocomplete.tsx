import React, { useEffect, useMemo, useRef, useState } from 'react';

/**
 * 可複用的「代碼選擇」元件。
 *
 * 兩種模式：
 *  - mode="list"：一次載入整份代碼表（同步 endpoint，如 /api/select/altcode），
 *    在前端做關鍵字過濾。對應 BasicInfoView 的 fetchEnumOptions 邏輯。
 *  - mode="search"：依使用者輸入向後端 async 查詢（如 /api/select/search/text），
 *    回傳 Laravel paginate 結構 `{ data: [{ id|value, text }] }`。
 *
 * 兩種模式都回傳 `value`（字串）給呼叫端；顯示則用 label。
 */

export interface CodeOption {
    value: string;
    label: string;
}

interface CommonProps {
    /** 目前已選值（代碼）。 */
    value: string;
    /** 已選項目的顯示文字（編輯既有資料時用於初始顯示）。 */
    initialLabel?: string | null;
    onChange: (value: string, label: string) => void;
    placeholder?: string;
    disabled?: boolean;
    id?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
}

interface ListProps extends CommonProps {
    mode: 'list';
    /** /api/select/{model} 的 model 名（例：altcode）。 */
    model: string;
    /** 該 model 的代碼欄位名（用於從回傳物件取 value）。 */
    idKey: string;
    /** 從回傳物件組出顯示 label。 */
    labelKeys: string[];
}

interface SearchProps extends CommonProps {
    mode: 'search';
    /** async 查詢 endpoint（例：/api/select/search/text）。 */
    endpoint: string;
    /** 額外查詢參數（例：addr 的朝代範圍 dy_start/dy_end）。 */
    extraQuery?: Record<string, string>;
}

type Props = ListProps | SearchProps;

const listCache = new Map<string, Promise<CodeOption[]>>();

async function fetchList(model: string, idKey: string, labelKeys: string[]): Promise<CodeOption[]> {
    if (!listCache.has(model)) {
        listCache.set(model, (async () => {
            const response = await fetch(`/api/select/${model}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error(`載入選項失敗（${model}）`);
            }
            const data = await response.json();
            if (!Array.isArray(data)) {
                return [];
            }
            return data
                .map((item: Record<string, unknown>): CodeOption | null => {
                    const raw = item[idKey];
                    if (raw == null) {
                        return null;
                    }
                    const label = labelKeys
                        .map((k) => (item[k] == null ? '' : String(item[k]).trim()))
                        .filter((s) => s !== '')
                        .join(' ')
                        .trim();
                    return { value: String(raw), label: label || String(raw) };
                })
                .filter(Boolean) as CodeOption[];
        })());
    }
    return listCache.get(model)!;
}

async function fetchSearch(endpoint: string, q: string, extraQuery: Record<string, string> = {}): Promise<CodeOption[]> {
    const params = new URLSearchParams({ q });
    for (const [k, v] of Object.entries(extraQuery)) {
        if (v !== undefined && v !== null && v !== '') params.set(k, v);
    }
    const sep = endpoint.includes('?') ? '&' : '?';
    const url = `${endpoint}${sep}${params.toString()}`;
    const response = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!response.ok) {
        throw new Error('查詢失敗');
    }
    const json = await response.json();
    const rows: Array<Record<string, unknown>> = Array.isArray(json?.data) ? json.data : Array.isArray(json) ? json : [];
    return rows
        .map((item): CodeOption | null => {
            const raw = item.id ?? item.value;
            if (raw == null) {
                return null;
            }
            const label = item.text != null ? String(item.text) : String(raw);
            return { value: String(raw), label };
        })
        .filter(Boolean) as CodeOption[];
}

export default function CodeAutocomplete(props: Props) {
    const { value, initialLabel, onChange, placeholder, disabled, id } = props;
    const ariaInvalid = props['aria-invalid'];
    const ariaDescribedBy = props['aria-describedby'];

    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [options, setOptions] = useState<CodeOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [selectedLabel, setSelectedLabel] = useState<string>(initialLabel ?? '');
    const containerRef = useRef<HTMLDivElement>(null);
    const debounceRef = useRef<number | null>(null);

    // 同步 initialLabel（編輯既有資料時）。
    useEffect(() => {
        setSelectedLabel(initialLabel ?? '');
    }, [initialLabel]);

    // list 模式：載入整份清單；並嘗試補上已選值的 label。
    useEffect(() => {
        if (props.mode !== 'list') {
            return;
        }
        let cancelled = false;
        setLoading(true);
        fetchList(props.model, props.idKey, props.labelKeys)
            .then((opts) => {
                if (cancelled) return;
                setOptions(opts);
                if (value) {
                    const match = opts.find((o) => o.value === String(value));
                    if (match) {
                        setSelectedLabel(match.label);
                    }
                }
            })
            .catch((e) => !cancelled && setError(e instanceof Error ? e.message : '載入失敗'))
            .finally(() => !cancelled && setLoading(false));
        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [props.mode, value, (props as ListProps).model]);

    // search 模式：依輸入 debounce 查詢。
    useEffect(() => {
        if (props.mode !== 'search' || !open) {
            return;
        }
        if (debounceRef.current) {
            window.clearTimeout(debounceRef.current);
        }
        const q = query.trim();
        if (q === '') {
            setOptions([]);
            return;
        }
        debounceRef.current = window.setTimeout(() => {
            setLoading(true);
            setError(null);
            fetchSearch((props as SearchProps).endpoint, q, (props as SearchProps).extraQuery)
                .then(setOptions)
                .catch((e) => setError(e instanceof Error ? e.message : '查詢失敗'))
                .finally(() => setLoading(false));
        }, 250);
        return () => {
            if (debounceRef.current) {
                window.clearTimeout(debounceRef.current);
            }
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query, open, props.mode]);

    // 點擊外部關閉下拉。
    useEffect(() => {
        const onDocClick = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, []);

    const filtered = useMemo(() => {
        if (props.mode === 'search') {
            return options;
        }
        const q = query.trim().toLowerCase();
        if (!q) {
            return options;
        }
        return options.filter((o) => `${o.value} ${o.label}`.toLowerCase().includes(q));
    }, [options, query, props.mode]);

    const handleSelect = (opt: CodeOption) => {
        setSelectedLabel(opt.label);
        setQuery('');
        setOpen(false);
        onChange(opt.value, opt.label);
    };

    const handleClear = () => {
        setSelectedLabel('');
        setQuery('');
        onChange('', '');
    };

    const displayText = open ? query : selectedLabel;

    return (
        <div ref={containerRef} style={wrapStyle}>
            <input
                id={id}
                type="text"
                disabled={disabled}
                value={displayText}
                placeholder={placeholder}
                aria-invalid={ariaInvalid}
                aria-describedby={ariaDescribedBy}
                onFocus={() => setOpen(true)}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setOpen(true);
                }}
                style={{
                    ...inputStyle,
                    ...(ariaInvalid ? invalidStyle : {}),
                }}
            />
            {value && !disabled ? (
                <button type="button" onClick={handleClear} style={clearBtnStyle} aria-label="clear" tabIndex={-1}>
                    ×
                </button>
            ) : null}
            {open ? (
                <div style={dropdownStyle}>
                    {loading ? <div style={hintStyle}>載入中…</div> : null}
                    {error ? <div style={{ ...hintStyle, color: '#dc3545' }}>{error}</div> : null}
                    {!loading && !error && filtered.length === 0 ? (
                        <div style={hintStyle}>{props.mode === 'search' && !query.trim() ? '請輸入關鍵字' : '無符合項目'}</div>
                    ) : null}
                    {filtered.map((opt) => (
                        <button
                            key={opt.value}
                            type="button"
                            onClick={() => handleSelect(opt)}
                            style={{
                                ...optionStyle,
                                ...(opt.value === String(value) ? selectedOptionStyle : {}),
                            }}
                        >
                            {opt.label}
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

const wrapStyle: React.CSSProperties = { position: 'relative', width: '100%' };

const inputStyle: React.CSSProperties = {
    width: '100%',
    height: 36,
    padding: '0 28px 0 10px',
    borderRadius: 6,
    border: '1px solid #cbd5e1',
    fontSize: '1rem',
    boxSizing: 'border-box',
};

const invalidStyle: React.CSSProperties = {
    borderColor: '#dc3545',
    boxShadow: '0 0 0 1px #dc3545',
};

const clearBtnStyle: React.CSSProperties = {
    position: 'absolute',
    right: 6,
    top: 6,
    width: 22,
    height: 22,
    border: 'none',
    background: 'transparent',
    color: '#94a3b8',
    cursor: 'pointer',
    fontSize: 16,
    lineHeight: '22px',
};

const dropdownStyle: React.CSSProperties = {
    position: 'absolute',
    top: '100%',
    left: 0,
    right: 0,
    zIndex: 60,
    marginTop: 2,
    maxHeight: 220,
    overflowY: 'auto',
    backgroundColor: '#fff',
    border: '1px solid #cbd5e1',
    borderRadius: 6,
    boxShadow: '0 6px 18px rgba(15, 23, 42, 0.12)',
};

const optionStyle: React.CSSProperties = {
    display: 'block',
    width: '100%',
    textAlign: 'left',
    padding: '8px 10px',
    border: 'none',
    background: 'transparent',
    cursor: 'pointer',
    fontSize: '0.85rem',
    color: '#1f2937',
};

const selectedOptionStyle: React.CSSProperties = {
    backgroundColor: '#eef4fb',
    fontWeight: 600,
};

const hintStyle: React.CSSProperties = {
    padding: '8px 10px',
    fontSize: '0.8rem',
    color: '#64748b',
};
