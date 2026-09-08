import React, { useState } from 'react';
import { getCsrfToken } from '../PersonBrowser/shared/csrf';
import { FormField } from '../ui/FormField';
import { SaveSplitButton } from '../ui/SaveSplitButton';

/**
 * 實體聚合表單（Office／SocialInstitution／Text）共用的提交管線：direct／proposal 兩種模式、
 * 修改提案（resubmit）模式、422／409 欄位錯誤映射、提案說明。三個表單的欄位各不相同
 * （表單不抽象，見 docs/ENTITY_AGGREGATE_ARCHITECTURE.md §6.5 第 5 點），但「送出去之後
 * 發生什麼」完全同構，收在這裡免得三份 fetch 各自漂移。
 */

export type SubmitMode = 'direct' | 'proposal';

/** 修改提案模式（?proposal={id}）：由 controller 的 proposalResubmitProps() 提供。 */
export interface ResubmitInfo {
    resubmit_proposal_id?: number;
    initial_comment?: string;
    resubmit_endpoint?: string;
}

/** 提案存的 changes（使用者原始輸入）；表單以它覆蓋初始值。 */
export type Overlay = Record<string, unknown>;

/** 讀 overlay：鍵存在就取 overlay 值（含 null），否則回 fallback。 */
export function overlayReader(overlay?: Overlay) {
    const has = (key: string) => !!overlay && Object.prototype.hasOwnProperty.call(overlay, key);
    return {
        has,
        /** 文字欄：null／undefined → ''。 */
        str: (key: string, fallback: string | null | undefined): string => {
            const v = has(key) ? overlay![key] : fallback;
            return v == null ? '' : String(v);
        },
        /** 數字欄以字串狀態存（input value）：null／undefined → ''。 */
        num: (key: string, fallback: number | string | null | undefined): string => {
            const v = has(key) ? overlay![key] : fallback;
            return v == null || v === '' ? '' : String(v);
        },
        /** 陣列欄（版本列／地址列／類型 id）。 */
        list: <T,>(key: string, fallback: T[]): T[] => {
            const v = has(key) ? overlay![key] : fallback;
            return Array.isArray(v) ? (v as T[]) : fallback;
        },
    };
}

interface SubmitOptions {
    mode: 'create' | 'edit';
    createEndpoint: string;
    mutateEndpoint: string;
    canEdit: boolean;
    canPropose: boolean;
    resubmit?: ResubmitInfo;
    /** 組請求信封（resource／operation／person_id／target／changes）；mode／meta 由這裡補。 */
    buildBody: () => Record<string, unknown>;
    /** 後端錯誤鍵 → 顯示文字；巢狀鍵（addresses.0.addr_id）先經 errorKey 收斂。 */
    mapError: (field: string, codes: unknown[]) => string;
    errorKey?: (field: string) => string;
    /** direct 成功後（通常導向編輯頁）。 */
    onSaved: (json: Record<string, any>) => void;
    fallbackError: string;
}

/**
 * 提交狀態機。isResubmit 時一律打 resubmit 端點、mode=proposal（後端也強制）；
 * 表單原生提交（Enter）：可直接寫者走 direct，否則可提案者走 proposal，修改提案模式一律 proposal。
 */
export function useEntityFormSubmit(opts: SubmitOptions) {
    const isResubmit = !!(opts.resubmit?.resubmit_proposal_id && opts.resubmit?.resubmit_endpoint);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [serverError, setServerError] = useState<string | null>(null);
    const [proposalSubmitted, setProposalSubmitted] = useState(false);
    const [comment, setComment] = useState(opts.resubmit?.initial_comment ?? '');

    const save = async (submitMode: SubmitMode) => {
        setBusy(true);
        setErrors({});
        setServerError(null);

        const body: Record<string, unknown> = { ...opts.buildBody() };
        // /api/v2/create 自己就是 create；但 resubmit 端點是 create／update 共用、缺 operation 時當 update，
        // 新增提案重發時會拿「target.pk 缺識別鍵」的 422。一律標明，對 /api/v2/create 無害（它會忽略）。
        if (opts.mode === 'create') body.operation = 'create';
        if (submitMode === 'proposal') body.mode = 'proposal';
        if (comment.trim() !== '') body.meta = { comment: comment.trim() };
        const endpoint = isResubmit
            ? opts.resubmit!.resubmit_endpoint!
            : opts.mode === 'create' ? opts.createEndpoint : opts.mutateEndpoint;

        try {
            const res = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                // 422 校驗與 409 護欄（如改名被引用擋下）都帶 errors 物件，統一映射成欄位訊息。
                if ((res.status === 422 || res.status === 409) && json?.errors && typeof json.errors === 'object') {
                    const mapped: Record<string, string> = {};
                    for (const [field, codes] of Object.entries(json.errors)) {
                        const key = opts.errorKey ? opts.errorKey(field) : field;
                        mapped[key] = opts.mapError(field, Array.isArray(codes) ? codes : []);
                    }
                    setErrors(mapped);
                } else {
                    setServerError(json?.message ?? opts.fallbackError);
                }
                setBusy(false);
                return;
            }
            if (submitMode === 'proposal') {
                // 提案未落庫——不跳編輯頁，顯示等待審核提示。
                setProposalSubmitted(true);
                setBusy(false);
                return;
            }
            opts.onSaved(json);
        } catch (err) {
            setServerError(String(err));
            setBusy(false);
        }
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (opts.canEdit && !isResubmit) void save('direct');
        else if (opts.canPropose) void save('proposal');
    };

    return {
        isResubmit,
        busy,
        errors,
        serverError,
        proposalSubmitted,
        comment,
        setComment,
        save,
        submit,
    };
}

export type EntityFormSubmit = ReturnType<typeof useEntityFormSubmit>;

/** 表單頂端：伺服器錯誤與「已提交建議」提示。 */
export function EntityFormNotices({ form, indexUrl, t }: { form: EntityFormSubmit; indexUrl: string; t: (key: string) => string }) {
    return (
        <>
            {form.serverError && (
                <div className="rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">{form.serverError}</div>
            )}
            {form.proposalSubmitted && (
                <div className="rounded border border-green-300 bg-green-50 px-4 py-2 text-sm text-green-800">
                    {t('proposal_submitted')}{' '}
                    <a href={indexUrl} className="underline">
                        {t('back_to_list')}
                    </a>
                </div>
            )}
        </>
    );
}

interface FooterProps {
    form: EntityFormSubmit;
    canEdit: boolean;
    canPropose: boolean;
    indexUrl: string;
    idPrefix: string;
    t: (key: string) => string;
}

/**
 * 表單底部：提案說明（可提案者才有）、送出 split button（直接儲存為主按鈕、提交建議收進
 * 箭頭選單；只可提案者為單一提交建議鈕）、取消。修改提案模式下沒有「直接儲存」——
 * resubmit 的語義就是重發提案。
 */
export function EntityFormFooter({ form, canEdit, canPropose, indexUrl, idPrefix, t }: FooterProps) {
    const inputCls =
        'w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

    return (
        <>
            {canPropose && (
                <FormField label={t('modification_note_label')} htmlFor={`${idPrefix}-comment`}>
                    <textarea
                        id={`${idPrefix}-comment`}
                        rows={2}
                        className={inputCls}
                        placeholder={t('modification_note_placeholder')}
                        value={form.comment}
                        onChange={(e) => form.setComment(e.target.value)}
                    />
                </FormField>
            )}

            <div className="flex gap-2 pt-2">
                <SaveSplitButton
                    directAvailable={canEdit}
                    proposalAvailable={canPropose}
                    isResubmit={form.isResubmit}
                    disabled={form.busy}
                    labels={{ saveDirect: t('btn_save'), submitProposal: t('btn_propose'), resubmitProposal: t('btn_resubmit') }}
                    onSaveDirect={() => void form.save('direct')}
                    onSubmitProposal={() => void form.save('proposal')}
                />
                <a href={indexUrl} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                    {t('btn_cancel')}
                </a>
            </div>

        </>
    );
}
