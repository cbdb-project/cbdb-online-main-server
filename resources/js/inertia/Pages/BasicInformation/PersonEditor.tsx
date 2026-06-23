import React, { useState, useCallback, useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { useTranslation } from '../../hooks/useTranslation';
import { registerDirtyChecker } from '../../hooks/useDirtyGuard';
import type { PersonSummary } from '../../components/PersonBrowser/PersonSummaryPanel';
import TabContentLoader from '../../components/PersonBrowser/TabContentLoader';
import PersonBanner, { PersonBannerData } from '../../components/PersonEditorShared/PersonBanner';
import SelectionDialog from '../../components/SelectionDialog';

/**
 * 人物編輯主界面（PersonEditor）—— 對齊舊 /basicinformation/{id} 的「13 分頁高效錄入界面」。
 *
 * 與 PersonBrowser/Index 共用同一組分頁引擎（BrowserTabs + TabContentLoader），但：
 *   - 聚焦單一人物（無左側搜尋/人物列表 sidebar）；
 *   - basic_info 分頁進場即可直接錄入（basicInfoStartEditing）；
 *   - 12 子資源分頁各自帶 React 錄入/編輯/刪除（由各 *EditorIsNew flag 控制）。
 * 資料端點指向「編輯者/訪客可用」的 app.basicinformation.summary/.tab（非 superadmin-only）。
 */
interface PageProps {
    [key: string]: unknown;
    personId: number;
    person_label: string;
    initialTab: string;
    index_url: string;
    person_banner: PersonBannerData;
    summaryEndpoint: string;
    tabEndpoint: string;
    mutateEndpoint: string;
    createEndpoint: string;
    deleteEndpoint: string;
    pinyinEndpoint: string;
    canEditBasicInfo: boolean;
    canProposeEdits: boolean;
    basicInfoEditorIsNew: boolean;
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
}

export default function PersonEditor() {
    const {
        personId,
        person_label,
        initialTab,
        index_url,
        summaryEndpoint,
        person_banner,
        tabEndpoint,
        mutateEndpoint,
        createEndpoint,
        deleteEndpoint,
        pinyinEndpoint,
        canEditBasicInfo,
        canProposeEdits,
        basicInfoEditorIsNew,
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
    } = usePage<PageProps>().props;

    const tPerson = useTranslation('person');

    const [summary, setSummary] = useState<PersonSummary | null>(null);
    const [summaryLoading, setSummaryLoading] = useState(false);
    const [summaryError, setSummaryError] = useState<string | null>(null);
    const [activeTab, setActiveTab] = useState(initialTab || 'basic_info');
    const [summaryRefreshKey, setSummaryRefreshKey] = useState(0);
    const [basicInfoEditorState, setBasicInfoEditorState] = useState({ editing: false, dirty: false });
    const [showUnsavedDialog, setShowUnsavedDialog] = useState(false);
    const [savingBeforeNavigate, setSavingBeforeNavigate] = useState(false);

    const basicInfoSaveHandlerRef = useRef<(() => Promise<boolean>) | null>(null);
    const pendingNavigationRef = useRef<(() => void) | null>(null);

    // ── 髒值守衛（語言切換用）──
    const dirtyRef = useRef(false);
    dirtyRef.current = basicInfoEditorState.dirty;
    useEffect(() => registerDirtyChecker(() => dirtyRef.current), []);

    // ── 載入摘要（header + tab_counts）──
    useEffect(() => {
        setSummaryLoading(true);
        setSummaryError(null);
        const url = summaryEndpoint.replace('__PERSON_ID__', String(personId));
        fetch(url)
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((data) => setSummary(data))
            .catch((err) => {
                setSummaryError(err instanceof Error ? err.message : tPerson('load_failed'));
                setSummary(null);
            })
            .finally(() => setSummaryLoading(false));
    }, [personId, summaryEndpoint, summaryRefreshKey, tPerson]);

    // ── 離頁守衛（編輯中有未儲存變更時）──
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

    const runOrWarnUnsaved = useCallback((action: () => void) => {
        if (basicInfoEditorState.dirty) {
            pendingNavigationRef.current = action;
            setShowUnsavedDialog(true);
            return;
        }
        action();
    }, [basicInfoEditorState.dirty]);

    const updateTabUrl = useCallback((tab: string) => {
        const url = new URL(window.location.href);
        if (tab && tab !== 'basic_info') {
            url.searchParams.set('tab', tab);
        } else {
            url.searchParams.delete('tab');
        }
        window.history.replaceState({}, '', url.toString());
    }, []);

    const handleTabChange = useCallback((tab: string) => {
        runOrWarnUnsaved(() => {
            setActiveTab(tab);
            updateTabUrl(tab);
        });
    }, [runOrWarnUnsaved, updateTabUrl]);

    const handleBasicInfoSaved = useCallback(() => {
        setSummaryRefreshKey((prev) => prev + 1);
    }, []);

    const registerBasicInfoSaveHandler = useCallback((handler: (() => Promise<boolean>) | null) => {
        basicInfoSaveHandlerRef.current = handler;
    }, []);

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

    return (
        <DashboardLayout
            title={person_label}
            breadcrumbs={[
                { label: tPerson('person_records'), url: index_url },
                // 末段隨當前分頁切換（人物記錄 / 著述 …），使頂部反映正在檢視的子資源，
                // 不再固定停留在「基本資料」造成誤解。
                { label: tPerson(`tab_${activeTab}`) },
            ]}
        >
            <PersonBanner
                data={{ ...person_banner, active_tab: activeTab, counts: summary?.tab_counts ?? person_banner.counts }}
                onTabSelect={handleTabChange}
            />

            <div style={tabsWrapStyle}>
                <div style={tabContentStyle}>
                    <TabContentLoader
                        personId={personId}
                        activeTab={activeTab}
                        tabEndpoint={tabEndpoint}
                        mutateEndpoint={mutateEndpoint}
                        createEndpoint={createEndpoint}
                        deleteEndpoint={deleteEndpoint}
                        pinyinEndpoint={pinyinEndpoint}
                        canEditBasicInfo={canEditBasicInfo}
                        canProposeEdits={canProposeEdits}
                        basicInfoEditorIsNew={basicInfoEditorIsNew}
                        basicInfoStartEditing={!basicInfoEditorIsNew}
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
                        onBasicInfoSaved={handleBasicInfoSaved}
                        onBasicInfoEditorStateChange={setBasicInfoEditorState}
                        onRegisterBasicInfoSaveHandler={registerBasicInfoSaveHandler}
                    />
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

const tabsWrapStyle: React.CSSProperties = {
    marginTop: 12,
    backgroundColor: '#fff',
    border: '1px solid #dee2e6',
    borderRadius: 10,
    overflow: 'hidden',
};

const tabContentStyle: React.CSSProperties = {
    padding: 16,
    backgroundColor: '#f4f6f9',
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
