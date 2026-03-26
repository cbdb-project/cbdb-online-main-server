import React, { useEffect, useState, useRef } from 'react';
import BasicInfoView from './BasicInfoView';
import RepeatedFormCards from './RepeatedFormCards';

interface Props {
    personId: number | null;
    activeTab: string;
    tabEndpoint: string;
    mutateEndpoint: string;
    pinyinEndpoint: string;
    onBasicInfoSaved?: () => void;
}

interface TabState {
    loading: boolean;
    error: string | null;
    data: unknown;
}

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
    onBasicInfoSaved,
}: Props) {
    const [cache, setCache] = useState<Record<string, TabState>>({});
    const prevPersonRef = useRef<number | null>(null);

    // 人物切換時清空快取
    useEffect(() => {
        if (prevPersonRef.current !== personId) {
            setCache({});
            prevPersonRef.current = personId;
        }
    }, [personId]);

    // lazy load
    useEffect(() => {
        if (!personId || !activeTab) return;
        if (cache[activeTab]) return;

        const url = tabEndpoint
            .replace('__PERSON_ID__', String(personId))
            .replace('__TAB_KEY__', activeTab);

        setCache((prev) => ({ ...prev, [activeTab]: { loading: true, error: null, data: null } }));

        const controller = new AbortController();

        fetch(url)
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
    }, [personId, activeTab, tabEndpoint, cache]);

    const retryActiveTab = () => {
        setCache((prev) => {
            const next = { ...prev };
            delete next[activeTab];

            return next;
        });
    };

    if (!personId) {
        return <div style={msgStyle}>請先選擇人物</div>;
    }

    const state = cache[activeTab];

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

    // Render based on tab type
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
                onSaved={() => {
                    retryActiveTab();
                    onBasicInfoSaved?.();
                }}
            />
        );
    }

    // All other tabs: repeated-form cards
    const listData = state.data as { columns?: Record<string, string>; rows?: Record<string, unknown>[] };
    return <RepeatedFormCards columns={listData?.columns || {}} rows={listData?.rows || []} />;
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
