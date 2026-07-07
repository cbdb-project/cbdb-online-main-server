import React, { useEffect, useRef, useState } from 'react';
import BasicInfoView from './BasicInfoView';
import BasicInfoEditor from '../BasicInfoEditor';
import { useTranslation } from '../../hooks/useTranslation';
import AltNamesTab from './tabs/AltNamesTab';
import AddressesTab from './tabs/AddressesTab';
import EntriesTab from './tabs/EntriesTab';
import StatusesTab from './tabs/StatusesTab';
import EventsTab from './tabs/EventsTab';
import AssociationsTab from './tabs/AssociationsTab';
import PossessionsTab from './tabs/PossessionsTab';
import SourcesTab from './tabs/SourcesTab';
import TextsTab from './tabs/TextsTab';
import PostingsTab from './tabs/PostingsTab';
import InstitutionsTab from './tabs/InstitutionsTab';
import KinshipTab from './tabs/KinshipTab';

interface Props {
    personId: number | null;
    activeTab: string;
    tabEndpoint: string;
    mutateEndpoint: string;
    createEndpoint?: string;
    deleteEndpoint?: string;
    pinyinEndpoint: string;
    canEditBasicInfo: boolean;
    canProposeEdits?: boolean;
    /** basic_info 分頁進場即進入編輯狀態（編輯主界面用；PersonBrowser 不傳 → 維持原行為）。 */
    basicInfoStartEditing?: boolean;
    /** flag=new 時 basic_info 改為「檢視 + 編輯按鈕導向獨立 BasicInfoEditor（含年號轉換）」。 */
    basicInfoEditorIsNew?: boolean;
    altnameEditorIsNew?: boolean;
    addressesEditorIsNew?: boolean;
    textsEditorIsNew?: boolean;
    sourcesEditorIsNew?: boolean;
    officesEditorIsNew?: boolean;
    assocEditorIsNew?: boolean;
    kinshipEditorIsNew?: boolean;
    eventsEditorIsNew?: boolean;
    entriesEditorIsNew?: boolean;
    statusesEditorIsNew?: boolean;
    possessionEditorIsNew?: boolean;
    socialInstEditorIsNew?: boolean;
    postCE?: boolean;
    onSelectPerson?: (personId: number) => void;
    onBasicInfoSaved?: () => void;
    /** 12 個子資源分頁新增/刪除成功後呼叫：除刷新該分頁列表外，一併通知上層刷新分頁徽章數字（tab_counts）。 */
    onSubresourceChanged?: () => void;
    onBasicInfoEditorStateChange?: (state: { editing: boolean; dirty: boolean }) => void;
    onRegisterBasicInfoSaveHandler?: (handler: (() => Promise<boolean>) | null) => void;
}

interface TabState {
    loading: boolean;
    error: string | null;
    data: unknown;
}

/* eslint-disable-next-line @typescript-eslint/no-explicit-any */
type TypedTabComponent = React.ComponentType<{ data: any; canEdit: boolean; postCE?: boolean; onSelectPerson?: (personId: number) => void }>;

const TAB_COMPONENTS: Record<string, TypedTabComponent> = {
    alt_names: AltNamesTab,
    addresses: AddressesTab,
};

/**
 * 根據 activeTab 和 personId lazy load 對應 tab 資料。
 * 已載入的 tab 資料會快取（同一 personId 不重複請求）。
 */
export default function TabContentLoader({
    personId,
    activeTab,
    tabEndpoint,
    mutateEndpoint,
    createEndpoint = '',
    deleteEndpoint = '',
    pinyinEndpoint,
    canEditBasicInfo,
    canProposeEdits = false,
    basicInfoStartEditing = false,
    basicInfoEditorIsNew = false,
    altnameEditorIsNew = false,
    addressesEditorIsNew = false,
    textsEditorIsNew = false,
    sourcesEditorIsNew = false,
    officesEditorIsNew = false,
    assocEditorIsNew = false,
    kinshipEditorIsNew = false,
    eventsEditorIsNew = false,
    entriesEditorIsNew = false,
    statusesEditorIsNew = false,
    possessionEditorIsNew = false,
    socialInstEditorIsNew = false,
    postCE = false,
    onSelectPerson,
    onBasicInfoSaved,
    onSubresourceChanged,
    onBasicInfoEditorStateChange,
    onRegisterBasicInfoSaveHandler,
}: Props) {
    // basic_info 內嵌 BasicInfoEditor 用的翻譯（biogmains→person→common 鏈，隨 locale 切換）。
    const tBio = useTranslation('biogmains');
    const tPerson = useTranslation('person');
    const tCommon = useTranslation('common');
    const tEditor = (k: string): string => {
        const v = tBio(k); if (v && v !== k) return v;
        const v2 = tPerson(k); if (v2 && v2 !== k) return v2;
        const v3 = tCommon(k); return v3 && v3 !== k ? v3 : k;
    };
    const [cache, setCache] = useState<Record<string, TabState>>({});
    const [fetchSeq, setFetchSeq] = useState(0);
    const cachePersonRef = useRef<number | null>(personId);

    // 人物切換時同步清快取，本次渲染直接使用空值
    const personChanged = cachePersonRef.current !== personId;
    if (personChanged) {
        cachePersonRef.current = personId;
        if (Object.keys(cache).length > 0) {
            setCache({});
        }
    }

    const effectiveCache = personChanged ? {} : cache;

    // lazy load — 由 personId / activeTab / fetchSeq 驅動
    useEffect(() => {
        if (personId == null || !activeTab) return;

        // 透過 updater 讀取最新 cache，避免把 cache 引用放進依賴
        let alreadyCached = false;
        setCache((prev) => {
            if (prev[activeTab]) {
                alreadyCached = true;
                return prev;
            }
            return { ...prev, [activeTab]: { loading: true, error: null, data: null } };
        });
        if (alreadyCached) return;

        const url = tabEndpoint
            .replace('__PERSON_ID__', String(personId))
            .replace('__TAB_KEY__', activeTab);

        const controller = new AbortController();

        fetch(url, { signal: controller.signal })
            .then((res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then((data) => {
                setCache((prev) => ({ ...prev, [activeTab]: { loading: false, error: null, data } }));
            })
            .catch((err: unknown) => {
                if (err instanceof DOMException && err.name === 'AbortError') {
                    return;
                }

                const message = err instanceof Error ? err.message : '載入失敗';

                setCache((prev) => ({
                    ...prev,
                    [activeTab]: { loading: false, error: message, data: null },
                }));
            });

        return () => {
            controller.abort();
        };
    }, [personId, activeTab, tabEndpoint, fetchSeq]);

    const retryActiveTab = () => {
        setCache((prev) => {
            const next = { ...prev };
            delete next[activeTab];
            return next;
        });
        setFetchSeq((s) => s + 1);
    };

    // 12 個子資源分頁新增/刪除成功後的統一 onRefresh：除重新載入該分頁列表外，
    // 一併通知上層刷新分頁徽章數字（summary.tab_counts），修正「新增/刪除後分頁數字未即時更新」問題。
    const refreshTabAndSummary = () => {
        retryActiveTab();
        onSubresourceChanged?.();
    };

    // 靜默刷新指定分頁的快取資料：於背景重新抓取並就地更新 cache[tabKey].data，
    // 不觸發 loading 態、不卸載當前掛載的元件（其 state 為當前真相），
    // 以便使用者切換分頁後再回來時「重新掛載」讀到最新資料，而非最初載入的舊快照。
    // 失敗時保留既有快取，避免把已顯示的資料清成錯誤態。
    const refreshTabCache = (tabKey: string) => {
        if (personId == null || !tabKey) return;
        const url = tabEndpoint
            .replace('__PERSON_ID__', String(personId))
            .replace('__TAB_KEY__', tabKey);
        fetch(url)
            .then((res) => {
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                return res.json();
            })
            .then((data) => {
                setCache((prev) => ({ ...prev, [tabKey]: { loading: false, error: null, data } }));
            })
            .catch(() => {
                /* 保留既有快取 */
            });
    };

    if (personId == null) {
        return null;
    }

    const state = effectiveCache[activeTab];

    if (!state || state.loading) {
        return <div style={msgStyle}>載入中…</div>;
    }

    if (state.error) {
        return (
            <div style={{ ...msgStyle, color: 'var(--destructive)' }}>
                <div>載入失敗：{state.error}</div>
                <button type="button" style={retryButtonStyle} onClick={retryActiveTab}>
                    重新載入
                </button>
            </div>
        );
    }

    // basic_info: 專門 view（含編輯功能）
    if (activeTab === 'basic_info') {
        const basicData = state.data as {
            sections?: Array<{ title: string; fields: Array<{ label: string; value: unknown }> }>;
            form?: {
                person_id: number;
                fields: Record<string, unknown>;
            };
        };

        // flag=new：直接內嵌獨立 BasicInfoEditor（落地即可編輯，含年號轉換），對齊 legacy
        // /basicinformation/{id}/edit「打開即錄入」；不再「檢視＋編輯按鈕跳轉」。
        if (basicInfoEditorIsNew && personId != null) {
            const ff = (basicData?.form?.fields ?? {}) as Record<string, unknown>;
            const initialFields: Record<string, string> = {};
            const initialLabels: Record<string, string> = {};
            for (const [k, f] of Object.entries(ff)) {
                if (f !== null && typeof f === 'object') {
                    const obj = f as { value?: unknown; display_value?: unknown };
                    initialFields[k] = obj.value == null ? '' : String(obj.value);
                    if (obj.display_value != null && obj.display_value !== '') initialLabels[k] = String(obj.display_value);
                } else {
                    initialFields[k] = f == null ? '' : String(f);
                }
            }
            return (
                <BasicInfoEditor
                    personId={personId}
                    personLabel=""
                    initialFields={initialFields}
                    initialLabels={initialLabels}
                    canEdit={canEditBasicInfo}
                    canPropose={canProposeEdits}
                    mutateEndpoint={mutateEndpoint}
                    deleteEndpoint={deleteEndpoint}
                    pinyinEndpoint={pinyinEndpoint}
                    indexUrl={`/app/basicinformation/${personId}?tab=basic_info`}
                    duplicateCollateralUrl={`/basicinformation/${personId}/Duplicate_Collateral_Info`}
                    saveasUrl={`/basicinformation/${personId}/saveas`}
                    t={tEditor}
                    onEditorStateChange={onBasicInfoEditorStateChange}
                    onRegisterSaveHandler={onRegisterBasicInfoSaveHandler}
                    onSaved={() => {
                        // 儲存成功後靜默刷新 basic_info 快取（修：切分頁再切回顯示舊值），
                        // 並通知上層刷新摘要計數。
                        refreshTabCache('basic_info');
                        onBasicInfoSaved?.();
                    }}
                />
            );
        }

        return (
            <BasicInfoView
                sections={basicData?.sections || []}
                form={basicData?.form || null}
                personId={personId}
                mutateEndpoint={mutateEndpoint}
                pinyinEndpoint={pinyinEndpoint}
                canEdit={canEditBasicInfo}
                startEditing={basicInfoStartEditing}
                onEditorStateChange={onBasicInfoEditorStateChange}
                onRegisterSaveHandler={onRegisterBasicInfoSaveHandler}
                onSaved={() => {
                    retryActiveTab();
                    onBasicInfoSaved?.();
                }}
            />
        );
    }

    // 別名分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'alt_names') {
        return (
            <AltNamesTab
                data={state.data}
                canEdit={canEditBasicInfo}
                canPropose={canProposeEdits}
                altnameEditorIsNew={altnameEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
            />
        );
    }

    // 地址分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'addresses') {
        return (
            <AddressesTab
                data={state.data}
                canEdit={canEditBasicInfo}
                postCE={postCE}
                canPropose={canProposeEdits}
                addressesEditorIsNew={addressesEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
            />
        );
    }

    // 著述分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'texts') {
        return (
            <TextsTab
                data={state.data}
                canEdit={canEditBasicInfo}
                canPropose={canProposeEdits}
                textsEditorIsNew={textsEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
            />
        );
    }

    // 出處分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'sources') {
        return (
            <SourcesTab
                data={state.data}
                canEdit={canEditBasicInfo}
                canPropose={canProposeEdits}
                sourcesEditorIsNew={sourcesEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
            />
        );
    }

    // 任官/官名分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'postings') {
        return (
            <PostingsTab
                data={state.data}
                canEdit={canEditBasicInfo}
                postCE={postCE}
                canPropose={canProposeEdits}
                officesEditorIsNew={officesEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
            />
        );
    }

    // 社會關係分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'associations') {
        return (
            <AssociationsTab
                data={state.data}
                canEdit={canEditBasicInfo}
                postCE={postCE}
                canPropose={canProposeEdits}
                assocEditorIsNew={assocEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
                onSelectPerson={onSelectPerson}
            />
        );
    }

    // 親屬關係分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'kinship') {
        return (
            <KinshipTab
                data={state.data}
                canEdit={canEditBasicInfo}
                canPropose={canProposeEdits}
                kinshipEditorIsNew={kinshipEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
                onSelectPerson={onSelectPerson}
            />
        );
    }

    // 事件分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'events') {
        return (
            <EventsTab
                data={state.data}
                canEdit={canEditBasicInfo}
                canPropose={canProposeEdits}
                eventsEditorIsNew={eventsEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
            />
        );
    }

    // 入仕分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'entries') {
        return (
            <EntriesTab
                data={state.data}
                canEdit={canEditBasicInfo}
                canPropose={canProposeEdits}
                entriesEditorIsNew={entriesEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
                onSelectPerson={onSelectPerson}
            />
        );
    }

    // 社會區分分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'statuses') {
        return (
            <StatusesTab
                data={state.data}
                canEdit={canEditBasicInfo}
                postCE={postCE}
                canPropose={canProposeEdits}
                statusesEditorIsNew={statusesEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
            />
        );
    }

    // 財產分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'possessions') {
        return (
            <PossessionsTab
                data={state.data}
                canEdit={canEditBasicInfo}
                canPropose={canProposeEdits}
                possessionEditorIsNew={possessionEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
            />
        );
    }

    // 社交機構分頁：注入 React 編輯器所需端點與遷移開關
    if (activeTab === 'social_institutions') {
        return (
            <InstitutionsTab
                data={state.data}
                canEdit={canEditBasicInfo}
                postCE={postCE}
                canPropose={canProposeEdits}
                socialInstEditorIsNew={socialInstEditorIsNew}
                personId={personId}
                createEndpoint={createEndpoint}
                mutateEndpoint={mutateEndpoint}
                deleteEndpoint={deleteEndpoint}
                onRefresh={refreshTabAndSummary}
            />
        );
    }

    // 其他 tabs：使用各自的 typed component
    const TabComponent = TAB_COMPONENTS[activeTab];
    if (TabComponent) {
        return <TabComponent data={state.data} canEdit={canEditBasicInfo} postCE={postCE} onSelectPerson={onSelectPerson} />;
    }

    // Fallback（不應出現，但作為安全後備）
    return <div style={msgStyle}>未支援的分頁：{activeTab}</div>;
}

const msgStyle: React.CSSProperties = {
    padding: 24,
    textAlign: 'center',
    color: 'var(--muted-foreground)',
    fontSize: '0.875rem',
};

const retryButtonStyle: React.CSSProperties = {
    marginTop: 12,
    padding: '6px 12px',
    border: '1px solid var(--destructive)',
    borderRadius: 4,
    backgroundColor: 'var(--card)',
    color: 'var(--destructive)',
    cursor: 'pointer',
};
