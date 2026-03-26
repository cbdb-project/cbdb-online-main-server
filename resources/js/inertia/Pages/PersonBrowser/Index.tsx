import React, { useState, useCallback, useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import AppShell from '../../Layouts/AppShell';
import PeopleSearchPanel from '../../components/PersonBrowser/PeopleSearchPanel';
import PeopleList, { PersonListItem, Pagination } from '../../components/PersonBrowser/PeopleList';
import PersonSummaryPanel, { PersonSummary } from '../../components/PersonBrowser/PersonSummaryPanel';
import BrowserTabs, { TAB_DEFINITIONS } from '../../components/PersonBrowser/BrowserTabs';
import TabContentLoader from '../../components/PersonBrowser/TabContentLoader';

interface PageProps {
    tabKeys: string[];
    searchEndpoint: string;
    summaryEndpoint: string;
    tabEndpoint: string;
    initialPersonId: number | null;
    initialKeyword: string;
    initialTab: string;
}

export default function PersonBrowserIndex() {
    const {
        searchEndpoint,
        summaryEndpoint,
        tabEndpoint,
        initialPersonId,
        initialKeyword,
        initialTab,
    } = usePage<PageProps>().props;

    // ── Core state ──
    const [keyword, setKeyword] = useState(initialKeyword || '');
    const [page, setPage] = useState(1);
    const [people, setPeople] = useState<PersonListItem[]>([]);
    const [pagination, setPagination] = useState<Pagination | null>(null);
    const [listLoading, setListLoading] = useState(false);

    const [selectedId, setSelectedId] = useState<number | null>(initialPersonId);
    const [summary, setSummary] = useState<PersonSummary | null>(null);
    const [summaryLoading, setSummaryLoading] = useState(false);
    const [summaryError, setSummaryError] = useState<string | null>(null);

    const [activeTab, setActiveTab] = useState(initialTab || 'basic_info');

    const isInitialMount = useRef(true);

    // ── URL sync ──
    const updateUrl = useCallback(
        (params: { person_id?: number | null; keyword?: string; tab?: string; page?: number }) => {
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
            if (params.tab) url.searchParams.set('tab', params.tab);
            if (params.page && params.page > 1) url.searchParams.set('page', String(params.page));
            else url.searchParams.delete('page');

            window.history.pushState({}, '', url.toString());
        },
        [],
    );

    // ── Search ──
    const doSearch = useCallback(
        (q: string, p: number) => {
            setListLoading(true);
            const url = `${searchEndpoint}?q=${encodeURIComponent(q)}&page=${p}&per_page=20`;
            fetch(url)
                .then((r) => r.json())
                .then((json) => {
                    setPeople(json.data || []);
                    setPagination(json.pagination || null);
                })
                .catch(() => {
                    setPeople([]);
                    setPagination(null);
                })
                .finally(() => setListLoading(false));
        },
        [searchEndpoint],
    );

    const handleSearch = useCallback(
        (q: string) => {
            setKeyword(q);
            setPage(1);
            doSearch(q, 1);
            updateUrl({ keyword: q, page: 1 });
        },
        [doSearch, updateUrl],
    );

    const handleClear = useCallback(() => {
        setKeyword('');
        setPage(1);
        doSearch('', 1);
        updateUrl({ keyword: '', page: 1 });
    }, [doSearch, updateUrl]);

    const handlePageChange = useCallback(
        (p: number) => {
            setPage(p);
            doSearch(keyword, p);
            updateUrl({ keyword, page: p });
        },
        [keyword, doSearch, updateUrl],
    );

    // ── Select person ──
    const handleSelect = useCallback(
        (personId: number) => {
            setSelectedId(personId);
            updateUrl({ person_id: personId, keyword, tab: activeTab });
        },
        [activeTab, keyword, updateUrl],
    );

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
    }, [selectedId, summaryEndpoint]);

    // ── Tab change ──
    const handleTabChange = useCallback(
        (tab: string) => {
            setActiveTab(tab);
            updateUrl({ person_id: selectedId, keyword, tab });
        },
        [selectedId, keyword, updateUrl],
    );

    // ── Initial load ──
    useEffect(() => {
        if (isInitialMount.current) {
            isInitialMount.current = false;
            doSearch(initialKeyword || '', 1);
        }
    }, [doSearch, initialKeyword]);

    // ── Browser back/forward ──
    useEffect(() => {
        const onPop = () => {
            const params = new URLSearchParams(window.location.search);
            const pid = params.get('person_id');
            const kw = params.get('keyword') || '';
            const tab = params.get('tab') || 'basic_info';
            const pg = parseInt(params.get('page') || '1', 10);
            setKeyword(kw);
            setPage(pg);
            setActiveTab(tab);
            if (pid) setSelectedId(parseInt(pid, 10));
            else setSelectedId(null);
            doSearch(kw, pg);
        };
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, [doSearch]);

    return (
        <AppShell>
            <div style={wrapperStyle}>
                {/* Left sidebar */}
                <aside style={sidebarStyle}>
                    <PeopleSearchPanel keyword={keyword} onSearch={handleSearch} onClear={handleClear} />
                    <PeopleList
                        people={people}
                        pagination={pagination}
                        selectedId={selectedId}
                        loading={listLoading}
                        onSelect={handleSelect}
                        onPageChange={handlePageChange}
                    />
                </aside>

                {/* Right main area */}
                <div style={mainStyle}>
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
                        />
                    </div>
                </div>
            </div>
        </AppShell>
    );
}

// ── Styles ──

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

const mainStyle: React.CSSProperties = {
    flex: 1,
    display: 'flex',
    flexDirection: 'column',
    overflow: 'hidden',
    backgroundColor: '#f4f6f9',
};

const tabContentStyle: React.CSSProperties = {
    flex: 1,
    overflowY: 'auto',
    padding: 16,
};
