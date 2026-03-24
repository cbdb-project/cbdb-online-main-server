import React, { useState, useEffect, useCallback } from 'react';
import { router, usePage } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import EntryTypeTree, { EntryType } from '../../components/EntryTypeTree';
import EntryCodeList, { EntryCode } from '../../components/EntryCodeList';
import SelectedCodeChips from '../../components/SelectedCodeChips';
import SearchResultTable, { ResultRow, PaginationData } from '../../components/SearchResultTable';

interface PageProps {
    entryTypes: EntryType[];
    preloadedCodes: EntryCode[];
    results: {
        data: ResultRow[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    } | null;
    filters: {
        entry_codes: number[];
        year_from: number | null;
        year_to: number | null;
        addr_id: number | null;
        type_id: string | null;
    };
    errors: Record<string, string>;
    pageUrl: string;
    typesEndpoint: string;
    codesEndpoint: string;
    [key: string]: unknown;
}

export default function Index() {
    const { entryTypes, preloadedCodes, results, filters, errors, pageUrl, typesEndpoint, codesEndpoint } = usePage<PageProps>().props;

    // Local state
    const [selectedTypeId, setSelectedTypeId] = useState<string | null>(filters.type_id ?? null);
    const [availableCodes, setAvailableCodes] = useState<EntryCode[]>(preloadedCodes ?? []);
    const [selectedCodes, setSelectedCodes] = useState<number[]>(filters.entry_codes ?? []);
    const [yearFrom, setYearFrom] = useState<string>(filters.year_from != null ? String(filters.year_from) : '');
    const [yearTo, setYearTo] = useState<string>(filters.year_to != null ? String(filters.year_to) : '');
    const [addrId, setAddrId] = useState<string>(filters.addr_id != null ? String(filters.addr_id) : '');
    const [loadingTypes] = useState(false);
    const [typesError] = useState<string | null>(null);
    const [loadingCodes, setLoadingCodes] = useState(false);
    const [codesError, setCodesError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    // All known codes (for chips display)
    const [allKnownCodes, setAllKnownCodes] = useState<EntryCode[]>(preloadedCodes ?? []);

    // Update allKnownCodes when new codes load
    useEffect(() => {
        if (availableCodes.length > 0) {
            setAllKnownCodes((prev) => {
                const map = new Map(prev.map((c) => [c.c_entry_code, c]));
                availableCodes.forEach((c) => map.set(c.c_entry_code, c));
                return Array.from(map.values());
            });
        }
    }, [availableCodes]);

    // Load codes when type changes (client-side fetch)
    const loadCodes = useCallback(async (typeId: string) => {
        setLoadingCodes(true);
        setCodesError(null);
        try {
            const url = `${codesEndpoint}?type_id=${encodeURIComponent(typeId)}`;
            const res = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const json = await res.json();
            if (json.success) {
                setAvailableCodes(json.data);
            } else {
                setCodesError('載入失敗');
            }
        } catch {
            setCodesError('載入入仕代碼失敗');
        } finally {
            setLoadingCodes(false);
        }
    }, [codesEndpoint]);

    const handleTypeSelect = (typeId: string) => {
        setSelectedTypeId(typeId);
        loadCodes(typeId);
    };

    const handleCodeToggle = (code: number) => {
        setSelectedCodes((prev) =>
            prev.includes(code) ? prev.filter((c) => c !== code) : [...prev, code]
        );
    };

    const handleSelectAll = () => {
        setSelectedCodes((prev) => {
            const existing = new Set(prev);
            availableCodes.forEach((c) => existing.add(c.c_entry_code));
            return Array.from(existing);
        });
    };

    const handleDeselectAll = () => {
        const currentSet = new Set(availableCodes.map((c) => c.c_entry_code));
        setSelectedCodes((prev) => prev.filter((c) => !currentSet.has(c)));
    };

    const handleRemoveCode = (code: number) => {
        setSelectedCodes((prev) => prev.filter((c) => c !== code));
    };

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);

        const params: Record<string, unknown> = {};
        if (selectedCodes.length > 0) params.entry_codes = selectedCodes;
        if (yearFrom) params.year_from = yearFrom;
        if (yearTo) params.year_to = yearTo;
        if (addrId) params.addr_id = addrId;
        if (selectedTypeId) params.type_id = selectedTypeId;

        router.get(pageUrl, params as Record<string, string>, {
            preserveState: false,
            onFinish: () => setSubmitting(false),
        });
    };

    const handlePageChange = (page: number) => {
        const params: Record<string, unknown> = { page };
        if (selectedCodes.length > 0) params.entry_codes = selectedCodes;
        if (filters.year_from != null) params.year_from = filters.year_from;
        if (filters.year_to != null) params.year_to = filters.year_to;
        if (filters.addr_id != null) params.addr_id = filters.addr_id;
        if (filters.type_id) params.type_id = filters.type_id;

        router.get(pageUrl, params as Record<string, string>, {
            preserveState: false,
        });
    };

    const handleReset = () => {
        setSelectedTypeId(null);
        setAvailableCodes([]);
        setSelectedCodes([]);
        setYearFrom('');
        setYearTo('');
        setAddrId('');
        router.get(pageUrl, {}, { preserveState: false });
    };

    const hasFilters = selectedCodes.length > 0 || yearFrom || yearTo || addrId;
    const hasResults = results !== null;

    const pagination: PaginationData | null = results
        ? {
            current_page: results.current_page,
            last_page: results.last_page,
            per_page: results.per_page,
            total: results.total,
            from: results.from,
            to: results.to,
        }
        : null;

    return (
        <AppShell>
            <h2 style={{ marginTop: 0, marginBottom: 20, fontSize: '1.3rem', fontWeight: 600 }}>按入仕查詢</h2>

            {/* Three-column layout */}
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 16, marginBottom: 24 }}>
                {/* Column 1: Entry types */}
                <EntryTypeTree
                    types={entryTypes}
                    selectedTypeId={selectedTypeId}
                    loading={loadingTypes}
                    error={typesError}
                    onSelect={handleTypeSelect}
                />

                {/* Column 2: Entry codes */}
                <EntryCodeList
                    codes={availableCodes}
                    selectedCodes={selectedCodes}
                    loading={loadingCodes}
                    error={codesError}
                    onToggle={handleCodeToggle}
                    onSelectAll={handleSelectAll}
                    onDeselectAll={handleDeselectAll}
                />

                {/* Column 3: Search form */}
                <div style={{ border: '1px solid #dee2e6', borderRadius: 4, backgroundColor: '#fff', overflow: 'hidden' }}>
                    <div style={{ padding: '10px 14px', borderBottom: '1px solid #dee2e6', fontWeight: 600, fontSize: '0.95rem' }}>
                        搜尋條件
                    </div>
                    <div style={{ padding: 14 }}>
                        <form onSubmit={handleSearch}>
                            {/* Selected codes */}
                            <div style={{ marginBottom: 14 }}>
                                <label style={{ display: 'block', fontWeight: 500, fontSize: '0.85rem', marginBottom: 4 }}>
                                    已選擇的入仕代碼：
                                </label>
                                <SelectedCodeChips
                                    selectedCodes={selectedCodes}
                                    allCodes={allKnownCodes}
                                    onRemove={handleRemoveCode}
                                />
                            </div>

                            <hr style={{ border: 'none', borderTop: '1px solid #e9ecef', margin: '12px 0' }} />

                            {/* Year range */}
                            <div style={{ marginBottom: 14 }}>
                                <label style={{ display: 'block', fontWeight: 500, fontSize: '0.85rem', marginBottom: 4 }}>年份範圍</label>
                                <div style={{ display: 'flex', gap: 8 }}>
                                    <input
                                        type="number"
                                        value={yearFrom}
                                        onChange={(e) => setYearFrom(e.target.value)}
                                        placeholder="起始年"
                                        style={inputStyle}
                                    />
                                    <input
                                        type="number"
                                        value={yearTo}
                                        onChange={(e) => setYearTo(e.target.value)}
                                        placeholder="結束年"
                                        style={inputStyle}
                                    />
                                </div>
                                {errors.year_from && <div style={errorStyle}>{errors.year_from}</div>}
                                {errors.year_to && <div style={errorStyle}>{errors.year_to}</div>}
                                <div style={{ fontSize: '0.75rem', color: '#6c757d', marginTop: 2 }}>可留空表示不限制</div>
                            </div>

                            {/* Address ID */}
                            <div style={{ marginBottom: 14 }}>
                                <label style={{ display: 'block', fontWeight: 500, fontSize: '0.85rem', marginBottom: 4 }}>入仕地址 ID</label>
                                <input
                                    type="number"
                                    value={addrId}
                                    onChange={(e) => setAddrId(e.target.value)}
                                    placeholder="請輸入地址 ID"
                                    style={inputStyle}
                                />
                                {errors.addr_id && <div style={errorStyle}>{errors.addr_id}</div>}
                                <div style={{ fontSize: '0.75rem', color: '#6c757d', marginTop: 2 }}>可留空表示不限制</div>
                            </div>

                            <hr style={{ border: 'none', borderTop: '1px solid #e9ecef', margin: '12px 0' }} />

                            {/* Actions */}
                            <div style={{ display: 'flex', gap: 8 }}>
                                <button
                                    type="submit"
                                    disabled={submitting}
                                    style={{
                                        flex: 1,
                                        padding: '8px 16px',
                                        backgroundColor: '#007bff',
                                        color: '#fff',
                                        border: 'none',
                                        borderRadius: 4,
                                        cursor: submitting ? 'wait' : 'pointer',
                                        fontSize: '0.9rem',
                                        opacity: submitting ? 0.7 : 1,
                                    }}
                                >
                                    {submitting ? '搜尋中...' : '執行搜尋'}
                                </button>
                                <button
                                    type="button"
                                    onClick={handleReset}
                                    style={{
                                        padding: '8px 16px',
                                        backgroundColor: '#6c757d',
                                        color: '#fff',
                                        border: 'none',
                                        borderRadius: 4,
                                        cursor: 'pointer',
                                        fontSize: '0.9rem',
                                    }}
                                >
                                    重置
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {/* Condition summary & results */}
            {hasResults && (
                <div style={{ border: '1px solid #dee2e6', borderRadius: 4, backgroundColor: '#fff', padding: 16 }}>
                    {/* Condition summary */}
                    {hasFilters && (
                        <div style={{ padding: '10px 14px', backgroundColor: '#d1ecf1', border: '1px solid #bee5eb', borderRadius: 4, marginBottom: 16, fontSize: '0.85rem' }}>
                            <strong>搜尋條件：</strong>
                            {filters.entry_codes.length > 0 && (
                                <span style={{ marginLeft: 8 }}>
                                    入仕代碼：
                                    {filters.entry_codes.map((c) => (
                                        <span key={c} style={{ display: 'inline-block', padding: '1px 6px', backgroundColor: '#007bff', color: '#fff', borderRadius: 10, fontSize: '0.75rem', marginLeft: 4 }}>
                                            {c}
                                        </span>
                                    ))}
                                </span>
                            )}
                            {filters.year_from != null && <span style={{ marginLeft: 8 }}>起始年：{filters.year_from}</span>}
                            {filters.year_to != null && <span style={{ marginLeft: 8 }}>結束年：{filters.year_to}</span>}
                            {filters.addr_id != null && <span style={{ marginLeft: 8 }}>地址 ID：{filters.addr_id}</span>}
                        </div>
                    )}

                    <SearchResultTable
                        rows={results.data}
                        pagination={pagination}
                        onPageChange={handlePageChange}
                    />
                </div>
            )}
        </AppShell>
    );
}

const inputStyle: React.CSSProperties = {
    flex: 1,
    padding: '6px 10px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    fontSize: '0.875rem',
};

const errorStyle: React.CSSProperties = {
    color: '#dc3545',
    fontSize: '0.8rem',
    marginTop: 2,
};
