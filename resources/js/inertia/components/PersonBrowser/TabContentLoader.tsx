import React, { useEffect, useRef, useState } from 'react';
import BasicInfoView from './BasicInfoView';
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
    pinyinEndpoint: string;
    canEditBasicInfo: boolean;
    postCE?: boolean;
    onSelectPerson?: (personId: number) => void;
    onBasicInfoSaved?: () => void;
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
    entries: EntriesTab,
    statuses: StatusesTab,
    events: EventsTab,
    associations: AssociationsTab,
    possessions: PossessionsTab,
    sources: SourcesTab,
    texts: TextsTab,
    postings: PostingsTab,
    social_institutions: InstitutionsTab,
    kinship: KinshipTab,
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
    pinyinEndpoint,
    canEditBasicInfo,
    postCE = false,
    onSelectPerson,
    onBasicInfoSaved,
    onBasicInfoEditorStateChange,
    onRegisterBasicInfoSaveHandler,
}: Props) {
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

    if (personId == null) {
        return null;
    }

    const state = effectiveCache[activeTab];

    if (!state || state.loading) {
        return <div style={msgStyle}>載入中…</div>;
    }

    if (state.error) {
        return (
            <div style={{ ...msgStyle, color: '#dc3545' }}>
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

        return (
            <BasicInfoView
                sections={basicData?.sections || []}
                form={basicData?.form || null}
                personId={personId}
                mutateEndpoint={mutateEndpoint}
                pinyinEndpoint={pinyinEndpoint}
                canEdit={canEditBasicInfo}
                onEditorStateChange={onBasicInfoEditorStateChange}
                onRegisterSaveHandler={onRegisterBasicInfoSaveHandler}
                onSaved={() => {
                    retryActiveTab();
                    onBasicInfoSaved?.();
                }}
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
    color: '#6c757d',
    fontSize: '0.875rem',
};

const retryButtonStyle: React.CSSProperties = {
    marginTop: 12,
    padding: '6px 12px',
    border: '1px solid #dc3545',
    borderRadius: 4,
    backgroundColor: '#fff',
    color: '#dc3545',
    cursor: 'pointer',
};
