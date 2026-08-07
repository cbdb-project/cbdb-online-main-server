import React, { useState, useCallback, useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { useTranslation } from '../../hooks/useTranslation';
import { registerDirtyChecker } from '../../hooks/useDirtyGuard';
import PeopleSearchPanel from '../../components/PersonBrowser/PeopleSearchPanel';
import PeopleList, { PersonListItem, Pagination, SortOrder } from '../../components/PersonBrowser/PeopleList';
import PersonSummaryPanel, { PersonSummary } from '../../components/PersonBrowser/PersonSummaryPanel';
import BrowserTabs, { TAB_DEFINITIONS } from '../../components/PersonBrowser/BrowserTabs';
import TabContentLoader from '../../components/PersonBrowser/TabContentLoader';
import SelectionDialog from '../../components/SelectionDialog';

interface PageProps {
    [key: string]: unknown;
    tabKeys: string[];
    searchEndpoint: string;
    summaryEndpoint: string;
    tabEndpoint: string;
    mutateEndpoint: string;
    createEndpoint: string;
    deleteEndpoint: string;
    pinyinEndpoint: string;
    canEditBasicInfo: boolean;
    canProposeEdits: boolean;
    altnameEditorIsNew: boolean;
    addressesEditorIsNew: boolean;
    textsEditorIsNew: boolean;
    sourcesEditorIsNew: boolean;
    officesEditorIsNew: boolean;
    assocEditorIsNew: boolean;
    kinshipEditorIsNew: boolean;
    eventsEditorIsNew: boolean;
    entriesEditorIsNew: boolean;
    statusesEditorIsNew: boolean;
    possessionEditorIsNew: boolean;
    socialInstEditorIsNew: boolean;
    initialPersonId: number | null;
    initialKeyword: string;
    initialDynasty: string;
    initialTab: string;
    initialPage: number;
}

interface BrowserLocationState {
    personId: number | null;
    keyword: string;
    dynasty: string;
    tab: string;
    page: number;
    sort: SortOrder;
}

export default function PersonBrowserIndex() {
    const {
        searchEndpoint,
        summaryEndpoint,
        tabEndpoint,
        mutateEndpoint,
        createEndpoint,
        deleteEndpoint,
        pinyinEndpoint,
        canEditBasicInfo,
        canProposeEdits,
        altnameEditorIsNew,
        addressesEditorIsNew,
        textsEditorIsNew,
        sourcesEditorIsNew,
        officesEditorIsNew,
        assocEditorIsNew,
        kinshipEditorIsNew,
        eventsEditorIsNew,
        entriesEditorIsNew,
        statusesEditorIsNew,
        possessionEditorIsNew,
        socialInstEditorIsNew,
        initialPersonId,
        initialKeyword,
        initialDynasty,
        initialTab,
        initialPage,
    } = usePage<PageProps>().props;

    const tPerson = useTranslation('person');

    // ── Core state ──
    const [keyword, setKeyword] = useState(initialKeyword || '');
    const [dynasty, setDynasty] = useState(initialDynasty || '');
    const [dynastyOptions, setDynastyOptions] = useState<Array<{ c_dy: number; label: string; count: number }>>([]);
    const [page, setPage] = useState(initialPage || 1);
    const [sortOrder, setSortOrder] = useState<SortOrder>(() => {
        if (typeof window !== 'undefined') {
            const params = new URLSearchParams(window.location.search);
            return params.get('sort') === 'desc' ? 'desc' : 'asc';
        }
        return 'asc';
    });
    const [people, setPeople] = useState<PersonListItem[]>([]);
    const [pagination, setPagination] = useState<Pagination | null>(null);
    const [listLoading, setListLoading] = useState(false);

    const [selectedId, setSelectedId] = useState<number | null>(initialPersonId);
    const [summary, setSummary] = useState<PersonSummary | null>(null);
    const [summaryLoading, setSummaryLoading] = useState(false);
    const [summaryError, setSummaryError] = useState<string | null>(null);

    const [activeTab, setActiveTab] = useState(initialTab || 'basic_info');
    const [summaryRefreshKey, setSummaryRefreshKey] = useState(0);
    const [basicInfoEditorState, setBasicInfoEditorState] = useState({ editing: false, dirty: false });
    const [showUnsavedDialog, setShowUnsavedDialog] = useState(false);
    const [savingBeforeNavigate, setSavingBeforeNavigate] = useState(false);
    const [isMobile, setIsMobile] = useState(() => typeof window !== 'undefined' && window.innerWidth < MOBILE_BREAKPOINT);
    const [sidebarOpen, setSidebarOpen] = useState(() => typeof window === 'undefined' || window.innerWidth >= MOBILE_BREAKPOINT);

    const isInitialMount = useRef(true);
    const basicInfoSaveHandlerRef = useRef<(() => Promise<boolean>) | null>(null);
    const pendingNavigationRef = useRef<(() => void) | null>(null);
    const committedLocationRef = useRef<BrowserLocationState>({
        personId: initialPersonId,
        keyword: initialKeyword || '',
        dynasty: initialDynasty || '',
        tab: initialTab || 'basic_info',
        page: initialPage || 1,
        sort: sortOrder,
    });
    const bypassNextPopGuardRef = useRef(false);

    const readLocationState = useCallback((search: string): BrowserLocationState => {
        const params = new URLSearchParams(search);
        const personId = params.get('person_id');
        const parsedPage = parseInt(params.get('page') || '1', 10);

        const rawSort = params.get('sort');

        return {
            personId: personId ? parseInt(personId, 10) : null,
            keyword: params.get('keyword') || '',
            dynasty: params.get('c_dy') || '',
            tab: params.get('tab') || 'basic_info',
            page: Number.isFinite(parsedPage) && parsedPage > 0 ? parsedPage : 1,
            sort: rawSort === 'asc' ? 'asc' : 'desc',
        };
    }, []);

    const buildUrlForState = useCallback((state: BrowserLocationState) => {
        const url = new URL(window.location.href);

        if (state.personId != null) {
            url.searchParams.set('person_id', String(state.personId));
        } else {
            url.searchParams.delete('person_id');
        }

        if (state.keyword) {
            url.searchParams.set('keyword', state.keyword);
        } else {
            url.searchParams.delete('keyword');
        }

        if (state.tab) {
            url.searchParams.set('tab', state.tab);
        } else {
            url.searchParams.delete('tab');
        }

        if (state.dynasty) {
            url.searchParams.set('c_dy', state.dynasty);
        } else {
            url.searchParams.delete('c_dy');
        }

        if (state.page > 1) {
            url.searchParams.set('page', String(state.page));
        } else {
            url.searchParams.delete('page');
        }

        if (state.sort === 'asc') {
            url.searchParams.set('sort', 'asc');
        } else {
            url.searchParams.delete('sort');
        }

        return url.toString();
    }, []);

    // ── URL sync ──
    const updateUrl = useCallback(
        (params: { person_id?: number | null; keyword?: string; dynasty?: string; tab?: string; page?: number; sort?: SortOrder }) => {
            const url = new URL(window.location.href);
            if (params.person_id != null) {
                url.searchParams.set('person_id', String(params.person_id));
            } else if (params.person_id === null) {
                url.searchParams.delete('person_id');
            }
            if (params.keyword != null) {
                if (params.keyword) url.searchParams.set('keyword', params.keyword);
                else url.searchParams.delete('keyword');
            }
            if (params.dynasty !== undefined) {
                if (params.dynasty) url.searchParams.set('c_dy', params.dynasty);
                else url.searchParams.delete('c_dy');
            }
            if (params.tab !== undefined) {
                if (params.tab) url.searchParams.set('tab', params.tab);
                else url.searchParams.delete('tab');
            }
            if (params.page !== undefined) {
                if (params.page > 1) url.searchParams.set('page', String(params.page));
                else url.searchParams.delete('page');
            }
            if (params.sort !== undefined) {
                if (params.sort === 'asc') url.searchParams.set('sort', 'asc');
                else url.searchParams.delete('sort');
            }

            committedLocationRef.current = readLocationState(url.search);
            window.history.pushState({}, '', url.toString());
        },
        [readLocationState],
    );

    // ── Search ──
    const doSearch = useCallback(
        (q: string, p: number, dy: string = '', sort: SortOrder = 'asc') => {
            setListLoading(true);
            let url = `${searchEndpoint}?q=${encodeURIComponent(q)}&page=${p}&per_page=20&sort=${sort}`;
            if (dy) {
                url += `&c_dy=${encodeURIComponent(dy)}`;
            }
            fetch(url)
                .then((r) => r.json())
                .then((json) => {
                    setPeople(json.data || []);
                    setPagination(json.pagination || null);
                    if (json.dynasty_counts) {
                        setDynastyOptions(json.dynasty_counts);
                    }
                })
                .catch(() => {
                    setPeople([]);
                    setPagination(null);
                })
                .finally(() => setListLoading(false));
        },
        [searchEndpoint],
    );

    const applyLocationState = useCallback((state: BrowserLocationState) => {
        setKeyword(state.keyword);
        setDynasty(state.dynasty);
        setPage(state.page);
        setSortOrder(state.sort);
        setActiveTab(state.tab);
        setSelectedId(state.personId);
        doSearch(state.keyword, state.page, state.dynasty, state.sort);
    }, [doSearch]);

    const handleSearch = useCallback(
        (q: string, dy: string) => {
            setKeyword(q);
            setDynasty(dy);
            setPage(1);
            doSearch(q, 1, dy, sortOrder);
            updateUrl({ keyword: q, dynasty: dy, page: 1 });
        },
        [doSearch, sortOrder, updateUrl],
    );

    const handleClear = useCallback(() => {
        setKeyword('');
        setDynasty('');
        setPage(1);
        doSearch('', 1, '', sortOrder);
        updateUrl({ keyword: '', dynasty: '', page: 1 });
    }, [doSearch, sortOrder, updateUrl]);

    const handlePageChange = useCallback(
        (p: number) => {
            setPage(p);
            doSearch(keyword, p, dynasty, sortOrder);
            updateUrl({ keyword, dynasty, page: p });
        },
        [keyword, dynasty, sortOrder, doSearch, updateUrl],
    );

    // ── Select person ──
    const handleSelect = useCallback(
        (personId: number) => {
            setSelectedId(personId);
            updateUrl({ person_id: personId, keyword, dynasty, tab: activeTab, page });
            if (isMobile) {
                setSidebarOpen(false);
            }
        },
        [activeTab, dynasty, isMobile, keyword, page, updateUrl],
    );

    const handleSortChange = useCallback(
        (sort: SortOrder) => {
            setSortOrder(sort);
            setPage(1);
            doSearch(keyword, 1, dynasty, sort);
            updateUrl({ keyword, dynasty, page: 1, sort });
        },
        [keyword, dynasty, doSearch, updateUrl],
    );

    // 僅刷新摘要（tab_counts／header），不連動搜尋結果列表。
    // 12 個子資源分頁新增/刪除記錄不會影響列表欄位顯示，無需重新搜尋（避免不必要的列表閃爍）。
    const handleSubresourceChanged = useCallback(() => {
        setSummaryRefreshKey((prev) => prev + 1);
    }, []);

    // 基本資料儲存後：除刷新摘要外，姓名/朝代等變更可能影響左側列表欄位顯示，需一併重新搜尋。
    const handleBasicInfoSaved = useCallback(() => {
        setSummaryRefreshKey((prev) => prev + 1);
        doSearch(keyword, page, dynasty, sortOrder);
    }, [doSearch, keyword, page, dynasty, sortOrder]);

    const registerBasicInfoSaveHandler = useCallback((handler: (() => Promise<boolean>) | null) => {
        basicInfoSaveHandlerRef.current = handler;
    }, []);

    const runOrWarnUnsaved = useCallback((action: () => void) => {
        if (basicInfoEditorState.dirty) {
            pendingNavigationRef.current = action;
            setShowUnsavedDialog(true);

            return;
        }

        action();
    }, [basicInfoEditorState.dirty]);

    // ── Register dirty checker for locale switch guard ──
    const dirtyRef = useRef(false);
    dirtyRef.current = basicInfoEditorState.dirty;
    useEffect(() => {
        const unregister = registerDirtyChecker(() => dirtyRef.current);
        return unregister; // cleanup: remove checker on unmount
    }, []);

    // ── Load summary when selectedId changes ──
    // 依賴含 activeTab：**每次切分頁都重新抓摘要**，否則分頁徽章計數會停在選取人物時的數字——
    // 其他人（或自己在另一個瀏覽器分頁）新增／刪除記錄後，切分頁時計數不會變（須整頁重載才更新）。
    // 已有摘要時為靜默更新：不進 loading、失敗也保留舊摘要，避免每次切分頁都閃一下摘要面板。
    // 只有「同一個人物、已有摘要」才靜默更新；換人物時仍走 loading，避免面板短暫顯示上一個人的摘要。
    const summaryPersonRef = useRef<number | null>(null);
    summaryPersonRef.current = summary?.c_personid ?? null;
    useEffect(() => {
        if (selectedId == null) {
            setSummary(null);
            setSummaryLoading(false);
            setSummaryError(null);
            return;
        }
        const silent = summaryPersonRef.current === selectedId;
        // 每次執行都把 loading 設成「本次的意圖」，不是只在非靜默時設 true：
        // 否則「非靜默請求被中止 → 下一輪是靜默」會讓 loading 永遠卡在 true
        // （PersonSummaryPanel 的 loading 分支在 summary 之前短路，面板會整塊空掉）。
        setSummaryLoading(!silent);
        if (!silent) {
            setSummaryError(null);
        }
        // 快速連續切分頁會疊發多個請求；abort 舊請求，避免慢的舊回應蓋掉新回應。
        const controller = new AbortController();
        const url = summaryEndpoint.replace('__PERSON_ID__', String(selectedId));
        fetch(url, { signal: controller.signal })
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((data) => {
                setSummary(data);
                setSummaryError(null);
            })
            .catch((err) => {
                if (err instanceof DOMException && err.name === 'AbortError') return;
                // 靜默更新失敗就沿用既有摘要（計數暫時偏舊勝過把摘要面板清空）。
                if (silent) return;
                setSummaryError(err.message || tPerson('load_failed'));
                setSummary(null);
            })
            .finally(() => {
                // 已被中止＝已有新的一輪接手（cleanup 先於新 effect 執行，而本回呼在其後才跑），
                // 此時不可再覆寫新一輪剛設好的 loading。
                if (!controller.signal.aborted) setSummaryLoading(false);
            });

        return () => controller.abort();
    }, [selectedId, summaryEndpoint, summaryRefreshKey, activeTab]);

    // ── Tab change ──
    const handleTabChange = useCallback(
        (tab: string) => {
            runOrWarnUnsaved(() => {
                setActiveTab(tab);
                updateUrl({ person_id: selectedId, keyword, dynasty, tab, page });
            });
        },
        [page, selectedId, keyword, dynasty, runOrWarnUnsaved, updateUrl],
    );

    // ── Initial load ──
    useEffect(() => {
        if (isInitialMount.current) {
            isInitialMount.current = false;
            doSearch(initialKeyword || '', initialPage || 1, initialDynasty || '', sortOrder);
        }
    }, [doSearch, initialKeyword, initialDynasty, initialPage]);

    useEffect(() => {
        const updateViewport = () => {
            const mobile = window.innerWidth < MOBILE_BREAKPOINT;

            setIsMobile((prev) => {
                if (prev !== mobile) {
                    setSidebarOpen(!mobile);
                }

                return mobile;
            });
        };

        updateViewport();
        window.addEventListener('resize', updateViewport);

        return () => window.removeEventListener('resize', updateViewport);
    }, []);

    // ── Browser back/forward ──
    useEffect(() => {
        const onPop = () => {
            const nextState = readLocationState(window.location.search);

            if (bypassNextPopGuardRef.current) {
                bypassNextPopGuardRef.current = false;
                committedLocationRef.current = nextState;
                applyLocationState(nextState);

                return;
            }

            if (basicInfoEditorState.dirty) {
                window.history.pushState({}, '', buildUrlForState(committedLocationRef.current));
                pendingNavigationRef.current = () => {
                    bypassNextPopGuardRef.current = true;
                    window.history.back();
                };
                setShowUnsavedDialog(true);

                return;
            }

            committedLocationRef.current = nextState;
            applyLocationState(nextState);
        };
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, [applyLocationState, basicInfoEditorState.dirty, buildUrlForState, readLocationState]);

    useEffect(() => {
        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            if (!basicInfoEditorState.dirty) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', onBeforeUnload);

        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [basicInfoEditorState.dirty]);

    const handleSaveAndContinue = useCallback(async () => {
        const saveHandler = basicInfoSaveHandlerRef.current;
        if (!saveHandler) {
            setShowUnsavedDialog(false);
            pendingNavigationRef.current?.();
            pendingNavigationRef.current = null;

            return;
        }

        setSavingBeforeNavigate(true);
        const ok = await saveHandler();
        setSavingBeforeNavigate(false);

        if (!ok) {
            return;
        }

        setShowUnsavedDialog(false);
        pendingNavigationRef.current?.();
        pendingNavigationRef.current = null;
    }, []);

    const handleDiscardAndContinue = useCallback(() => {
        setShowUnsavedDialog(false);
        pendingNavigationRef.current?.();
        pendingNavigationRef.current = null;
    }, []);

    const handleStayOnPage = useCallback(() => {
        setShowUnsavedDialog(false);
        pendingNavigationRef.current = null;
    }, []);

    const guardedHandleSelect = useCallback((personId: number) => {
        runOrWarnUnsaved(() => handleSelect(personId));
    }, [handleSelect, runOrWarnUnsaved]);

    return (
        <DashboardLayout disableContentPadding>
            {isMobile && sidebarOpen ? (
                <div
                    style={mobileBackdropStyle}
                    onClick={() => setSidebarOpen(false)}
                />
            ) : null}

            <div style={wrapperStyle}>
                {/* Left sidebar */}
                <aside
                    style={{
                        ...sidebarStyle,
                        ...(isMobile ? mobileSidebarStyle : {}),
                        ...(isMobile && sidebarOpen ? mobileSidebarOpenStyle : {}),
                        ...(isMobile && !sidebarOpen ? mobileSidebarClosedStyle : {}),
                    }}
                >
                    {isMobile ? (
                        <div style={mobileSidebarHeaderStyle}>
                            <div style={mobileSidebarTitleStyle}>{tPerson('person_list')}</div>
                            <button
                                type="button"
                                style={mobileSidebarCloseButtonStyle}
                                onClick={() => setSidebarOpen(false)}
                            >
                                {tPerson('collapse')}
                            </button>
                        </div>
                    ) : null}
                    <PeopleSearchPanel keyword={keyword} dynasty={dynasty} dynastyOptions={dynastyOptions} onSearch={handleSearch} onClear={handleClear} />
                    <PeopleList
                        people={people}
                        pagination={pagination}
                        selectedId={selectedId}
                        loading={listLoading}
                        sortOrder={sortOrder}
                        onSelect={guardedHandleSelect}
                        onPageChange={handlePageChange}
                        onSortChange={handleSortChange}
                    />
                </aside>

                {/* Right main area */}
                <div style={mainStyle}>
                    {isMobile ? (
                        <div style={mobileToolbarStyle}>
                            <button
                                type="button"
                                style={mobileSidebarToggleButtonStyle}
                                onClick={() => setSidebarOpen(true)}
                            >
                                {tPerson('person_list')}
                            </button>
                            <div style={mobileToolbarMetaStyle}>
                                {selectedId != null ? `${tPerson('person')} #${selectedId}` : tPerson('no_person_selected')}
                            </div>
                        </div>
                    ) : null}

                    <PersonSummaryPanel summary={summary} loading={summaryLoading} error={summaryError} />

                    <BrowserTabs
                        tabs={TAB_DEFINITIONS}
                        activeTab={activeTab}
                        counts={summary?.tab_counts || {}}
                        onTabChange={handleTabChange}
                    />

                    <div style={tabContentStyle}>
                        <TabContentLoader
                            personId={selectedId}
                            activeTab={activeTab}
                            tabEndpoint={tabEndpoint}
                            mutateEndpoint={mutateEndpoint}
                            createEndpoint={createEndpoint}
                            deleteEndpoint={deleteEndpoint}
                            pinyinEndpoint={pinyinEndpoint}
                            canEditBasicInfo={canEditBasicInfo}
                            canProposeEdits={canProposeEdits}
                            altnameEditorIsNew={altnameEditorIsNew}
                            addressesEditorIsNew={addressesEditorIsNew}
                            textsEditorIsNew={textsEditorIsNew}
                            sourcesEditorIsNew={sourcesEditorIsNew}
                            officesEditorIsNew={officesEditorIsNew}
                            assocEditorIsNew={assocEditorIsNew}
                            kinshipEditorIsNew={kinshipEditorIsNew}
                            eventsEditorIsNew={eventsEditorIsNew}
                            entriesEditorIsNew={entriesEditorIsNew}
                            statusesEditorIsNew={statusesEditorIsNew}
                            possessionEditorIsNew={possessionEditorIsNew}
                            socialInstEditorIsNew={socialInstEditorIsNew}
                            postCE={summary?.dynasty_start != null && summary.dynasty_start > 0}
                            onSelectPerson={guardedHandleSelect}
                            onBasicInfoSaved={handleBasicInfoSaved}
                            onSubresourceChanged={handleSubresourceChanged}
                            onBasicInfoEditorStateChange={setBasicInfoEditorState}
                            onRegisterBasicInfoSaveHandler={registerBasicInfoSaveHandler}
                        />
                    </div>
                </div>
            </div>

            <SelectionDialog
                isOpen={showUnsavedDialog}
                title={tPerson('unsaved_changes')}
                width={560}
                onClose={handleStayOnPage}
                footer={(
                    <>
                        <button type="button" style={dialogSecondaryButtonStyle} onClick={handleStayOnPage}>
                            {tPerson('stay_on_page')}
                        </button>
                        <button type="button" style={dialogNeutralButtonStyle} onClick={handleDiscardAndContinue}>
                            {tPerson('leave_without_saving')}
                        </button>
                        <button
                            type="button"
                            style={dialogPrimaryButtonStyle}
                            onClick={() => {
                                void handleSaveAndContinue();
                            }}
                            disabled={savingBeforeNavigate || !basicInfoEditorState.dirty}
                        >
                            {savingBeforeNavigate ? tPerson('saving') : tPerson('save_and_continue')}
                        </button>
                    </>
                )}
            >
                <div style={dialogBodyTextStyle}>
                    {tPerson('unsaved_changes_warning')}
                </div>
            </SelectionDialog>
        </DashboardLayout>
    );
}

// ── Styles ──

const MOBILE_BREAKPOINT = 960;

const wrapperStyle: React.CSSProperties = {
    display: 'flex',
    // 填滿 DashboardLayout 內容區：扣掉導覽列（h-14 ≈ 57px）與頁尾（py-3 + text-sm ≈ 49px）≈ 106px。
    height: 'calc(100vh - 106px)',
    overflow: 'hidden',
};

const sidebarStyle: React.CSSProperties = {
    width: 320,
    minWidth: 240,
    maxWidth: 400,
    borderRight: '1px solid var(--border)',
    backgroundColor: 'var(--card)',
    display: 'flex',
    flexDirection: 'column',
    overflow: 'hidden',
    flexShrink: 0,
};

const mobileSidebarStyle: React.CSSProperties = {
    position: 'fixed',
    top: 0,
    left: 0,
    bottom: 0,
    width: 'min(86vw, 360px)',
    maxWidth: '86vw',
    minWidth: 'unset',
    zIndex: 35,
    borderRight: '1px solid var(--border)',
    boxShadow: '0 18px 48px rgba(15, 23, 42, 0.22)',
    transition: 'transform 0.22s ease, box-shadow 0.22s ease',
};

const mobileSidebarOpenStyle: React.CSSProperties = {
    transform: 'translateX(0)',
};

const mobileSidebarClosedStyle: React.CSSProperties = {
    transform: 'translateX(calc(-100% - 16px))',
    boxShadow: 'none',
};

const mobileBackdropStyle: React.CSSProperties = {
    position: 'fixed',
    inset: 0,
    backgroundColor: 'rgba(15, 23, 42, 0.28)',
    zIndex: 30,
};

const mobileSidebarHeaderStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    padding: '12px 14px',
    borderBottom: '1px solid var(--border)',
    backgroundColor: 'var(--surface-sunken)',
};

const mobileSidebarTitleStyle: React.CSSProperties = {
    fontSize: '0.95rem',
    fontWeight: 700,
    color: 'var(--foreground)',
};

const mobileSidebarCloseButtonStyle: React.CSSProperties = {
    border: '1px solid var(--border)',
    backgroundColor: 'var(--card)',
    color: 'var(--muted-foreground)',
    borderRadius: 8,
    padding: '6px 10px',
    fontSize: '0.82rem',
    fontWeight: 700,
    cursor: 'pointer',
};

const mainStyle: React.CSSProperties = {
    flex: 1,
    display: 'flex',
    flexDirection: 'column',
    overflow: 'hidden',
    backgroundColor: 'var(--surface-sunken)',
};

const mobileToolbarStyle: React.CSSProperties = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: 12,
    padding: '10px 12px 0',
    flexWrap: 'wrap',
};

const mobileSidebarToggleButtonStyle: React.CSSProperties = {
    border: '1px solid var(--border)',
    backgroundColor: 'var(--card)',
    color: 'var(--foreground)',
    borderRadius: 999,
    padding: '7px 12px',
    fontSize: '0.83rem',
    fontWeight: 700,
    cursor: 'pointer',
};

const mobileToolbarMetaStyle: React.CSSProperties = {
    color: 'var(--muted-foreground)',
    fontSize: '0.82rem',
    fontWeight: 600,
};

const tabContentStyle: React.CSSProperties = {
    flex: 1,
    overflowY: 'auto',
    padding: 16,
};

const dialogBodyTextStyle: React.CSSProperties = {
    color: 'var(--muted-foreground)',
    lineHeight: 1.7,
};

const dialogPrimaryButtonStyle: React.CSSProperties = {
    borderRadius: 8,
    padding: '8px 14px',
    border: '1px solid var(--primary)',
    backgroundColor: 'var(--primary)',
    color: 'var(--primary-foreground)',
    fontWeight: 700,
    cursor: 'pointer',
};

const dialogSecondaryButtonStyle: React.CSSProperties = {
    borderRadius: 8,
    padding: '8px 14px',
    border: '1px solid var(--border)',
    backgroundColor: 'var(--card)',
    color: 'var(--muted-foreground)',
    fontWeight: 700,
    cursor: 'pointer',
};

const dialogNeutralButtonStyle: React.CSSProperties = {
    borderRadius: 8,
    padding: '8px 14px',
    border: '1px solid var(--danger-border)',
    backgroundColor: 'var(--danger-subtle)',
    color: 'var(--danger-subtle-foreground)',
    fontWeight: 700,
    cursor: 'pointer',
};
