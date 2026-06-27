import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { Input } from '../../../components/ui/Input';
import { Modal } from '../../../components/ui/Modal';
import { Pagination } from '../../../components/ui/Pagination';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

interface DiffRow {
    field: string;
    before?: string | number | null;
    after?: string | number | null;
    current?: string | number | null;
    matches_current?: boolean;
    matches_before?: boolean;
}
interface DiffPayload {
    type?: string;
    rows?: DiffRow[];
    addresses?: unknown[];
    note?: string | null;
}
interface AuditLog {
    table_name: string | null;
    operation: string | null;
    row_pk_text: string | null;
    diff: DiffPayload | null;
}
interface Person {
    id: number | null;
    display_name: string;
    is_primary: boolean;
    person_edit_url: string | null;
    resource_id: string | null;
    resource_link: string | null;
    resource_description: string;
}
interface OperationNote {
    label: string;
    content: string;
}
interface OpRow {
    id: number;
    people: Person[];
    resource: string;
    resource_display: string;
    resource_link: string | null;
    resource_description: string;
    show_per_person_resource_buttons: boolean;
    op_type: number;
    op_type_label: string;
    user_name: string;
    updated_display: string;
    updated_utc: string;
    is_proposal: boolean;
    review_status: string | null;
    review_comment: string | null;
    reviewed_by: string | null;
    reviewed_display: string;
    reviewed_utc: string;
    proposal_comment: string | null;
    submitted_by: string | null;
    submitted_display: string;
    submitted_utc: string;
    cancelled_by: string | null;
    cancelled_display: string;
    cancelled_utc: string;
    cancel_reason: string | null;
    primary_note_label: string;
    operation_notes: OperationNote[];
    has_operation_notes: boolean;
    note_tooltip_short: string;
    resource_data_display: Record<string, unknown> | string;
    audit_logs: AuditLog[];
    has_audit_logs: boolean;
    diff_source: DiffPayload | string | null;
    has_diff_content: boolean;
    can_compare: boolean;
    can_restore: boolean;
    can_edit_proposal: boolean;
    can_review_proposal: boolean;
    urls: {
        restore: string;
        approve: string;
        reject: string;
        edit_proposal: string;
        cancel_proposal: string;
    };
}
interface OperationsPageProps extends SharedProps {
    lists: OpRow[];
    pagination: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
    proposals_only: boolean;
    status_filters: string[];
    history_context: { person_id: number; page: string; label: string } | null;
    filters: { editor: string; op_type: number[] };
    urls: { index: string };
}

function formatLocal(iso: string, fallback: string): string {
    if (!iso) return fallback;
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? fallback : d.toLocaleString();
}

export default function OperationsIndex() {
    const props = usePage<OperationsPageProps>().props;
    const { lists, pagination, proposals_only, status_filters, history_context, filters, urls } = props;
    const t = useTranslation('operations');
    const tc = useTranslation('common');
    const tnav = useTranslation('nav');

    const [editor, setEditor] = useState(filters.editor ?? '');
    const [opTypes, setOpTypes] = useState<number[]>(filters.op_type ?? []);
    const [statuses, setStatuses] = useState<string[]>(status_filters ?? []);

    const [modal, setModal] = useState<{ row: OpRow; view: 'snapshot' | 'compare' | 'notes' } | null>(null);
    const [rejectFor, setRejectFor] = useState<OpRow | null>(null);
    const [rejectComment, setRejectComment] = useState('');

    const historyParams = history_context
        ? { c_personid: history_context.person_id, history_page: history_context.page }
        : {};

    const submitFilters = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(urls.index, {
            ...(proposals_only ? { proposals_only: 1 } : {}),
            ...historyParams,
            editor,
            ...(proposals_only ? { status: statuses } : { op_type: opTypes }),
        }, { preserveScroll: true });
    };
    const clearFilters = () => {
        router.get(urls.index, {
            ...(proposals_only ? { proposals_only: 1 } : {}),
            ...historyParams,
        }, { preserveScroll: true });
    };
    const onPageChange = (page: number) => {
        router.get(urls.index, {
            ...(proposals_only ? { proposals_only: 1 } : {}),
            ...historyParams,
            editor: filters.editor,
            ...(proposals_only ? { status: status_filters } : { op_type: filters.op_type }),
            page,
        }, { preserveScroll: true, preserveState: true });
    };

    const post = (url: string, confirmKey: string, data: Record<string, string> = {}) => {
        if (!window.confirm(t(confirmKey))) return;
        router.post(url, data, { preserveScroll: true });
    };
    const del = (url: string, confirmKey: string) => {
        if (!window.confirm(t(confirmKey))) return;
        router.delete(url, { preserveScroll: true });
    };

    const opTypeOptions: [number, string][] = [
        [1, t('op_create')], [2, t('op_overwrite')], [3, t('op_update')], [4, t('op_delete')],
    ];
    const statusOptions: [string, string][] = [
        ['pending', t('status_pending')], ['approved', t('status_approved')],
        ['rejected', t('status_rejected')], ['cancelled', t('status_withdrawn')],
    ];

    const toggle = <T,>(arr: T[], v: T): T[] => (arr.includes(v) ? arr.filter((x) => x !== v) : [...arr, v]);

    return (
        <DashboardLayout
            title={proposals_only ? tnav('recent_proposals') : tnav('recent_operations')}
            breadcrumbs={[{ label: proposals_only ? tnav('recent_proposals') : tnav('recent_operations') }]}
        >
            <div className="rounded-lg border border-border bg-card p-4">
                {history_context && (
                    <div className="mb-3 rounded border border-info-border bg-info-subtle px-4 py-2 text-sm text-info-subtle-foreground">
                        {t('history_label')} {history_context.person_id}「{history_context.label}」
                    </div>
                )}

                <form onSubmit={submitFilters} className="mb-4 flex flex-wrap items-end gap-3">
                    <div>
                        <label className="mb-1 block text-sm font-medium">{t('modified_by')}：</label>
                        <Input value={editor} placeholder={t('editor_placeholder')} onChange={(e) => setEditor(e.target.value)} className="w-44" />
                    </div>
                    {proposals_only ? (
                        <div>
                            <span className="mb-1 block text-sm font-medium">{t('status_label')}：</span>
                            <div className="flex flex-wrap gap-3">
                                {statusOptions.map(([val, label]) => (
                                    <label key={val} className="flex items-center gap-1 text-sm">
                                        <input type="checkbox" checked={statuses.includes(val)} onChange={() => setStatuses((s) => toggle(s, val))} />
                                        {label}
                                    </label>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <div>
                            <span className="mb-1 block text-sm font-medium">{t('operation_type')}：</span>
                            <div className="flex flex-wrap gap-3">
                                {opTypeOptions.map(([val, label]) => (
                                    <label key={val} className="flex items-center gap-1 text-sm">
                                        <input type="checkbox" checked={opTypes.includes(val)} onChange={() => setOpTypes((s) => toggle(s, val))} />
                                        {label}
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}
                    <Button type="submit">{t('filter')}</Button>
                    <Button type="button" variant="secondary" onClick={clearFilters}>{t('clear_filter')}</Button>
                </form>

                <div className="overflow-x-auto rounded-md border border-border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                <th className="px-3 py-2 text-left font-medium">{t('person_label')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('modified_resource')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('resource_location')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('modified_value')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('operation_type')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('modified_by')}</th>
                                <th className="px-3 py-2 text-left font-medium">{t('modified_time')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {lists.length === 0 && (
                                <tr><td colSpan={7} className="px-3 py-6 text-center text-muted-foreground">—</td></tr>
                            )}
                            {lists.map((row) => {
                                const span = row.people.length;
                                const first = row.people[0];
                                const perPerson = row.show_per_person_resource_buttons;
                                return (
                                    <React.Fragment key={row.id}>
                                        <tr className="border-t border-border align-top">
                                            <PersonCell person={first} span={span} t={t} />
                                            <td className="px-3 py-1.5" rowSpan={span}>{row.resource_display}</td>
                                            {perPerson
                                                ? <LocationCell person={first} t={t} />
                                                : <td className="px-3 py-1.5" rowSpan={span}><ResourceLink link={row.resource_link} desc={row.resource_description} t={t} /></td>}
                                            <td className="px-3 py-1.5" rowSpan={span} style={{ maxWidth: '28rem' }}>
                                                <ValueCell row={row} t={t} setModal={setModal} post={post} del={del} openReject={(r) => { setRejectFor(r); setRejectComment(''); }} />
                                            </td>
                                            <td className="px-3 py-1.5" rowSpan={span}>{row.op_type_label}</td>
                                            <td className="px-3 py-1.5" rowSpan={span}>{row.user_name}</td>
                                            <td className="px-3 py-1.5" rowSpan={span} title={row.updated_utc}>{formatLocal(row.updated_utc, row.updated_display)}</td>
                                        </tr>
                                        {span > 1 && row.people.slice(1).map((p, i) => (
                                            <tr key={`${row.id}-p${i}`} className="border-t border-border align-top">
                                                <PersonCell person={p} span={1} t={t} />
                                                {perPerson && <LocationCell person={p} t={t} />}
                                            </tr>
                                        ))}
                                    </React.Fragment>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <Pagination className="mt-4" meta={pagination} onPageChange={onPageChange} labels={{ previous: tc('previous'), next: tc('next') }} />
            </div>

            {/* 內容快照 / 比對 / 備註 Modal */}
            <Modal
                open={modal !== null}
                onOpenChange={(o) => !o && setModal(null)}
                className="max-w-4xl"
                title={modal?.view === 'snapshot' ? t('content_snapshot') : modal?.view === 'compare' ? t('compare') : modal?.row.primary_note_label}
                footer={<Button variant="outline" onClick={() => setModal(null)}>{tc('close')}</Button>}
            >
                {modal?.view === 'snapshot' && <KeyValueTable data={modal.row.resource_data_display} />}
                {modal?.view === 'compare' && <CompareBody row={modal.row} t={t} />}
                {modal?.view === 'notes' && (
                    <div className="max-h-[60vh] space-y-3 overflow-auto">
                        {modal.row.operation_notes.map((n, i) => (
                            <div key={i}>
                                {n.label !== modal.row.primary_note_label && <strong>{n.label}</strong>}
                                <div className="whitespace-pre-line">{n.content}</div>
                            </div>
                        ))}
                    </div>
                )}
            </Modal>

            {/* 退回提案 Modal（含原因） */}
            <Modal
                open={rejectFor !== null}
                onOpenChange={(o) => !o && setRejectFor(null)}
                title={t('reject_modal_title')}
                footer={
                    <>
                        <Button variant="outline" onClick={() => setRejectFor(null)}>{tc('cancel')}</Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (!rejectFor) return;
                                router.post(rejectFor.urls.reject, { review_comment: rejectComment }, { preserveScroll: true });
                                setRejectFor(null);
                            }}
                        >
                            {t('confirm_reject')}
                        </Button>
                    </>
                }
            >
                <label className="mb-1 block text-sm">{t('reject_reason_opt')}</label>
                <textarea
                    rows={3}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                    value={rejectComment}
                    onChange={(e) => setRejectComment(e.target.value)}
                />
            </Modal>
        </DashboardLayout>
    );
}

function PersonCell({ person, span, t }: { person: Person; span: number; t: (k: string) => string }) {
    return (
        <td className="px-3 py-1.5">
            {!person.id ? (
                <span className="text-muted-foreground">{t('no_person_involved')}</span>
            ) : (
                <>
                    <a className="text-primary hover:underline" href={person.person_edit_url ?? '#'}>{person.display_name}</a>
                    {span > 1 && (
                        <span className="ml-1 rounded bg-muted px-1.5 py-0.5 text-xs">
                            {person.is_primary ? t('main_op') : t('linked_op')}
                        </span>
                    )}
                </>
            )}
        </td>
    );
}

function ResourceLink({ link, desc, t }: { link: string | null; desc: string; t: (k: string) => string }) {
    return (
        <>
            {link ? (
                <a href={link} className="inline-flex items-center rounded-md border border-primary px-3 py-1 text-xs text-primary hover:bg-primary/10">{t('view_page')}</a>
            ) : (
                <button type="button" disabled className="inline-flex items-center rounded-md border border-border px-3 py-1 text-xs text-muted-foreground">{t('no_resource_page')}</button>
            )}
            {desc !== '' && <div className="mt-1 whitespace-pre-line break-all text-xs text-muted-foreground">{desc}</div>}
        </>
    );
}

function LocationCell({ person, t }: { person: Person; t: (k: string) => string }) {
    return (
        <td className="px-3 py-1.5">
            <ResourceLink link={person.resource_link} desc={person.resource_description} t={t} />
        </td>
    );
}

function ValueCell({ row, t, setModal, post, del, openReject }: {
    row: OpRow;
    t: (k: string) => string;
    setModal: (m: { row: OpRow; view: 'snapshot' | 'compare' | 'notes' }) => void;
    post: (url: string, confirmKey: string, data?: Record<string, string>) => void;
    del: (url: string, confirmKey: string) => void;
    openReject: (r: OpRow) => void;
}) {
    const badge = (() => {
        if (!row.is_proposal) return null;
        const map: Record<string, [string, string]> = {
            approved: ['bg-success-subtle text-success-subtle-foreground', t('status_approved')],
            rejected: ['bg-danger-subtle text-danger-subtle-foreground', t('status_rejected')],
            cancelled: ['bg-muted text-foreground', t('status_withdrawn')],
        };
        const [cls, label] = map[row.review_status ?? ''] ?? ['bg-warning-subtle text-warning-subtle-foreground', t('status_pending')];
        return <span className={`rounded px-2 py-0.5 text-xs font-semibold ${cls}`}>{label}</span>;
    })();

    return (
        <div className="space-y-2">
            {row.is_proposal && (
                <div className="space-y-0.5">
                    {badge}
                    {row.proposal_comment && <div className="text-xs text-muted-foreground">{t('proposal_desc')}：{row.proposal_comment}</div>}
                    {row.submitted_by && <div className="text-xs text-muted-foreground">{t('proposer')}：{row.submitted_by}{row.submitted_display && `（${row.submitted_display}）`}</div>}
                    {row.review_status === 'cancelled' && <div className="text-xs text-muted-foreground">{t('cancelled_by')}：{row.cancelled_by ?? '—'}{row.cancelled_display && `（${row.cancelled_display}）`}</div>}
                    {row.review_status === 'cancelled' && row.cancel_reason && <div className="text-xs text-muted-foreground">{t('withdrawal_reason')}：{row.cancel_reason}</div>}
                    {row.review_comment && <div className="text-xs text-muted-foreground">{t('review_notes')}：{row.review_comment}</div>}
                    {row.reviewed_by && <div className="text-xs text-muted-foreground">{t('reviewer')}：{row.reviewed_by}{row.reviewed_display && `（${row.reviewed_display}）`}</div>}
                </div>
            )}

            <div className="flex flex-wrap gap-1.5">
                <Button size="sm" variant="outline" onClick={() => setModal({ row, view: 'snapshot' })}>{t('content_snapshot')}</Button>
                <Button size="sm" variant="outline" disabled={!row.can_compare} onClick={() => setModal({ row, view: 'compare' })}>{t('compare')}</Button>
                {row.has_operation_notes && (
                    <Button size="sm" variant="ghost" title={row.note_tooltip_short ?? ''} onClick={() => setModal({ row, view: 'notes' })}>
                        <i className="far fa-edit" aria-hidden />
                    </Button>
                )}
            </div>

            {row.can_restore && (
                <Button size="sm" variant="outline" onClick={() => post(row.urls.restore, 'revert_confirm')}>
                    <i className="fas fa-history mr-1" aria-hidden />{t('revert')}
                </Button>
            )}

            {row.can_edit_proposal && (
                <div className="flex flex-wrap gap-1.5">
                    <a href={row.urls.edit_proposal} className="inline-flex items-center rounded-md border border-border px-3 py-1 text-xs hover:bg-muted">
                        <i className="far fa-pen-to-square mr-1" aria-hidden />{t('edit_proposal')}
                    </a>
                    <Button size="sm" variant="outline" onClick={() => del(row.urls.cancel_proposal, 'withdraw_confirm')}>
                        <i className="fas fa-ban mr-1" aria-hidden />{t('revoke')}
                    </Button>
                </div>
            )}

            {row.can_review_proposal && (
                <div className="flex flex-wrap gap-1.5">
                    <Button size="sm" variant="outline" onClick={() => post(row.urls.approve, 'approve_confirm', { review_comment: '' })}>
                        <i className="fas fa-check mr-1" aria-hidden />{t('approve')}
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => openReject(row)}>
                        <i className="fas fa-reply mr-1" aria-hidden />{t('reject_proposal')}
                    </Button>
                </div>
            )}
        </div>
    );
}

function CompareBody({ row, t }: { row: OpRow; t: (k: string) => string }) {
    if (row.has_audit_logs) {
        return (
            <div className="max-h-[60vh] space-y-3 overflow-auto">
                <div className="text-xs text-muted-foreground">{t('audit_log_count_item').replace(':count', String(row.audit_logs.length))}</div>
                {row.audit_logs.map((a, i) => (
                    <div key={i} className="rounded border border-border p-2">
                        <div className="mb-1.5 break-all text-xs">
                            <strong>{a.table_name ?? t('unknown_table')}</strong> · {(a.operation ?? 'UNKNOWN').toUpperCase()} · <span className="font-mono">{a.row_pk_text}</span>
                        </div>
                        <DiffTable diff={a.diff} t={t} />
                    </div>
                ))}
            </div>
        );
    }
    return <div className="max-h-[60vh] overflow-auto"><DiffTable diff={row.diff_source} t={t} /></div>;
}

function DiffTable({ diff, t }: { diff: DiffPayload | string | null; t: (k: string) => string }) {
    if (!diff) return <p className="text-sm text-muted-foreground">—</p>;
    if (typeof diff === 'string') {
        return <pre className="overflow-auto whitespace-pre-wrap break-all text-xs">{diff}</pre>;
    }
    if (diff.type === 'POSTED_TO_ADDR_DATA') {
        return <pre className="overflow-auto whitespace-pre-wrap break-all text-xs">{JSON.stringify(diff.addresses ?? [], null, 2)}</pre>;
    }
    const rows = diff.rows ?? [];
    return (
        <table className="w-full text-sm">
            <thead className="bg-muted/50">
                <tr>
                    <th className="px-2 py-1 text-left">{t('diff_field')}</th>
                    <th className="px-2 py-1 text-left">{t('diff_before')}</th>
                    <th className="px-2 py-1 text-left">{t('diff_after')}</th>
                    <th className="px-2 py-1 text-left">{t('diff_current')}</th>
                </tr>
            </thead>
            <tbody>
                {rows.map((d, i) => (
                    <tr key={`${d.field}-${i}`} className="border-t border-border align-top">
                        <td className="px-2 py-1 font-mono text-xs">{d.field}</td>
                        <td className={`px-2 py-1 break-all ${d.matches_before ? 'bg-info-subtle' : ''}`}>{d.before}</td>
                        <td className={`px-2 py-1 break-all ${d.matches_current ? 'bg-success-subtle' : ''}`}>{d.after}</td>
                        <td className="px-2 py-1 break-all">{d.current ?? t('not_retrieved')}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function KeyValueTable({ data }: { data: Record<string, unknown> | string }) {
    if (typeof data === 'string') {
        return <pre className="max-h-[60vh] overflow-auto whitespace-pre-wrap break-all text-sm">{data}</pre>;
    }
    const entries = Object.entries(data);
    return (
        <div className="max-h-[60vh] overflow-auto">
            <table className="w-full text-sm">
                <tbody>
                    {entries.map(([k, v]) => (
                        <tr key={k} className="border-t border-border align-top">
                            <td className="px-2 py-1 font-mono text-xs">{k}</td>
                            <td className="px-2 py-1 break-all">{v === null ? '(null)' : typeof v === 'object' ? JSON.stringify(v) : String(v)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
