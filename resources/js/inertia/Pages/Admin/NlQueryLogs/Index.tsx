import React, { useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Pagination } from '../../../components/ui/Pagination';
import { Button } from '../../../components/ui/Button';
import { Input } from '../../../components/ui/Input';
import { Select } from '../../../components/ui/Select';
import { FormField } from '../../../components/ui/FormField';
import { useDataTableQuery } from '../../../components/data-table/useDataTableQuery';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';
import { cn } from '../../../lib/utils';
import { LOG_TONE, LOG_CARD_BASE, LOG_HEADER_BASE, LOG_PILL_BASE } from '../logCardStyles';
import { LogCollapsible } from '../LogCollapsible';

interface LlmSummary {
    model: string | null;
    rounds_count: number | null;
    prompt_tokens: number;
    completion_tokens: number;
    total_tokens: number;
}

interface NlLogRow {
    id: number;
    user_name: string | null;
    user_email: string | null;
    created_at: string;
    execution_time_ms: number | null;
    success: boolean;
    question: string;
    generated_sql: string | null;
    explanation: string | null;
    error_message: string | null;
    llm_prompt: string | null;
    llm_response: string | null;
    llm_summary: LlmSummary | null;
}

interface NlQueryLogsPageProps extends SharedProps {
    logs: {
        data: NlLogRow[];
        meta: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
    };
    users: { id: number; name: string }[];
    filters: { search: string | null; success: string | null; user_id: string | null };
    playground_url: string;
}

const nf = new Intl.NumberFormat();

/** 將字串中的實際換行與字面 `<br />`／`<br/>` 都轉成真正的換行顯示，其餘文字原樣輸出（不解讀為 HTML）。 */
function MultilineText({ value }: { value: string }) {
    const lines = value.split(/\r\n|\r|\n|<br\s*\/?>/gi);
    return (
        <>
            {lines.map((line, i) => (
                <React.Fragment key={i}>
                    {i > 0 && <br />}
                    {line}
                </React.Fragment>
            ))}
        </>
    );
}

/**
 * 遞迴渲染深度上限，同時涵蓋兩種情況：物件/陣列本身的巢狀層數，以及字串欄位
 * （如 OpenAI tool_calls[].function.arguments）被再次解析為 JSON 容器的次數。
 * 避免異常巢狀輸入（無論是結構本身或字串套字串）拖垮渲染或造成堆疊溢位。
 */
const MAX_JSON_RENDER_DEPTH = 12;

function tryParseJsonContainer(text: string): unknown {
    const trimmed = text.trim();
    if (trimmed[0] !== '{' && trimmed[0] !== '[') {
        return undefined;
    }
    try {
        const value: unknown = JSON.parse(trimmed);
        return typeof value === 'object' && value !== null ? value : undefined;
    } catch {
        return undefined;
    }
}

function JsonValue({ value, depth = 0 }: { value: unknown; depth?: number }) {
    if (value === null || value === undefined) {
        return <span className="text-muted-foreground">null</span>;
    }
    if (typeof value === 'string') {
        if (value === '') {
            return <span className="text-muted-foreground">""</span>;
        }
        const nested = depth < MAX_JSON_RENDER_DEPTH ? tryParseJsonContainer(value) : undefined;
        return nested !== undefined ? <JsonValue value={nested} depth={depth + 1} /> : <MultilineText value={value} />;
    }
    if (typeof value !== 'object') {
        return <>{String(value)}</>;
    }
    if (depth >= MAX_JSON_RENDER_DEPTH) {
        return <MultilineText value={JSON.stringify(value)} />;
    }
    if (Array.isArray(value)) {
        return value.length === 0 ? (
            <span className="text-muted-foreground">[]</span>
        ) : (
            <JsonTable rows={value.map((item, i) => [`#${i}`, item] as [string, unknown])} depth={depth + 1} />
        );
    }
    const entries = Object.entries(value as Record<string, unknown>);
    return entries.length === 0 ? <span className="text-muted-foreground">{'{}'}</span> : <JsonTable rows={entries} depth={depth + 1} />;
}

function JsonTable({ rows, depth }: { rows: [string, unknown][]; depth: number }) {
    return (
        <table className="w-full table-fixed rounded border border-border/60 text-xs">
            <tbody>
                {rows.map(([key, value]) => (
                    <tr key={key} className="border-b border-border/60 align-top last:border-b-0">
                        <th className="w-28 whitespace-nowrap break-words px-2 py-1 text-left font-medium text-muted-foreground">{key}</th>
                        <td className="break-words px-2 py-1">
                            <JsonValue value={value} depth={depth} />
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/**
 * `llm_response` 依來源可能是：{rounds:[...], total_rounds}（一般查詢，含每輪工具呼叫過程）、
 * 單一 OpenAI 回應物件（QA 模式，無 rounds 包裝）、或 {fallback_non_tool_mode, fallback_response}
 * （工具模式失敗降級）。三者皆只需取「最後一輪」的 choices[0].message.content 作為最終生成結果，
 * 其餘輪次紀錄／tool_calls_requested／tool_results／usage 等屬過程資訊，表格化時略過。
 */
function unwrapToResponseObject(data: unknown): unknown {
    if (typeof data !== 'object' || data === null || Array.isArray(data)) {
        return data;
    }
    let current: Record<string, unknown> = data as Record<string, unknown>;
    if (typeof current.fallback_response === 'object' && current.fallback_response !== null) {
        current = current.fallback_response as Record<string, unknown>;
    }
    if (Array.isArray(current.rounds) && current.rounds.length > 0) {
        const lastRound = current.rounds[current.rounds.length - 1];
        if (typeof lastRound === 'object' && lastRound !== null) {
            const roundResponse = (lastRound as Record<string, unknown>).llm_response;
            current = (typeof roundResponse === 'object' && roundResponse !== null ? roundResponse : lastRound) as Record<string, unknown>;
        }
    }
    return current;
}

/** 取出第一個以 `{` 開頭、括號配對完整的 JSON 物件子字串（忽略字串內的括號）。 */
function extractFirstJsonObjectSubstring(text: string): string | undefined {
    const start = text.indexOf('{');
    if (start === -1) {
        return undefined;
    }
    let depth = 0;
    let inString = false;
    let escaped = false;
    for (let i = start; i < text.length; i++) {
        const ch = text[i];
        if (escaped) {
            escaped = false;
            continue;
        }
        if (ch === '\\') {
            escaped = true;
            continue;
        }
        if (ch === '"') {
            inString = !inString;
            continue;
        }
        if (inString) {
            continue;
        }
        if (ch === '{') {
            depth++;
        } else if (ch === '}') {
            depth--;
            if (depth === 0) {
                return text.slice(start, i + 1);
            }
        }
    }
    return undefined;
}

/**
 * 只在整段內容「頭尾都是 fence」時才剝除（例如 QA 的 answer_markdown 內容本身可能含
 * ```sql fence，若改用中段搜尋最近的 fence pair 會被這種內嵌 fence 誤截斷）。
 */
function stripOuterCodeFence(content: string): string {
    const trimmed = content.trim();
    const openMatch = trimmed.match(/^```(?:json)?\s*/i);
    if (!openMatch || !trimmed.endsWith('```')) {
        return trimmed;
    }
    return trimmed.slice(openMatch[0].length, -3).trim();
}

/** LLM 有時把 JSON 包在 ```json fence 或前後夾雜說明文字，盡量還原出可解析的 JSON 物件。 */
function parseMessageContent(content: string): unknown {
    const candidate = stripOuterCodeFence(content);
    try {
        return JSON.parse(candidate);
    } catch {
        // fall through
    }
    const objectSubstring = extractFirstJsonObjectSubstring(candidate);
    if (objectSubstring) {
        try {
            return JSON.parse(objectSubstring);
        } catch {
            // fall through
        }
    }
    return undefined;
}

function extractFinalResult(fullParsed: unknown): unknown {
    const responseObj = unwrapToResponseObject(fullParsed);
    if (typeof responseObj !== 'object' || responseObj === null) {
        return responseObj;
    }
    const choices = (responseObj as Record<string, unknown>).choices;
    const firstChoice = Array.isArray(choices) ? (choices[0] as Record<string, unknown> | undefined) : undefined;
    const message = firstChoice?.message as Record<string, unknown> | undefined;
    const content = message?.content;
    if (typeof content !== 'string') {
        return responseObj;
    }
    if (content.trim() === '') {
        return content;
    }
    const parsedContent = parseMessageContent(content);
    return parsedContent !== undefined ? parsedContent : content;
}

function LlmResponseBlock({ raw, tableLabel, rawLabel }: { raw: string; tableLabel: string; rawLabel: string }) {
    const [showRaw, setShowRaw] = useState(false);

    const fullParsed = useMemo(() => {
        try {
            const value: unknown = JSON.parse(raw);
            return typeof value === 'object' && value !== null ? value : undefined;
        } catch {
            return undefined;
        }
    }, [raw]);

    const finalResult = useMemo(() => (fullParsed !== undefined ? extractFinalResult(fullParsed) : undefined), [fullParsed]);

    if (fullParsed === undefined) {
        return <pre className="max-h-[32rem] overflow-auto bg-muted/40 p-2 text-xs">{raw}</pre>;
    }

    return (
        <div>
            <div className="mb-1 flex justify-end">
                <Button type="button" variant="outline" size="sm" onClick={() => setShowRaw((v) => !v)}>
                    <i className={cn('fas', showRaw ? 'fa-table' : 'fa-code')} aria-hidden />
                    {showRaw ? tableLabel : rawLabel}
                </Button>
            </div>
            <div className="max-h-[32rem] overflow-auto rounded border border-border bg-muted/40 p-2">
                {showRaw ? <pre className="text-xs">{JSON.stringify(fullParsed, null, 2)}</pre> : <JsonValue value={finalResult} />}
            </div>
        </div>
    );
}

export default function NlQueryLogsIndex() {
    const props = usePage<NlQueryLogsPageProps>().props;
    const { logs, users, filters, playground_url } = props;
    const t = useTranslation('query');
    const tc = useTranslation('common');
    const tOps = useTranslation('operations');

    const [form, setForm] = useState({
        search: filters.search ?? '',
        success: filters.success ?? '',
        user_id: filters.user_id ?? '',
    });

    const applied = {
        search: filters.search ?? '',
        success: filters.success ?? '',
        user_id: filters.user_id ?? '',
    };

    const { onPageChange, onFilterChange } = useDataTableQuery({
        params: applied,
        only: ['logs', 'filters'],
    });

    const hasFilters = Boolean(form.search || form.success !== '' || form.user_id);

    return (
        <DashboardLayout
            title={t('log_page_title')}
            breadcrumbs={[{ label: t('log_page_title') }]}
        >
            <div className="mb-3">
                <a
                    href={playground_url}
                    className="inline-flex items-center gap-1 rounded-md border border-input px-3 py-1.5 text-sm hover:bg-muted"
                >
                    <i className="fas fa-arrow-left" aria-hidden /> {t('log_back_link')}
                </a>
            </div>

            <form
                className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-12"
                onSubmit={(e) => {
                    e.preventDefault();
                    onFilterChange({ ...form });
                }}
            >
                <FormField className="md:col-span-5" label={t('log_keyword_search')} htmlFor="search">
                    <Input id="search" value={form.search} placeholder={t('log_search_placeholder')} onChange={(e) => setForm((f) => ({ ...f, search: e.target.value }))} />
                </FormField>
                <FormField className="md:col-span-3" label={tOps('status_label')} htmlFor="success">
                    <Select id="success" value={form.success} onChange={(e) => setForm((f) => ({ ...f, success: e.target.value }))}>
                        <option value="">{t('log_status_all')}</option>
                        <option value="1">{t('log_status_success')}</option>
                        <option value="0">{t('log_status_failure')}</option>
                    </Select>
                </FormField>
                <FormField className="md:col-span-3" label={t('log_user_label')} htmlFor="user_id">
                    <Select id="user_id" value={form.user_id} onChange={(e) => setForm((f) => ({ ...f, user_id: e.target.value }))}>
                        <option value="">{t('log_all_users')}</option>
                        {users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </Select>
                </FormField>
                <div className="flex items-end gap-2 md:col-span-12">
                    <Button type="submit">
                        <i className="fas fa-search" aria-hidden /> {tc('search')}
                    </Button>
                    {hasFilters && (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => {
                                setForm({ search: '', success: '', user_id: '' });
                                onFilterChange({ search: null, success: null, user_id: null });
                            }}
                        >
                            <i className="fas fa-times" aria-hidden /> {tOps('clear_filter')}
                        </Button>
                    )}
                </div>
            </form>

            <div className="mb-3 rounded border border-blue-300 bg-blue-50 px-4 py-2 text-sm text-blue-800 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
                <i className="fas fa-info-circle mr-1" aria-hidden />
                {t('log_record_count', {
                    total: String(logs.meta.total),
                    first: String(logs.meta.from ?? 0),
                    last: String(logs.meta.to ?? 0),
                })}
            </div>

            {logs.data.length === 0 ? (
                <div className="rounded border border-yellow-300 bg-yellow-50 px-4 py-3 text-sm text-yellow-800 dark:border-yellow-900 dark:bg-yellow-950/40 dark:text-yellow-200">
                    <i className="fas fa-exclamation-triangle mr-1" aria-hidden /> {t('log_no_records')}
                </div>
            ) : (
                <div className="space-y-4">
                    {logs.data.map((log) => {
                        const tone = LOG_TONE[log.success ? 'success' : 'danger'];
                        return (
                        <article key={log.id} className={cn(LOG_CARD_BASE, tone.card)}>
                            <header className={cn(LOG_HEADER_BASE, tone.header)}>
                                <span className="font-mono font-semibold text-muted-foreground">#{log.id}</span>
                                <span>
                                    <i className="fas fa-user mr-1 text-muted-foreground" aria-hidden />
                                    <span className="font-medium text-foreground">{log.user_name ?? tc('unknown')}</span>
                                    {log.user_email && <small className="ml-1 text-muted-foreground">({log.user_email})</small>}
                                </span>
                                <span className="text-muted-foreground">
                                    <i className="fas fa-clock mr-1" aria-hidden />
                                    {log.created_at}
                                </span>
                                {log.execution_time_ms != null && (
                                    <span className="text-muted-foreground">
                                        <i className="fas fa-stopwatch mr-1" aria-hidden />
                                        {log.execution_time_ms}ms
                                    </span>
                                )}
                                <span className={cn(LOG_PILL_BASE, tone.pill)}>
                                    <i className={cn('fas', log.success ? 'fa-check-circle' : 'fa-times-circle')} aria-hidden />
                                    {log.success ? t('log_status_success') : t('log_status_failure')}
                                </span>
                            </header>

                            <div className="space-y-3 p-4">
                                <div>
                                    <h6 className="mb-1 font-medium">
                                        <i className="fas fa-question-circle mr-1 text-primary" aria-hidden /> {t('log_user_question')}
                                    </h6>
                                    <div className="rounded bg-muted/40 px-3 py-2 text-sm">{log.question}</div>
                                </div>

                                {log.success ? (
                                    <>
                                        {log.generated_sql && (
                                            <div>
                                                <h6 className="mb-1 font-medium">
                                                    <i className="fas fa-code mr-1 text-emerald-600" aria-hidden /> {t('log_generated_sql')}
                                                </h6>
                                                <pre className="max-h-52 overflow-auto rounded bg-muted/40 p-2 text-xs">{log.generated_sql}</pre>
                                            </div>
                                        )}
                                        {log.explanation && (
                                            <div>
                                                <h6 className="mb-1 font-medium">
                                                    <i className="fas fa-info-circle mr-1 text-blue-600" aria-hidden /> {t('log_explanation')}
                                                </h6>
                                                <p className="text-sm text-muted-foreground">{log.explanation}</p>
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    log.error_message && (
                                        <div>
                                            <h6 className="mb-1 font-medium">
                                                <i className="fas fa-exclamation-triangle mr-1 text-destructive" aria-hidden /> {t('log_error_message')}
                                            </h6>
                                            <div className="rounded border border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive">{log.error_message}</div>
                                        </div>
                                    )
                                )}

                                {log.llm_summary && (
                                    <div className="flex flex-wrap gap-1 text-xs">
                                        {log.llm_summary.model && (
                                            <span className="rounded bg-muted px-2 py-0.5">{t('log_model')}: {log.llm_summary.model}</span>
                                        )}
                                        {log.llm_summary.rounds_count != null && (
                                            <span className="rounded bg-muted px-2 py-0.5">{t('log_total_rounds')}: {log.llm_summary.rounds_count}</span>
                                        )}
                                        {log.llm_summary.total_tokens > 0 && (
                                            <span className="rounded bg-muted px-2 py-0.5">{t('log_token_total')}: {nf.format(log.llm_summary.total_tokens)}</span>
                                        )}
                                    </div>
                                )}

                                {log.llm_prompt && (
                                    <LogCollapsible label={t('log_llm_prompt')}>
                                        <pre className="max-h-96 overflow-auto bg-muted/40 p-2 text-xs">{log.llm_prompt}</pre>
                                    </LogCollapsible>
                                )}
                                {log.llm_response && (
                                    <LogCollapsible label={t('log_llm_response')}>
                                        <LlmResponseBlock
                                            raw={log.llm_response}
                                            tableLabel={t('log_llm_response_table')}
                                            rawLabel={t('log_llm_response_raw')}
                                        />
                                    </LogCollapsible>
                                )}
                            </div>
                        </article>
                        );
                    })}

                    <Pagination
                        meta={logs.meta}
                        onPageChange={onPageChange}
                        summaryTemplate="{from}–{to} / {total}"
                        labels={{ previous: tc('previous'), next: tc('next') }}
                    />
                </div>
            )}
        </DashboardLayout>
    );
}
