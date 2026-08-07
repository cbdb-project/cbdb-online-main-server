import React, { useEffect, useState } from 'react';
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
import {
    activationKeyOf,
    applyError,
    applySuccess,
    beginActivation,
    dropTab,
    type TabCache,
} from './tabCachePolicy';

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

/* eslint-disable-next-line @typescript-eslint/no-explicit-any */
type TypedTabComponent = React.ComponentType<{ data: any; canEdit: boolean; postCE?: boolean; onSelectPerson?: (personId: number) => void }>;

const TAB_COMPONENTS: Record<string, TypedTabComponent> = {
    alt_names: AltNamesTab,
    addresses: AddressesTab,
};

/**
 * 根據 activeTab 和 personId 載入對應 tab 資料。
 *
 * **每次切分頁都會重新向後端取資料**（見 tabCachePolicy）：分頁資料不再永久沿用首次載入的快照，
 * 否則其他人、或自己在另一個瀏覽器分頁的新增／刪除／修改，在不整頁重載的情況下永遠看不到。
 * 已有舊資料的分頁在重新驗證期間先繼續顯示舊資料，不閃載入佔位；`basic_info` 例外（其編輯器
 * 在掛載時快照欄位值，必須等新資料才渲染）。
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
    const [cache, setCache] = useState<TabCache>({});
    const [fetchSeq, setFetchSeq] = useState(0);
    const [cachePerson, setCachePerson] = useState<number | null>(personId);
    const [activation, setActivation] = useState<string | null>(null);

    // 一次「分頁啟用」＝抓取 effect 的任一依賴改變。每次啟用都會重新抓資料（見下方 effect）。
    // key 的組成必須與 effect 依賴完全一致，否則會出現「effect 重跑但沿用同一戳記」的漏洞。
    const activationKey = activationKeyOf(personId, activeTab, fetchSeq, tabEndpoint);

    // 標記啟用起始狀態刻意放在 render 期間、而非 effect 裡：effect 晚一拍會讓 basic_info 先以舊資料
    // 掛載一次 BasicInfoEditor（閃動 + 多跑一輪 onRegisterSaveHandler／onEditorStateChange）再被替換。
    // render 期間 setState 是 React 允許的「隨 props 調整 state」用法；條件下一輪即為 false，不會無限迴圈。
    // 同時以區域變數 effectiveCache 讓本次渲染直接反映，不必等下一輪。
    let effectiveCache = cache;

    // 人物切換：整批丟棄舊人物的快取（其他分頁的資料也不再屬於這個人）。
    if (cachePerson !== personId) {
        setCachePerson(personId);
        if (Object.keys(cache).length > 0) {
            setCache({});
        }
        effectiveCache = {};
    }

    if (activation !== activationKey) {
        setActivation(activationKey);
        setCache((prev) => beginActivation(prev, activeTab, activationKey));
        effectiveCache = beginActivation(effectiveCache, activeTab, activationKey);
    }

    // 每次啟用都重新抓資料——不再有「已快取就跳過」的短路。
    // （舊版的短路旗標是在 setCache 的 updater 裡設定的，而 React 只在 eager state 快路徑才會同步呼叫
    //  updater，於是 13 個分頁裡有 7～8 個沿用舊快取、5～6 個湊巧重抓，行為並不確定。）
    useEffect(() => {
        if (personId == null || !activeTab) return;

        // 本輪的啟用識別碼。落庫由 applySuccess／applyError 比對快取裡的 activation 戳記——
        // 已被新啟用取代的回應原封不動丟掉（abort 在 cleanup 才發生，仍有「新一輪已 commit、
        // cleanup 未跑」的空隙）。比對在 updater 內對 committed state 進行，不靠 render 期間的 ref。
        const myActivation = activationKeyOf(personId, activeTab, fetchSeq, tabEndpoint);
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
                setCache((prev) => applySuccess(prev, activeTab, myActivation, data));
            })
            .catch((err: unknown) => {
                if (err instanceof DOMException && err.name === 'AbortError') {
                    return;
                }

                // 只存技術細節（HTTP 狀態等）；使用者看到的「載入失敗」字樣由 render 端翻譯後加上，
                // 這樣 effect 不必依賴 tPerson——依賴多一項而 activationKey 少一項就會產生共用戳記的漏洞。
                const message = err instanceof Error ? err.message : '';

                setCache((prev) => applyError(prev, activeTab, myActivation, message));
            });

        return () => {
            controller.abort();
        };
    }, [personId, activeTab, tabEndpoint, fetchSeq]);

    const retryActiveTab = () => {
        setCache((prev) => dropTab(prev, activeTab));
        setFetchSeq((s) => s + 1);
    };

    // 12 個子資源分頁新增/刪除成功後的統一 onRefresh：除重新載入該分頁列表外，
    // 一併通知上層刷新分頁徽章數字（summary.tab_counts），修正「新增/刪除後分頁數字未即時更新」問題。
    const refreshTabAndSummary = () => {
        retryActiveTab();
        onSubresourceChanged?.();
    };

    if (personId == null) {
        return null;
    }

    const state = effectiveCache[activeTab];

    // 這幾個提示原為硬編碼中文。切分頁改為一律重抓後，載入佔位與失敗提示的出現頻率大增
    // （尤其 basic_info 每次回訪都會短暫顯示），英文語境不能再落回中文，故改走 person 翻譯。
    if (!state || state.loading) {
        return <div style={msgStyle}>{tPerson('loading')}</div>;
    }

    if (state.error != null) {
        return (
            <div style={{ ...msgStyle, color: 'var(--destructive)' }}>
                <div>{tPerson('load_failed')}{state.error ? `：${state.error}` : ''}</div>
                <button type="button" style={retryButtonStyle} onClick={retryActiveTab}>
                    {tPerson('reload')}
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
                    // 儲存成功後只通知上層刷新摘要計數／標題；不必另外刷 basic_info 快取——
                    // 編輯器保有剛存下的值，而下一次切回本分頁本來就會重新抓資料。
                    onSaved={onBasicInfoSaved}
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
    return <div style={msgStyle}>{tPerson('unsupported_tab')}：{activeTab}</div>;
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
