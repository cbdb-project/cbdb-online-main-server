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
    /**
     * #98 opt-in：當 value 為哨兵（''/'0'/'-9999'）且無已解析 label 時，以此文字（淡灰）顯示，
     * 讓使用者直觀知道「此欄已自動補預設值（如未詳）」。僅非主碼、可自動預設的碼欄才傳。
     */
    sentinelLabel?: string;
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
    // 快取鍵須含 idKey/labelKeys：同一 model 在不同元件用不同 idKey/labelKeys 時，
    // 否則先載入者的 value/label 組法會污染後者（#111）。
    const cacheKey = `${model}|${idKey}|${labelKeys.join(',')}`;
    if (!listCache.has(cacheKey)) {
        listCache.set(cacheKey, (async () => {
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
    return listCache.get(cacheKey)!;
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
    const { value, initialLabel, onChange, placeholder, disabled, id, sentinelLabel } = props;
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
    // search 模式用：避免舊查詢（如常見姓氏字首，結果多、後端較慢）晚於新查詢（字數更多、結果少）
    // 回應，導致 setOptions 被過期回應覆蓋、下拉顯示與目前輸入不符的候選（如誤留一筆不相干的舊結果）。
    const searchSeqRef = useRef(0);

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
        if (props.mode !== 'search') {
            return;
        }
        // 一進 effect（即使用者按鍵、開關下拉的當下）就立刻認領新序號，而非等 250ms debounce
        // 觸發才認領：這樣任何仍在飛行中的舊查詢回應，只要使用者已再次按鍵／關閉下拉，
        // 就立刻失效（seq 落後），不需等到下一次 debounce 真正送出新請求才失效——
        // 否則舊回應可能剛好在「使用者已打字但新請求尚未送出」的空窗期回來，覆蓋畫面。
        const seq = ++searchSeqRef.current;
        if (!open) {
            // 認領新 seq 已讓任何仍在飛行中的舊請求的 finally 失效（guard 擋下），
            // 這裡改由本次 effect 負責重置 loading，避免卡在「載入中」。
            setLoading(false);
            return;
        }
        if (debounceRef.current) {
            window.clearTimeout(debounceRef.current);
        }
        const q = query.trim();
        if (q === '') {
            setOptions([]);
            setLoading(false);
            return;
        }
        debounceRef.current = window.setTimeout(() => {
            setLoading(true);
            setError(null);
            fetchSearch((props as SearchProps).endpoint, q, (props as SearchProps).extraQuery)
                .then((opts) => { if (seq === searchSeqRef.current) setOptions(opts); })
                .catch((e) => { if (seq === searchSeqRef.current) setError(e instanceof Error ? e.message : '查詢失敗'); })
                .finally(() => { if (seq === searchSeqRef.current) setLoading(false); });
        }, 250);
        return () => {
            if (debounceRef.current) {
                window.clearTimeout(debounceRef.current);
            }
            // 防禦性處理：若 mode 從 'search' 切換離開（目前無呼叫端會動態切換，但避免未來新增
            // 用法時遺漏），讓本次仍在飛行中的 search 請求跟著失效，不讓其回應影響切換後的狀態。
            ++searchSeqRef.current;
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

    // #98：value 為哨兵且無已解析 label 時，顯示 sentinelLabel（淡灰），表示「已自動補預設值」。
    const isSentinelValue = value === '' || value === '0' || value === '-9999';
    const showingSentinel = !open && !selectedLabel && !!sentinelLabel && isSentinelValue;
    const displayText = open ? query : (selectedLabel || (showingSentinel ? sentinelLabel! : ''));

    return (
        <div ref={containerRef} style={wrapStyle}>
            <input
                className="cbdb-historical-text"
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
                // 編輯器根節點現為 <form>（支援 Enter 保存）：下拉開啟時本元件無「目前反白候選」概念，
                // Enter 若不攔截會被表單當成隱式提交，使用者尚未點選候選就誤觸儲存。開啟中吞掉 Enter，
                // 迫使使用者以滑鼠點選候選、或按 Esc 關閉下拉後再以 Enter 送出表單。
                // isComposing 排除：中文輸入法選字用的 Enter 屬合成事件，不可攔截，否則無法確認候選字。
                onKeyDown={(e) => {
                    // keyCode 229 後備判斷：部分瀏覽器/輸入法組合在確認字詞當下 isComposing 已回報 false。
                    if (e.nativeEvent.isComposing || e.keyCode === 229) return;
                    if (e.key === 'Escape' && open) {
                        e.preventDefault();
                        setOpen(false);
                        return;
                    }
                    if (e.key === 'Enter' && open) {
                        e.preventDefault();
                    }
                }}
                style={{
                    ...inputStyle,
                    ...(ariaInvalid ? invalidStyle : {}),
                    ...(showingSentinel ? { color: 'var(--muted-foreground)' } : {}),
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
                    {error ? <div style={{ ...hintStyle, color: 'var(--destructive)' }}>{error}</div> : null}
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
    border: '1px solid var(--input)',
    fontSize: '1rem',
    boxSizing: 'border-box',
    background: 'var(--background)',
    color: 'var(--foreground)',
};

const invalidStyle: React.CSSProperties = {
    borderColor: 'var(--destructive)',
    boxShadow: '0 0 0 1px var(--destructive)',
};

const clearBtnStyle: React.CSSProperties = {
    position: 'absolute',
    right: 6,
    top: 6,
    width: 22,
    height: 22,
    border: 'none',
    background: 'transparent',
    color: 'var(--muted-foreground)',
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
    backgroundColor: 'var(--popover)',
    color: 'var(--popover-foreground)',
    border: '1px solid var(--border)',
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
    fontSize: '1rem',
    color: 'var(--popover-foreground)',
};

const selectedOptionStyle: React.CSSProperties = {
    backgroundColor: 'var(--accent)',
    fontWeight: 600,
};

const hintStyle: React.CSSProperties = {
    padding: '8px 10px',
    fontSize: '0.9rem',
    color: 'var(--muted-foreground)',
};
