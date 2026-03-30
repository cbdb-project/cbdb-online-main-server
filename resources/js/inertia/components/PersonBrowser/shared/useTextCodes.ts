import { useEffect, useMemo, useState } from 'react';
import type { TextCodeRecord } from './textLookup';

interface LookupState {
    loading: boolean;
    error: string | null;
    records: Record<number, TextCodeRecord>;
}

interface ApiResponse {
    ok: boolean;
    data?: TextCodeRecord[];
}

export function useTextCodes(ids: Array<number | null | undefined>): LookupState {
    const normalizedIds = useMemo(
        () =>
            Array.from(
                new Set(
                    ids.filter((value): value is number => typeof value === 'number' && Number.isFinite(value) && value > 0)
                )
            ),
        [ids]
    );
    const idsKey = useMemo(() => normalizedIds.join(','), [normalizedIds]);
    const [state, setState] = useState<LookupState>({
        loading: false,
        error: null,
        records: {},
    });

    useEffect(() => {
        if (normalizedIds.length === 0) {
            setState({ loading: false, error: null, records: {} });
            return;
        }

        const controller = new AbortController();

        setState((prev) => ({
            loading: true,
            error: null,
            records: prev.records,
        }));

        const params = new URLSearchParams();
        params.set('ids', normalizedIds.join(','));

        fetch(`/api/v2/texts?${params.toString()}`, {
            signal: controller.signal,
        })
            .then((res) => {
                if (!res.ok) {
                    throw new Error(`HTTP ${res.status}`);
                }

                return res.json() as Promise<ApiResponse>;
            })
            .then((payload) => {
                const records = Object.fromEntries(
                    (payload.data ?? []).map((row) => [row.c_textid, row] as const)
                ) as Record<number, TextCodeRecord>;

                setState({
                    loading: false,
                    error: null,
                    records,
                });
            })
            .catch((error: unknown) => {
                if (error instanceof DOMException && error.name === 'AbortError') {
                    return;
                }

                const message = error instanceof Error ? error.message : '載入文獻資料失敗';

                setState((prev) => ({
                    loading: false,
                    error: message,
                    records: prev.records,
                }));
            });

        return () => controller.abort();
    }, [idsKey]);

    return state;
}
