import React, { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useTranslation } from '../../hooks/useTranslation';
import AppShell from '../../Layouts/AppShell';
import EntryCodeList, { EntryCode } from '../../components/EntryCodeList';
import EntryPeopleTable, { EntryPersonRow } from '../../components/EntryPeopleTable';
import EntryTypeTree, { EntryType } from '../../components/EntryTypeTree';
import PlaceMultiSelect, { PlaceOption } from '../../components/PlaceMultiSelect';
import SearchResultTable, { ResultRow } from '../../components/SearchResultTable';
import SelectedCodeChips from '../../components/SelectedCodeChips';
import SelectionDialog from '../../components/SelectionDialog';
import { PaginationData } from '../../components/PaginationControls';

interface DynastyOption {
    c_dy: string;
    c_dynasty: string | null;
    c_dynasty_chn: string | null;
    c_start: number | null;
    c_end: number | null;
}

interface FiltersState {
    person_keyword: string;
    type_id: string | null;
    entry_codes: number[];
    place_ids: number[];
    include_sub_units: boolean;
    use_index_year_range: boolean;
    index_year_from: number | null;
    index_year_to: number | null;
    use_entry_year_range: boolean;
    entry_year_from: number | null;
    entry_year_to: number | null;
    dynasty_codes: string[];
}

interface QueryResultState {
    records: PaginationData<ResultRow>;
    people: PaginationData<EntryPersonRow>;
    summary: {
        record_count: number;
        person_count: number;
        selected_entry_code_count: number;
        selected_place_count: number;
    };
}

interface PageProps {
    entryTypes: EntryType[];
    dynasties: DynastyOption[];
    preloadedCodes: EntryCode[];
    preloadedPlaces: PlaceOption[];
    initialFilters: {
        person_keyword: string | null;
        type_id: string | null;
        entry_codes: number[];
        place_ids: number[];
        include_sub_units: boolean;
        use_index_year_range: boolean;
        index_year_from: number | null;
        index_year_to: number | null;
        use_entry_year_range: boolean;
        entry_year_from: number | null;
        entry_year_to: number | null;
        dynasty_codes: string[];
    };
    pageUrl: string;
    codesEndpoint: string;
    placesEndpoint: string;
    queryEndpoint: string;
}

type ActiveDialog = 'entry' | 'personKeyword' | 'places' | 'indexYear' | 'entryYear' | 'dynasty' | null;

export default function Index() {
    const {
        entryTypes,
        dynasties,
        preloadedCodes,
        preloadedPlaces,
        initialFilters,
        pageUrl,
        codesEndpoint,
        placesEndpoint,
        queryEndpoint,
    } = usePage<PageProps>().props;

    const t = useTranslation('person');
    const tCommon = useTranslation('common');

    const [filters, setFilters] = useState<FiltersState>({
        person_keyword: initialFilters.person_keyword ?? '',
        type_id: initialFilters.type_id ?? null,
        entry_codes: initialFilters.entry_codes ?? [],
        place_ids: initialFilters.place_ids ?? [],
        include_sub_units: initialFilters.include_sub_units ?? false,
        use_index_year_range: initialFilters.use_index_year_range ?? false,
        index_year_from: initialFilters.index_year_from,
        index_year_to: initialFilters.index_year_to,
        use_entry_year_range: initialFilters.use_entry_year_range ?? false,
        entry_year_from: initialFilters.entry_year_from,
        entry_year_to: initialFilters.entry_year_to,
        dynasty_codes: initialFilters.dynasty_codes ?? [],
    });
    const [availableCodes, setAvailableCodes] = useState<EntryCode[]>(preloadedCodes ?? []);
    const [allKnownCodes, setAllKnownCodes] = useState<EntryCode[]>(preloadedCodes ?? []);
    const [selectedPlaces, setSelectedPlaces] = useState<PlaceOption[]>(preloadedPlaces ?? []);
    const [placeQuery, setPlaceQuery] = useState('');
    const [placeResults, setPlaceResults] = useState<PlaceOption[]>([]);
    const [activeTab, setActiveTab] = useState<'records' | 'people'>('records');
    const [results, setResults] = useState<QueryResultState | null>(null);
    const [queryErrors, setQueryErrors] = useState<Record<string, string[]>>({});
    const [resultsError, setResultsError] = useState<string | null>(null);
    const [loadingCodes, setLoadingCodes] = useState(false);
    const [loadingPlaces, setLoadingPlaces] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [activeDialog, setActiveDialog] = useState<ActiveDialog>(null);
    const initialQueryRan = useRef(false);
    const shouldFocusResultsRef = useRef(false);
    const resultsSectionRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        if (availableCodes.length === 0) {
            return;
        }

        setAllKnownCodes((previous) => {
            const map = new Map(previous.map((code) => [code.c_entry_code, code]));
            availableCodes.forEach((code) => map.set(code.c_entry_code, code));
            return Array.from(map.values()).sort((left, right) => left.c_entry_code - right.c_entry_code);
        });
    }, [availableCodes]);

    useEffect(() => {
        if (!filters.type_id || availableCodes.length > 0) {
            return;
        }

        void loadCodes(filters.type_id);
    }, []);

    useEffect(() => {
        if (placeQuery.trim() === '') {
            setPlaceResults([]);
            setLoadingPlaces(false);
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoadingPlaces(true);
            try {
                const response = await fetch(`${placesEndpoint}?${buildQueryString({ q: placeQuery, limit: 20 })}`, {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const payload = await response.json();
                setPlaceResults(payload.data ?? []);
            } catch (error) {
                if (!controller.signal.aborted) {
                    setPlaceResults([]);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setLoadingPlaces(false);
                }
            }
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [placeQuery, placesEndpoint]);

    useEffect(() => {
        if (initialQueryRan.current) {
            return;
        }

        initialQueryRan.current = true;

        if (!hasAnyCondition(filters)) {
            return;
        }

        void runQuery({ records_page: 1, people_page: 1 }, false);
    }, []);

    useEffect(() => {
        if (!results || !shouldFocusResultsRef.current) {
            return;
        }

        shouldFocusResultsRef.current = false;
        window.requestAnimationFrame(() => {
            resultsSectionRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        });
    }, [results]);

    async function loadCodes(typeId: string) {
        setLoadingCodes(true);

        try {
            const response = await fetch(`${codesEndpoint}?${buildQueryString({ type_id: typeId })}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            setAvailableCodes(payload.data ?? []);
        } catch (error) {
            setAvailableCodes([]);
        } finally {
            setLoadingCodes(false);
        }
    }

    function updateFilter<K extends keyof FiltersState>(key: K, value: FiltersState[K]) {
        setFilters((previous) => ({
            ...previous,
            [key]: value,
        }));
    }

    function handleTypeSelect(typeId: string) {
        setFilters((previous) => ({
            ...previous,
            type_id: typeId,
            entry_codes: [],
        }));
        setAvailableCodes([]);
        void loadCodes(typeId);
    }

    function handleClearType() {
        setFilters((previous) => ({
            ...previous,
            type_id: null,
            entry_codes: [],
        }));
        setAvailableCodes([]);
    }

    function handleDynastyToggle(code: string) {
        setFilters((previous) => ({
            ...previous,
            dynasty_codes: previous.dynasty_codes.includes(code)
                ? previous.dynasty_codes.filter((existing) => existing !== code)
                : [...previous.dynasty_codes, code].sort(),
        }));
    }

    function handleCodeToggle(code: number) {
        setFilters((previous) => ({
            ...previous,
            entry_codes: previous.entry_codes.includes(code)
                ? previous.entry_codes.filter((existing) => existing !== code)
                : [...previous.entry_codes, code].sort((left, right) => left - right),
        }));
    }

    function handleSelectAllCodes() {
        setFilters((previous) => {
            const codes = new Set(previous.entry_codes);
            availableCodes.forEach((code) => codes.add(code.c_entry_code));

            return {
                ...previous,
                entry_codes: Array.from(codes).sort((left, right) => left - right),
            };
        });
    }

    function handleDeselectAllCodes() {
        const visibleCodes = new Set(availableCodes.map((code) => code.c_entry_code));

        setFilters((previous) => ({
            ...previous,
            entry_codes: previous.entry_codes.filter((code) => !visibleCodes.has(code)),
        }));
    }

    function handleRemoveCode(code: number) {
        setFilters((previous) => ({
            ...previous,
            entry_codes: previous.entry_codes.filter((existing) => existing !== code),
        }));
    }

    function handleAddPlace(place: PlaceOption) {
        setSelectedPlaces((previous) => {
            if (previous.some((item) => item.c_addr_id === place.c_addr_id)) {
                return previous;
            }

            const next = [...previous, place];
            updateFilter('place_ids', next.map((item) => item.c_addr_id));
            return next;
        });
    }

    function handleRemovePlace(placeId: number) {
        setSelectedPlaces((previous) => {
            const next = previous.filter((place) => place.c_addr_id !== placeId);
            updateFilter('place_ids', next.map((place) => place.c_addr_id));
            return next;
        });
    }

    function handleClearPlaces() {
        setSelectedPlaces([]);
        updateFilter('place_ids', []);
    }

    function handleReset() {
        setFilters({
            person_keyword: '',
            type_id: null,
            entry_codes: [],
            place_ids: [],
            include_sub_units: false,
            use_index_year_range: false,
            index_year_from: null,
            index_year_to: null,
            use_entry_year_range: false,
            entry_year_from: null,
            entry_year_to: null,
            dynasty_codes: [],
        });
        setAvailableCodes([]);
        setSelectedPlaces([]);
        setPlaceQuery('');
        setPlaceResults([]);
        setResults(null);
        setResultsError(null);
        setQueryErrors({});
        setActiveDialog(null);
        window.history.replaceState(null, '', pageUrl);
    }

    async function runQuery(overrides: { records_page?: number; people_page?: number } = {}, switchToRecords = true) {
        const payload = buildRequestPayload(filters, selectedPlaces, overrides);

        setSubmitting(true);
        setResultsError(null);
        setQueryErrors({});

        if (switchToRecords) {
            setActiveTab('records');
        }

        try {
            const queryString = buildQueryString(payload);
            window.history.replaceState(null, '', queryString ? `${pageUrl}?${queryString}` : pageUrl);

            const response = await fetch(`${queryEndpoint}?${queryString}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();

            if (!response.ok) {
                setResults(null);
                setResultsError(data.message ?? '查詢失敗');
                setQueryErrors(data.errors ?? {});
                return;
            }

            shouldFocusResultsRef.current = true;
            setResults(data.data);
        } catch (error) {
            setResults(null);
            setResultsError('查詢時發生錯誤，請稍後再試。');
        } finally {
            setSubmitting(false);
        }
    }

    const selectedTypeLabel = findSelectedTypeLabel(entryTypes, filters.type_id);
    const errorMessages = collectErrorMessages(queryErrors);
    const displayErrorMessages = errorMessages.filter((message) => message !== resultsError);
    const selectedCodePreview = formatCodePreview(filters.entry_codes, allKnownCodes);
    const selectedPlacePreview = formatPlacePreview(selectedPlaces, filters.include_sub_units);
    const selectedDynastyPreview = formatDynastyPreview(filters.dynasty_codes, dynasties);

    return (
        <AppShell>
            <div style={workspaceStyle}>
                <div>
                    <h2 style={{ margin: 0, fontSize: '1.55rem', fontWeight: 700 }}>按入仕查詢</h2>
                </div>

                <div style={workspaceSummaryCardStyle}>
                    <div style={{ display: 'grid', gap: 12 }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
                                <div style={{ fontWeight: 700, fontSize: '1rem' }}>目前篩選摘要</div>
                                <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                                    <button
                                        type="button"
                                        onClick={() => void runQuery({ records_page: 1, people_page: 1 })}
                                        disabled={submitting}
                                        style={toolbarPrimaryButtonStyle(submitting)}
                                    >
                                        {submitting ? tCommon('loading') : tCommon('search')}
                                    </button>
                                <button type="button" onClick={handleReset} style={toolbarSecondaryButtonStyle}>
                                    {tCommon('reset')}
                                </button>
                            </div>
                        </div>
                        <div style={summaryGridStyle}>
                            <FilterSummaryTile
                                label="入仕類型與代碼"
                                value={selectedTypeLabel || '不限'}
                                detail={selectedCodePreview}
                                size="large"
                                onClick={() => setActiveDialog('entry')}
                            />
                            <FilterSummaryTile
                                label="人物關鍵字"
                                value={filters.person_keyword || '不限'}
                                onClick={() => setActiveDialog('personKeyword')}
                            />
                            <FilterSummaryTile
                                label="地點"
                                value={selectedPlaces.length > 0 ? `${selectedPlaces.length} 項` : '不限'}
                                detail={selectedPlacePreview}
                                size="large"
                                onClick={() => setActiveDialog('places')}
                            />
                            <FilterSummaryTile
                                label="指數年"
                                value={filters.use_index_year_range ? `${filters.index_year_from ?? '-200'} 至 ${filters.index_year_to ?? '1911'}` : '不限'}
                                onClick={() => setActiveDialog('indexYear')}
                            />
                            <FilterSummaryTile
                                label="入仕年"
                                value={filters.use_entry_year_range ? `${filters.entry_year_from ?? '-200'} 至 ${filters.entry_year_to ?? '1911'}` : '不限'}
                                onClick={() => setActiveDialog('entryYear')}
                            />
                            <FilterSummaryTile
                                label="朝代"
                                value={filters.dynasty_codes.length > 0 ? `${filters.dynasty_codes.length} 項` : '不限'}
                                detail={selectedDynastyPreview}
                                onClick={() => setActiveDialog('dynasty')}
                            />
                        </div>
                    </div>
                </div>

                {(resultsError || errorMessages.length > 0) && (
                    <div style={workspaceErrorStackStyle}>
                        {resultsError && <div style={errorBoxStyle}>{resultsError}</div>}
                        {displayErrorMessages.map((message) => (
                            <div key={message} style={errorBoxStyle}>{message}</div>
                        ))}
                    </div>
                )}

                <div ref={resultsSectionRef} style={resultsWorkspaceStyle}>
                    <div style={{ ...panelHeaderStyle, display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 8 }}>
                        <span>查詢結果工作區</span>
                        {results && (
                            <span style={{ color: '#6c757d', fontSize: '0.82rem', fontWeight: 500 }}>
                                記錄 {results.summary.record_count} 筆 / 人物 {results.summary.person_count} 位
                            </span>
                        )}
                    </div>
                    <div style={{ padding: 16, display: 'grid', gap: 14 }}>
                        {!results && !resultsError && (
                            <div style={{ color: '#6c757d', fontSize: '0.92rem' }}>
                                先在上方設定條件；每一格都可以直接點開 modal 編輯，再執行查詢。
                            </div>
                        )}

                        {results && (
                            <>
                                <div style={tabStripStyle}>
                                    <button type="button" onClick={() => setActiveTab('records')} style={tabButtonStyle(activeTab === 'records')}>
                                        入仕記錄
                                    </button>
                                    <button type="button" onClick={() => setActiveTab('people')} style={tabButtonStyle(activeTab === 'people')}>
                                        人物摘要
                                    </button>
                                </div>

                                <div style={resultsViewportStyle}>
                                    {activeTab === 'records' ? (
                                        <SearchResultTable
                                            rows={results.records.data}
                                            pagination={results.records}
                                            onPageChange={(page) => void runQuery({ records_page: page, people_page: results.people.current_page }, false)}
                                        />
                                    ) : (
                                        <EntryPeopleTable
                                            rows={results.people.data}
                                            pagination={results.people}
                                            onPageChange={(page) => void runQuery({ records_page: results.records.current_page, people_page: page }, false)}
                                        />
                                    )}
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>

            <SelectionDialog
                isOpen={activeDialog === 'entry'}
                title="選擇入仕類型與代碼"
                description="先選類型，再勾選代碼；右側摘要會即時顯示目前已選內容。"
                width={1240}
                onClose={() => setActiveDialog(null)}
                footer={(
                    <div style={dialogFooterStyle}>
                        <button
                            type="button"
                            onClick={handleClearType}
                            disabled={!filters.type_id && filters.entry_codes.length === 0}
                            style={dialogSecondaryButtonStyle}
                        >
                            清空選擇
                        </button>
                        <button type="button" onClick={() => setActiveDialog(null)} style={dialogPrimaryButtonStyle}>
                            完成
                        </button>
                    </div>
                )}
            >
                <div style={entrySelectionDialogGridStyle}>
                    <EntryTypeTree
                        types={entryTypes}
                        selectedTypeId={filters.type_id}
                        loading={false}
                        error={null}
                        onSelect={handleTypeSelect}
                    />
                    <EntryCodeList
                        codes={availableCodes}
                        selectedCodes={filters.entry_codes}
                        loading={loadingCodes}
                        error={null}
                        onToggle={handleCodeToggle}
                        onSelectAll={handleSelectAllCodes}
                        onDeselectAll={handleDeselectAllCodes}
                    />
                    <div style={entrySelectionSummaryPanelStyle}>
                        <div style={panelHeaderStyle}>目前已選</div>
                        <div style={{ padding: 16, display: 'grid', gap: 14 }}>
                            <div>
                                <div style={selectionCaptionStyle}>入仕類型</div>
                                <div style={selectionValueStyle}>{selectedTypeLabel || '尚未選擇'}</div>
                            </div>
                            <div>
                                <div style={selectionCaptionStyle}>入仕代碼</div>
                                <SelectedCodeChips
                                    selectedCodes={filters.entry_codes}
                                    allCodes={allKnownCodes}
                                    onRemove={handleRemoveCode}
                                />
                            </div>
                            <div style={modalHintBoxStyle}>
                                {loadingCodes
                                    ? '正在載入該類型的代碼...'
                                    : (filters.type_id
                                        ? `目前已選 ${filters.entry_codes.length} 項代碼`
                                        : '請先選擇入仕類型，再到中間勾選代碼。')}
                            </div>
                        </div>
                    </div>
                </div>
            </SelectionDialog>

            <SelectionDialog
                isOpen={activeDialog === 'personKeyword'}
                title="設定人物關鍵字"
                description="會比對人物姓名與 ALTNAME_DATA 異名。"
                width={560}
                onClose={() => setActiveDialog(null)}
                footer={(
                    <div style={dialogFooterStyle}>
                        <button
                            type="button"
                            onClick={() => updateFilter('person_keyword', '')}
                            disabled={filters.person_keyword.trim() === ''}
                            style={dialogSecondaryButtonStyle}
                        >
                            清空關鍵字
                        </button>
                        <button type="button" onClick={() => setActiveDialog(null)} style={dialogPrimaryButtonStyle}>
                            完成
                        </button>
                    </div>
                )}
            >
                <div style={{ display: 'grid', gap: 10 }}>
                    <label style={labelStyle}>人物關鍵字</label>
                    <input
                        type="text"
                        value={filters.person_keyword}
                        onChange={(event) => updateFilter('person_keyword', event.target.value)}
                        placeholder="姓名、別名或人物關鍵字"
                        style={textInputStyle}
                    />
                    <div style={hintStyle}>留空表示不限。</div>
                </div>
            </SelectionDialog>

            <SelectionDialog
                isOpen={activeDialog === 'places'}
                title="選擇入仕地點"
                description="可多選地點，搜尋結果會比對 ENTRY_DATA.c_entry_addr_id。"
                width={860}
                onClose={() => setActiveDialog(null)}
                footer={(
                    <div style={dialogFooterStyle}>
                        <button
                            type="button"
                            onClick={handleClearPlaces}
                            disabled={selectedPlaces.length === 0}
                            style={dialogSecondaryButtonStyle}
                        >
                            清空地點
                        </button>
                        <button type="button" onClick={() => setActiveDialog(null)} style={dialogPrimaryButtonStyle}>
                            完成
                        </button>
                    </div>
                )}
            >
                <PlaceMultiSelect
                    query={placeQuery}
                    selectedPlaces={selectedPlaces}
                    searchResults={placeResults}
                    loading={loadingPlaces}
                    includeSubUnits={filters.include_sub_units}
                    onQueryChange={setPlaceQuery}
                    onAddPlace={handleAddPlace}
                    onRemovePlace={handleRemovePlace}
                    onClearPlaces={handleClearPlaces}
                    onToggleIncludeSubUnits={(checked) => updateFilter('include_sub_units', checked)}
                />
            </SelectionDialog>

            <SelectionDialog
                isOpen={activeDialog === 'indexYear'}
                title="設定指數年範圍"
                width={620}
                onClose={() => setActiveDialog(null)}
                footer={(
                    <div style={dialogFooterStyle}>
                        <button
                            type="button"
                            onClick={() => {
                                updateFilter('use_index_year_range', false);
                                updateFilter('index_year_from', null);
                                updateFilter('index_year_to', null);
                            }}
                            disabled={!filters.use_index_year_range && filters.index_year_from === null && filters.index_year_to === null}
                            style={dialogSecondaryButtonStyle}
                        >
                            清空指數年
                        </button>
                        <button type="button" onClick={() => setActiveDialog(null)} style={dialogPrimaryButtonStyle}>
                            完成
                        </button>
                    </div>
                )}
            >
                <div style={{ display: 'grid', gap: 14 }}>
                    <label style={checkboxLabelStyle}>
                        <input
                            type="checkbox"
                            checked={filters.use_index_year_range}
                            onChange={(event) => updateFilter('use_index_year_range', event.target.checked)}
                        />
                        啟用指數年範圍
                    </label>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <input
                            type="number"
                            value={filters.index_year_from ?? ''}
                            onChange={(event) => updateFilter('index_year_from', normalizeNumberInput(event.target.value))}
                            disabled={!filters.use_index_year_range}
                            placeholder="-200"
                            style={numberInputStyle}
                        />
                        <input
                            type="number"
                            value={filters.index_year_to ?? ''}
                            onChange={(event) => updateFilter('index_year_to', normalizeNumberInput(event.target.value))}
                            disabled={!filters.use_index_year_range}
                            placeholder="1911"
                            style={numberInputStyle}
                        />
                    </div>
                </div>
            </SelectionDialog>

            <SelectionDialog
                isOpen={activeDialog === 'entryYear'}
                title="設定入仕年範圍"
                width={620}
                onClose={() => setActiveDialog(null)}
                footer={(
                    <div style={dialogFooterStyle}>
                        <button
                            type="button"
                            onClick={() => {
                                updateFilter('use_entry_year_range', false);
                                updateFilter('entry_year_from', null);
                                updateFilter('entry_year_to', null);
                            }}
                            disabled={!filters.use_entry_year_range && filters.entry_year_from === null && filters.entry_year_to === null}
                            style={dialogSecondaryButtonStyle}
                        >
                            清空入仕年
                        </button>
                        <button type="button" onClick={() => setActiveDialog(null)} style={dialogPrimaryButtonStyle}>
                            完成
                        </button>
                    </div>
                )}
            >
                <div style={{ display: 'grid', gap: 14 }}>
                    <label style={checkboxLabelStyle}>
                        <input
                            type="checkbox"
                            checked={filters.use_entry_year_range}
                            onChange={(event) => updateFilter('use_entry_year_range', event.target.checked)}
                        />
                        啟用入仕年範圍
                    </label>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <input
                            type="number"
                            value={filters.entry_year_from ?? ''}
                            onChange={(event) => updateFilter('entry_year_from', normalizeNumberInput(event.target.value))}
                            disabled={!filters.use_entry_year_range}
                            placeholder="-200"
                            style={numberInputStyle}
                        />
                        <input
                            type="number"
                            value={filters.entry_year_to ?? ''}
                            onChange={(event) => updateFilter('entry_year_to', normalizeNumberInput(event.target.value))}
                            disabled={!filters.use_entry_year_range}
                            placeholder="1911"
                            style={numberInputStyle}
                        />
                    </div>
                </div>
            </SelectionDialog>

            <SelectionDialog
                isOpen={activeDialog === 'dynasty'}
                title="設定朝代範圍"
                width={760}
                onClose={() => setActiveDialog(null)}
                footer={(
                    <div style={dialogFooterStyle}>
                        <button
                            type="button"
                            onClick={() => {
                                updateFilter('dynasty_codes', []);
                            }}
                            disabled={filters.dynasty_codes.length === 0}
                            style={dialogSecondaryButtonStyle}
                        >
                            清空朝代
                        </button>
                        <button type="button" onClick={() => setActiveDialog(null)} style={dialogPrimaryButtonStyle}>
                            完成
                        </button>
                    </div>
                )}
            >
                <div style={{ display: 'grid', gap: 14 }}>
                    <div style={checkboxListStyle}>
                        {dynasties.map((dynasty) => {
                            const checked = filters.dynasty_codes.includes(dynasty.c_dy);

                            return (
                                <label key={dynasty.c_dy} style={checkboxListItemStyle}>
                                    <input
                                        type="checkbox"
                                        checked={checked}
                                        onChange={() => handleDynastyToggle(dynasty.c_dy)}
                                    />
                                    <span>{formatDynastyLabel(dynasty)}</span>
                                </label>
                            );
                        })}
                    </div>
                </div>
            </SelectionDialog>
        </AppShell>
    );
}

function FilterSummaryTile({
    label,
    value,
    detail,
    size = 'normal',
    onClick,
}: {
    label: string;
    value: string;
    detail?: string;
    size?: 'normal' | 'large';
    onClick: () => void;
}) {
    return (
        <button type="button" onClick={onClick} style={filterSummaryTileStyle(size)}>
            <div style={summaryLabelStyle}>{label}</div>
            <div style={summaryValueStyle}>{value}</div>
            <div style={filterSummaryDetailStyle}>{detail || '\u00A0'}</div>
        </button>
    );
}

function SummaryItem({ label, value }: { label: string; value: string }) {
    return (
        <div style={summaryItemStyle}>
            <div style={summaryLabelStyle}>{label}</div>
            <div style={summaryValueStyle}>{value}</div>
        </div>
    );
}

function SelectorCard({
    title,
    summary,
    actionLabel,
    onOpen,
    actionDisabled = false,
    clearLabel,
    onClear,
    children,
}: {
    title: string;
    summary: string;
    actionLabel: string;
    onOpen: () => void;
    actionDisabled?: boolean;
    clearLabel?: string;
    onClear?: () => void;
    children?: React.ReactNode;
}) {
    return (
        <div style={panelStyle}>
            <div style={selectorCardHeaderStyle}>
                <div>
                    <div style={{ fontWeight: 700, fontSize: '0.92rem' }}>{title}</div>
                    <div style={{ marginTop: 4, color: '#6c757d', fontSize: '0.82rem' }}>{summary}</div>
                </div>
                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
                    {onClear && clearLabel && (
                        <button type="button" onClick={onClear} style={selectorSecondaryButtonStyle}>
                            {clearLabel}
                        </button>
                    )}
                    <button type="button" onClick={onOpen} disabled={actionDisabled} style={selectorPrimaryButtonStyle(actionDisabled)}>
                        {actionLabel}
                    </button>
                </div>
            </div>
            {children && <div style={{ padding: 16 }}>{children}</div>}
        </div>
    );
}

function SelectedPlaceChips({
    places,
    onRemove,
}: {
    places: PlaceOption[];
    onRemove: (placeId: number) => void;
}) {
    if (places.length === 0) {
        return <span style={{ color: '#6c757d', fontSize: '0.85rem' }}>尚未選擇</span>;
    }

    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {places.map((place) => (
                <span key={place.c_addr_id} style={placeChipStyle}>
                    {place.c_name_chn || place.c_name || `ADDR ${place.c_addr_id}`}
                    <button
                        type="button"
                        onClick={() => onRemove(place.c_addr_id)}
                        style={chipRemoveButtonStyle}
                        aria-label={`移除地點 ${place.c_addr_id}`}
                    >
                        ×
                    </button>
                </span>
            ))}
        </div>
    );
}

function RailButton({
    label,
    onClick,
    disabled = false,
    primary = false,
}: {
    label: string;
    onClick: () => void;
    disabled?: boolean;
    primary?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            style={railButtonStyle(disabled, primary)}
        >
            {label}
        </button>
    );
}

function buildRequestPayload(filters: FiltersState, selectedPlaces: PlaceOption[], overrides: { records_page?: number; people_page?: number }) {
    const payload: Record<string, unknown> = {
        records_page: overrides.records_page ?? 1,
        people_page: overrides.people_page ?? 1,
    };

    if (filters.person_keyword.trim() !== '') {
        payload.person_keyword = filters.person_keyword.trim();
    }

    if (filters.type_id) {
        payload.type_id = filters.type_id;
    }

    if (filters.entry_codes.length > 0) {
        payload.entry_codes = filters.entry_codes;
    }

    if (selectedPlaces.length > 0) {
        payload.place_ids = selectedPlaces.map((place) => place.c_addr_id);
        payload.include_sub_units = filters.include_sub_units;
    }

    if (filters.use_index_year_range) {
        payload.use_index_year_range = true;
        if (filters.index_year_from !== null) {
            payload.index_year_from = filters.index_year_from;
        }
        if (filters.index_year_to !== null) {
            payload.index_year_to = filters.index_year_to;
        }
    }

    if (filters.use_entry_year_range) {
        payload.use_entry_year_range = true;
        if (filters.entry_year_from !== null) {
            payload.entry_year_from = filters.entry_year_from;
        }
        if (filters.entry_year_to !== null) {
            payload.entry_year_to = filters.entry_year_to;
        }
    }

    if (filters.dynasty_codes.length > 0) {
        payload.dynasty_codes = filters.dynasty_codes;
    }

    return payload;
}

function buildQueryString(payload: Record<string, unknown>) {
    const params = new URLSearchParams();

    Object.entries(payload).forEach(([key, value]) => {
        if (value === null || value === undefined || value === '' || value === false) {
            return;
        }

        if (Array.isArray(value)) {
            value.forEach((item) => params.append(`${key}[]`, String(item)));
            return;
        }

        if (typeof value === 'boolean') {
            params.set(key, value ? '1' : '0');
            return;
        }

        params.set(key, String(value));
    });

    return params.toString();
}

function normalizeNumberInput(value: string): number | null {
    if (value.trim() === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isNaN(parsed) ? null : parsed;
}

function hasAnyCondition(filters: FiltersState) {
    return filters.person_keyword.trim() !== ''
        || filters.entry_codes.length > 0
        || filters.place_ids.length > 0
        || filters.use_index_year_range
        || filters.use_entry_year_range
        || filters.dynasty_codes.length > 0;
}

function formatDynastyLabel(dynasty: DynastyOption) {
    const label = dynasty.c_dynasty_chn || dynasty.c_dynasty || dynasty.c_dy;
    const start = dynasty.c_start ?? '?';
    const end = dynasty.c_end ?? '?';

    return `${label} (${start} - ${end})`;
}

function findSelectedTypeLabel(types: EntryType[], typeId: string | null) {
    if (!typeId) {
        return null;
    }

    const matched = types.find((type) => type.c_entry_type === typeId);

    return matched ? (matched.c_entry_type_desc_chn || matched.c_entry_type_desc || matched.c_entry_type) : typeId;
}

function collectErrorMessages(errors: Record<string, string[]>) {
    const messages = new Set<string>();

    Object.values(errors).forEach((items) => {
        items.forEach((message) => {
            if (message.trim() !== '') {
                messages.add(message);
            }
        });
    });

    return Array.from(messages);
}

function formatCodePreview(selectedCodes: number[], allCodes: EntryCode[]) {
    if (selectedCodes.length === 0) {
        return '點擊後開啟入仕方式選擇';
    }

    const codeMap = new Map(allCodes.map((code) => [code.c_entry_code, code]));
    const preview = selectedCodes.slice(0, 3).map((code) => {
        const matched = codeMap.get(code);
        return matched ? (matched.c_entry_desc_chn || matched.c_entry_desc || String(code)) : String(code);
    });

    return selectedCodes.length > 3
        ? `${preview.join('、')} 等 ${selectedCodes.length} 項`
        : preview.join('、');
}

function formatPlacePreview(places: PlaceOption[], includeSubUnits: boolean) {
    if (places.length === 0) {
        return includeSubUnits ? '包含下屬地點' : '點擊後開啟地點選擇';
    }

    const preview = places.slice(0, 3).map((place) => place.c_name_chn || place.c_name || `ADDR ${place.c_addr_id}`);
    const label = places.length > 3 ? `${preview.join('、')} 等 ${places.length} 項` : preview.join('、');

    return includeSubUnits ? `${label}；含下屬地點` : label;
}

function formatDynastyPreview(selectedDynastyCodes: string[], dynasties: DynastyOption[]) {
    if (selectedDynastyCodes.length === 0) {
        return '點擊後開啟朝代多選';
    }

    const dynastyMap = new Map(dynasties.map((dynasty) => [dynasty.c_dy, dynasty]));
    const preview = selectedDynastyCodes.slice(0, 3).map((code) => {
        const matched = dynastyMap.get(code);
        return matched ? (matched.c_dynasty_chn || matched.c_dynasty || matched.c_dy) : code;
    });

    return selectedDynastyCodes.length > 3
        ? `${preview.join('、')} 等 ${selectedDynastyCodes.length} 項`
        : preview.join('、');
}

const pageGridStyle = (isSidebarOpen: boolean, isCompactLayout: boolean): React.CSSProperties => ({
    display: 'grid',
    gridTemplateColumns: isCompactLayout ? 'minmax(0, 1fr)' : (isSidebarOpen ? '340px minmax(0, 1fr)' : '88px minmax(0, 1fr)'),
    gap: 18,
    alignItems: 'start',
});

const sidebarContainerStyle = (isCompactLayout: boolean): React.CSSProperties => ({
    position: isCompactLayout ? 'static' : 'sticky',
    top: isCompactLayout ? undefined : 12,
    alignSelf: 'start',
});

const sidebarCardStyle = (isCompactLayout: boolean, isSidebarOpen: boolean): React.CSSProperties => ({
    display: 'flex',
    flexDirection: 'column',
    gap: 14,
    minHeight: isCompactLayout ? 'auto' : (isSidebarOpen ? 'calc(100vh - 120px)' : 'auto'),
    border: '1px solid #d7dee6',
    borderRadius: 4,
    backgroundColor: '#f8fafc',
    boxShadow: '0 12px 36px rgba(15, 23, 42, 0.08)',
    overflow: 'hidden',
});

const sidebarHeaderStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    gap: 12,
    padding: '16px 18px',
    borderBottom: '1px solid #dee2e6',
    backgroundColor: '#fff',
};

const sidebarBodyStyle: React.CSSProperties = {
    display: 'grid',
    gap: 14,
    padding: 14,
};

const sidebarRailStyle: React.CSSProperties = {
    display: 'grid',
    gap: 10,
    padding: 12,
};

const collapseButtonStyle: React.CSSProperties = {
    padding: '7px 12px',
    borderRadius: 4,
    border: '1px solid #d0d8e2',
    backgroundColor: '#fff',
    color: '#334155',
    cursor: 'pointer',
    fontSize: '0.82rem',
    fontWeight: 600,
};

const expandRailButtonStyle: React.CSSProperties = {
    width: '100%',
    padding: '8px 10px',
    borderRadius: 4,
    border: '1px solid #d0d8e2',
    backgroundColor: '#fff',
    color: '#334155',
    cursor: 'pointer',
    fontSize: '0.82rem',
    fontWeight: 700,
};

const panelStyle: React.CSSProperties = {
    border: '1px solid #dee2e6',
    borderRadius: 4,
    backgroundColor: '#fff',
    overflow: 'hidden',
};

const selectorCardHeaderStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    gap: 10,
    padding: '14px 16px',
    borderBottom: '1px solid #eef2f6',
};

function selectorPrimaryButtonStyle(disabled: boolean): React.CSSProperties {
    return {
        padding: '6px 12px',
        borderRadius: 4,
        border: 'none',
        backgroundColor: disabled ? '#cbd5e1' : '#0d6efd',
        color: '#fff',
        cursor: disabled ? 'not-allowed' : 'pointer',
        fontSize: '0.82rem',
        fontWeight: 700,
    };
}

const selectorSecondaryButtonStyle: React.CSSProperties = {
    padding: '6px 10px',
    borderRadius: 4,
    border: '1px solid #cbd5e1',
    backgroundColor: '#fff',
    color: '#475569',
    cursor: 'pointer',
    fontSize: '0.82rem',
    fontWeight: 600,
};

const selectionCaptionStyle: React.CSSProperties = {
    marginBottom: 6,
    fontSize: '0.74rem',
    color: '#6b7280',
    textTransform: 'uppercase',
    letterSpacing: '0.02em',
};

const selectionValueStyle: React.CSSProperties = {
    fontSize: '0.92rem',
    color: '#0f172a',
    fontWeight: 600,
};

function railButtonStyle(disabled: boolean, primary: boolean): React.CSSProperties {
    return {
        width: '100%',
        minHeight: 48,
        padding: '10px 8px',
        borderRadius: 4,
        border: primary ? 'none' : '1px solid #d0d8e2',
        backgroundColor: primary ? '#0d6efd' : '#fff',
        color: primary ? '#fff' : '#334155',
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.55 : 1,
        fontSize: '0.82rem',
        fontWeight: 700,
    };
}

const workspaceStyle: React.CSSProperties = {
    display: 'grid',
    gap: 16,
    minWidth: 0,
    padding: '16px 20px 24px',
};

const workspaceErrorStackStyle: React.CSSProperties = {
    display: 'grid',
    gap: 10,
};

const workspaceSummaryCardStyle: React.CSSProperties = {
    border: '1px solid #d8e0e8',
    borderRadius: 4,
    backgroundColor: '#fff',
    padding: 16,
    boxShadow: '0 10px 28px rgba(15, 23, 42, 0.05)',
};

const summaryGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))',
    gap: 10,
};

const summaryItemStyle: React.CSSProperties = {
    padding: '10px 12px',
    borderRadius: 4,
    backgroundColor: '#f8fafc',
    border: '1px solid #e4ebf2',
};

function filterSummaryTileStyle(size: 'normal' | 'large'): React.CSSProperties {
    return {
        ...summaryItemStyle,
        width: '100%',
        minWidth: 0,
        textAlign: 'left',
        cursor: 'pointer',
        whiteSpace: 'normal',
        display: 'grid',
        gridTemplateRows: '2.3em 2.4em minmax(2.8em, auto)',
        alignContent: 'start',
        gridColumn: size === 'large' ? 'span 2' : 'span 1',
    };
}

const summaryLabelStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'flex-start',
    minHeight: '2.3em',
    lineHeight: 1.2,
    fontSize: '0.72rem',
    letterSpacing: '0.02em',
    color: '#6b7280',
    textTransform: 'uppercase',
};

const summaryValueStyle: React.CSSProperties = {
    marginTop: 4,
    fontSize: '0.92rem',
    color: '#111827',
    fontWeight: 600,
    overflowWrap: 'anywhere',
    alignSelf: 'start',
};

const filterSummaryDetailStyle: React.CSSProperties = {
    marginTop: 6,
    fontSize: '0.78rem',
    color: '#6b7280',
    lineHeight: 1.4,
    overflowWrap: 'anywhere',
    minHeight: '2.8em',
    alignSelf: 'start',
};

const summaryActionButtonStyle: React.CSSProperties = {
    padding: '7px 12px',
    borderRadius: 4,
    border: '1px solid #cfd6de',
    backgroundColor: '#f8fafc',
    color: '#334155',
    cursor: 'pointer',
    fontSize: '0.83rem',
    fontWeight: 600,
};

const resultsWorkspaceStyle: React.CSSProperties = {
    ...panelStyle,
    minHeight: '76vh',
    scrollMarginTop: '24px',
};

const resultsViewportStyle: React.CSSProperties = {
    minHeight: '56vh',
    maxHeight: '72vh',
    overflow: 'auto',
    border: '1px solid #e3e8ee',
    borderRadius: 4,
    backgroundColor: '#fff',
};

const panelHeaderStyle: React.CSSProperties = {
    padding: '12px 16px',
    borderBottom: '1px solid #dee2e6',
    fontWeight: 700,
    fontSize: '0.96rem',
};

const labelStyle: React.CSSProperties = {
    display: 'block',
    fontWeight: 500,
    fontSize: '0.85rem',
    marginBottom: 6,
};

const checkboxLabelStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    gap: 8,
    fontWeight: 500,
    fontSize: '0.85rem',
};

const checkboxListStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
    gap: 8,
    maxHeight: 320,
    overflowY: 'auto',
    paddingRight: 4,
};

const checkboxListItemStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'flex-start',
    gap: 8,
    padding: '8px 10px',
    border: '1px solid #dbe2ea',
    borderRadius: 4,
    backgroundColor: '#fff',
    fontSize: '0.85rem',
    cursor: 'pointer',
};

const hintStyle: React.CSSProperties = {
    fontSize: '0.75rem',
    color: '#6c757d',
    marginTop: 4,
};

const dividerStyle: React.CSSProperties = {
    border: 'none',
    borderTop: '1px solid #e9ecef',
    margin: '14px 0',
};

const textInputStyle: React.CSSProperties = {
    width: '100%',
    maxWidth: '100%',
    boxSizing: 'border-box',
    padding: '7px 10px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    fontSize: '0.875rem',
};

const numberInputStyle: React.CSSProperties = {
    flex: 1,
    padding: '7px 10px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    fontSize: '0.875rem',
};

const selectStyle: React.CSSProperties = {
    flex: 1,
    padding: '7px 10px',
    border: '1px solid #ced4da',
    borderRadius: 4,
    fontSize: '0.875rem',
    backgroundColor: '#fff',
};

function primaryButtonStyle(disabled: boolean): React.CSSProperties {
    return {
        flex: 1,
        padding: '9px 14px',
        backgroundColor: '#0d6efd',
        color: '#fff',
        border: 'none',
        borderRadius: 4,
        cursor: disabled ? 'wait' : 'pointer',
        opacity: disabled ? 0.75 : 1,
        fontSize: '0.9rem',
        fontWeight: 600,
    };
}

function toolbarPrimaryButtonStyle(disabled: boolean): React.CSSProperties {
    return {
        ...primaryButtonStyle(disabled),
        flex: 'none',
    };
}

const secondaryButtonStyle: React.CSSProperties = {
    padding: '9px 14px',
    backgroundColor: '#6c757d',
    color: '#fff',
    border: 'none',
    borderRadius: 4,
    cursor: 'pointer',
    fontSize: '0.9rem',
    fontWeight: 600,
};

const toolbarSecondaryButtonStyle: React.CSSProperties = {
    ...secondaryButtonStyle,
    flex: 'none',
};

const errorBoxStyle: React.CSSProperties = {
    padding: '10px 12px',
    borderRadius: 4,
    backgroundColor: '#f8d7da',
    color: '#842029',
    fontSize: '0.85rem',
};

function tabButtonStyle(active: boolean): React.CSSProperties {
    return {
        padding: '9px 16px',
        borderRadius: '4px 4px 0 0',
        border: '1px solid #cfd8e3',
        borderBottomColor: active ? '#fff' : '#cfd8e3',
        backgroundColor: active ? '#fff' : '#eef3f8',
        color: active ? '#0f172a' : '#526071',
        cursor: 'pointer',
        fontSize: '0.85rem',
        fontWeight: 600,
        marginBottom: -1,
    };
}

const tabStripStyle: React.CSSProperties = {
    display: 'flex',
    gap: 6,
    alignItems: 'flex-end',
    borderBottom: '1px solid #cfd8e3',
    paddingBottom: 0,
};

const placeChipStyle: React.CSSProperties = {
    display: 'inline-flex',
    alignItems: 'center',
    padding: '4px 9px',
    backgroundColor: '#198754',
    color: '#fff',
    borderRadius: 4,
    fontSize: '0.75rem',
};

const chipRemoveButtonStyle: React.CSSProperties = {
    marginLeft: 4,
    background: 'none',
    border: 'none',
    color: '#fff',
    cursor: 'pointer',
    padding: '0 2px',
    fontSize: '0.85rem',
    lineHeight: 1,
};

const dialogFooterStyle: React.CSSProperties = {
    display: 'flex',
    justifyContent: 'space-between',
    gap: 8,
    width: '100%',
};

const dialogPrimaryButtonStyle: React.CSSProperties = {
    padding: '8px 14px',
    borderRadius: 4,
    border: 'none',
    backgroundColor: '#0d6efd',
    color: '#fff',
    cursor: 'pointer',
    fontSize: '0.85rem',
    fontWeight: 700,
};

const dialogSecondaryButtonStyle: React.CSSProperties = {
    padding: '8px 14px',
    borderRadius: 4,
    border: '1px solid #cbd5e1',
    backgroundColor: '#fff',
    color: '#475569',
    cursor: 'pointer',
    fontSize: '0.85rem',
    fontWeight: 600,
};

const entrySelectionDialogGridStyle: React.CSSProperties = {
    display: 'grid',
    gridTemplateColumns: 'repeat(auto-fit, minmax(260px, 1fr))',
    gap: 16,
    alignItems: 'start',
};

const entrySelectionSummaryPanelStyle: React.CSSProperties = {
    ...panelStyle,
    minHeight: 402,
};

const modalHintBoxStyle: React.CSSProperties = {
    padding: '12px 14px',
    borderRadius: 0,
    backgroundColor: '#eff6ff',
    border: '1px solid #dbeafe',
    color: '#1d4ed8',
    fontSize: '0.85rem',
    lineHeight: 1.5,
};
