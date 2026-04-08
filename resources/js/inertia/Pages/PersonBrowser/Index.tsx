import React, { useState, useCallback, useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import PeopleSearchPanel from '../../components/PersonBrowser/PeopleSearchPanel';
import PeopleList, { PersonListItem, Pagination } from '../../components/PersonBrowser/PeopleList';
import PersonSummaryPanel, { PersonSummary } from '../../components/PersonBrowser/PersonSummaryPanel';
import BrowserTabs, { TAB_DEFINITIONS } from '../../components/PersonBrowser/BrowserTabs';
import TabContentLoader from '../../components/PersonBrowser/TabContentLoader';
import SelectionDialog from '../../components/SelectionDialog';

interface PageProps {
    tabKeys: string[];
    searchEndpoint: string;
    summaryEndpoint: string;
    tabEndpoint: string;
    mutateEndpoint: string;
    pinyinEndpoint: string;
    canEditBasicInfo: boolean;
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
}

export default function PersonBrowserIndex() {
    const {
        searchEndpoint,
        summaryEndpoint,
        tabEndpoint,
        mutateEndpoint,
        pinyinEndpoint,
        canEditBasicInfo,
        initialPersonId,
        initialKeyword,
        initialDynasty,
        initialTab,
        initialPage,
    } = usePage<PageProps>().props;

    // ── Core state ──
    const [keyword, setKeyword] = useState(initialKeyword || '');
    const [dynasty, setDynasty] = useState(initialDynasty || '');
    const [dynastyOptions, setDynastyOptions] = useState<Array<{ c_dy: number; label: string; count: number }>>([]);
    const [page, setPage] = useState(initialPage || 1);
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
    });
    const bypassNextPopGuardRef = useRef(false);

    const readLocationState = useCallback((search: string): BrowserLocationState => {
        const params = new URLSearchParams(search);
        const personId = params.get('person_id');
        const parsedPage = parseInt(params.get('page') || '1', 10);

        return {
            personId: personId ? parseInt(personId, 10) : null,
            keyword: params.get('keyword') || '',
            dynasty: params.get('c_dy') || '',
            tab: params.get('tab') || 'basic_info',
            page: Number.isFinite(parsedPage) && parsedPage > 0 ? parsedPage : 1,
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

        return url.toString();
    }, []);

    // ── URL sync ──
    const updateUrl = useCallback(
        (params: { person_id?: number | null; keyword?: string; dynasty?: string; tab?: string; page?: number }) => {
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

            committedLocationRef.current = readLocationState(url.search);
            window.history.pushState({}, '', url.toString());
        },
        [readLocationState],
    );

    // ── Search ──
    const doSearch = useCallback(
        (q: string, p: number, dy: string = '') => {
            setListLoading(true);
            let url = `${searchEndpoint}?q=${encodeURIComponent(q)}&page=${p}&per_page=20`;
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
        setActiveTab(state.tab);
        setSelectedId(state.personId);
        doSearch(state.keyword, state.page, state.dynasty);
    }, [doSearch]);

    const handleSearch = useCallback(
        (q: string, dy: string) => {
            setKeyword(q);
            setDynasty(dy);
            setPage(1);
            doSearch(q, 1, dy);
            updateUrl({ keyword: q, dynasty: dy, page: 1 });
        },
        [doSearch, updateUrl],
    );

    const handleClear = useCallback(() => {
        setKeyword('');
        setDynasty('');
        setPage(1);
        doSearch('', 1, '');
        updateUrl({ keyword: '', dynasty: '', page: 1 });
    }, [doSearch, updateUrl]);

    const handlePageChange = useCallback(
        (p: number) => {
            setPage(p);
            doSearch(keyword, p, dynasty);
            updateUrl({ keyword, dynasty, page: p });
        },
        [keyword, dynasty, doSearch, updateUrl],
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

    const handleBasicInfoSaved = useCallback(() => {
        setSummaryRefreshKey((prev) => prev + 1);
        doSearch(keyword, page, dynasty);
    }, [doSearch, keyword, page, dynasty]);

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

    // ── Load summary when selectedId changes ──
    useEffect(() => {
        if (!selectedId) {
            setSummary(null);
            return;
        }
        setSummaryLoading(true);
        setSummaryError(null);
        const url = summaryEndpoint.replace('__PERSON_ID__', String(selectedId));
        fetch(url)
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((data) => {
                setSummary(data);
            })
            .catch((err) => {
                setSummaryError(err.message || '載入失敗');
                setSummary(null);
            })
            .finally(() => setSummaryLoading(false));
    }, [selectedId, summaryEndpoint, summaryRefreshKey]);

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
            doSearch(initialKeyword || '', initialPage || 1, initialDynasty || '');
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
        <AppShell>
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
                            <div style={mobileSidebarTitleStyle}>人物列表</div>
                            <button
                                type="button"
                                style={mobileSidebarCloseButtonStyle}
                                onClick={() => setSidebarOpen(false)}
                            >
                                收起
                            </button>
                        </div>
                    ) : null}
                    <PeopleSearchPanel keyword={keyword} dynasty={dynasty} dynastyOptions={dynastyOptions} onSearch={handleSearch} onClear={handleClear} />
                    <PeopleList
                        people={people}
                        pagination={pagination}
                        selectedId={selectedId}
                        loading={listLoading}
                        onSelect={guardedHandleSelect}
                        onPageChange={handlePageChange}
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
                                人物列表
                            </button>
                            <div style={mobileToolbarMetaStyle}>
                                {selectedId ? `人物 #${selectedId}` : '尚未選擇人物'}
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
                            pinyinEndpoint={pinyinEndpoint}
                            canEditBasicInfo={canEditBasicInfo}
                            postCE={summary?.dynasty_start != null && summary.dynasty_start > 0}
                            onSelectPerson={guardedHandleSelect}
                            onBasicInfoSaved={handleBasicInfoSaved}
                            onBasicInfoEditorStateChange={setBasicInfoEditorState}
                            onRegisterBasicInfoSaveHandler={registerBasicInfoSaveHandler}
                        />
                    </div>
                </div>
            </div>

            <SelectionDialog
                isOpen={showUnsavedDialog}
                title="尚有未儲存的修改"
                width={560}
                onClose={handleStayOnPage}
                footer={(
                    <>
                        <button type="button" style={dialogSecondaryButtonStyle} onClick={handleStayOnPage}>
                            留在本頁
                        </button>
                        <button type="button" style={dialogNeutralButtonStyle} onClick={handleDiscardAndContinue}>
                            不儲存並離開
                        </button>
                        <button
                            type="button"
                            style={dialogPrimaryButtonStyle}
                            onClick={() => {
                                void handleSaveAndContinue();
                            }}
                            disabled={savingBeforeNavigate || !basicInfoEditorState.dirty}
                        >
                            {savingBeforeNavigate ? '儲存中…' : '儲存並繼續'}
                        </button>
                    </>
                )}
            >
                <div style={dialogBodyTextStyle}>
                    目前的人物基本信息仍在編輯中，且有未儲存的修改。如果你希望保留這次修改，請先儲存，再切換到其他人物或頁籤。
                </div>
            </SelectionDialog>
        </AppShell>
    );
}

// ── Styles ──

const MOBILE_BREAKPOINT = 960;

const wrapperStyle: React.CSSProperties = {
    display: 'flex',
    height: 'calc(100vh - 100px)',
    overflow: 'hidden',
};

const sidebarStyle: React.CSSProperties = {
    width: 320,
    minWidth: 240,
    maxWidth: 400,
    borderRight: '1px solid #dee2e6',
    backgroundColor: '#fff',
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
    borderRight: '1px solid #d6dde6',
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
    borderBottom: '1px solid #dee2e6',
    backgroundColor: '#f8fafc',
};

const mobileSidebarTitleStyle: React.CSSProperties = {
    fontSize: '0.95rem',
    fontWeight: 700,
    color: '#203548',
};

const mobileSidebarCloseButtonStyle: React.CSSProperties = {
    border: '1px solid #cdd6df',
    backgroundColor: '#fff',
    color: '#445566',
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
    backgroundColor: '#f4f6f9',
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
    border: '1px solid #c9d5e2',
    backgroundColor: '#fff',
    color: '#204467',
    borderRadius: 999,
    padding: '7px 12px',
    fontSize: '0.83rem',
    fontWeight: 700,
    cursor: 'pointer',
};

const mobileToolbarMetaStyle: React.CSSProperties = {
    color: '#5f7081',
    fontSize: '0.82rem',
    fontWeight: 600,
};

const tabContentStyle: React.CSSProperties = {
    flex: 1,
    overflowY: 'auto',
    padding: 16,
};

const dialogBodyTextStyle: React.CSSProperties = {
    color: '#475569',
    lineHeight: 1.7,
};

const dialogPrimaryButtonStyle: React.CSSProperties = {
    borderRadius: 8,
    padding: '8px 14px',
    border: '1px solid #255f93',
    backgroundColor: '#255f93',
    color: '#fff',
    fontWeight: 700,
    cursor: 'pointer',
};

const dialogSecondaryButtonStyle: React.CSSProperties = {
    borderRadius: 8,
    padding: '8px 14px',
    border: '1px solid #cbd5e1',
    backgroundColor: '#fff',
    color: '#475569',
    fontWeight: 700,
    cursor: 'pointer',
};

const dialogNeutralButtonStyle: React.CSSProperties = {
    borderRadius: 8,
    padding: '8px 14px',
    border: '1px solid #d7c3c3',
    backgroundColor: '#fff7f7',
    color: '#9f2f2f',
    fontWeight: 700,
    cursor: 'pointer',
};
