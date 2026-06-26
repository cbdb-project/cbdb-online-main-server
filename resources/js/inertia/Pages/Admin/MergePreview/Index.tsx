import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { Input } from '../../../components/ui/Input';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

type Attrs = Record<string, unknown>;
interface PersonSummary {
    exists: boolean;
    id: number | string | null;
    name_chn: string | null;
    name: string | null;
    dynasty_code: number | string | null;
    dynasty_name: string | null;
    gender_code: number | null;
    gender_label: string | null;
    attributes?: Attrs;
}
interface OtherRow { summary?: string; raw?: Attrs; [k: string]: unknown }
interface Preview {
    primary_id: string | number;
    secondary_id: string | number;
    auto_arrange: boolean;
    primary_person: PersonSummary;
    secondary_person: PersonSummary;
    merged_person: Attrs;
    merged_updates: Attrs;
    name_match: string;
    dynasty_match: string;
    gender_match: string;
    altname_details_primary: Attrs[];
    altname_details_secondary: Attrs[];
    kin_details_primary: Attrs[];
    kin_details_secondary: Attrs[];
    assoc_details_primary: Record<string, Attrs[]>;
    assoc_details_secondary: Record<string, Attrs[]>;
    other_details_primary: Record<string, OtherRow[]>;
    other_details_secondary: Record<string, OtherRow[]>;
    table_counts_primary: Record<string, number | Record<string, number>>;
    table_counts_secondary: Record<string, number | Record<string, number>>;
    sql_preview: string[];
    merge_reason: string;
    biog_columns: string[];
    merge_blocked: boolean;
    notes: string;
    auto_min_target: number | null;
}
interface MergePreviewProps extends SharedProps {
    preview: Preview | null;
    auto_arrange: boolean;
    merge_reason: string;
    form_primary: string;
    form_secondary: string;
    merge_blocked: boolean;
    urls: { index: string };
}

const STATUS_CLASS: Record<string, string> = { same: 'text-green-600', different: 'text-amber-600', unknown: 'text-muted-foreground' };

function val(v: unknown): string {
    return v === null || v === undefined ? 'NULL' : String(v);
}

const TABLE_MAP: [string, string][] = [
    ['biog_addr', 'BIOG_ADDR_DATA'], ['biog_inst', 'BIOG_INST_DATA'], ['biog_source', 'BIOG_SOURCE_DATA'],
    ['biog_text', 'BIOG_TEXT_DATA'], ['entry', 'ENTRY_DATA'], ['events', 'EVENTS_DATA'],
    ['possession', 'POSSESSION_DATA'], ['status', 'STATUS_DATA'], ['posted_to_addr', 'POSTED_TO_ADDR_DATA'],
    ['posting', 'POSTING_DATA'], ['posted_to_office', 'POSTED_TO_OFFICE_DATA'], ['merged_person', 'MERGED_PERSON_DATA'],
];

export default function MergePreviewIndex() {
    const props = usePage<MergePreviewProps>().props;
    const { preview, urls } = props;
    const t = useTranslation('admin');
    const tc = useTranslation('common');

    const [primary, setPrimary] = useState(props.form_primary ?? '');
    const [secondary, setSecondary] = useState(props.form_secondary ?? '');
    const [reason, setReason] = useState(props.merge_reason ?? '');
    const [autoArrange, setAutoArrange] = useState(props.auto_arrange ?? true);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(urls.index, {
            primary_id: primary,
            secondary_id: secondary,
            reason,
            merge_to_min: autoArrange ? 'true' : 'false',
        }, { preserveScroll: true });
    };

    const copyLink = () => {
        const params = new URLSearchParams();
        if (primary.trim()) params.set('primary_id', primary.trim());
        if (secondary.trim()) params.set('secondary_id', secondary.trim());
        params.set('merge_to_min', autoArrange ? 'true' : 'false');
        if (reason.trim()) params.set('reason', reason.trim());
        const base = `${window.location.origin}${urls.index}`;
        const link = `${base}?${params.toString()}`;
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(link).then(() => window.alert(t('manage_merge_link_copied'))).catch(() => window.prompt(t('manage_merge_copy_prompt'), link));
        } else {
            window.prompt(t('manage_merge_copy_prompt'), link);
        }
    };

    return (
        <DashboardLayout title={t('manage_merge_title')} breadcrumbs={[{ label: t('manage_merge_title') }]}>
            <div className="rounded-lg border border-border bg-card p-4">
                <form onSubmit={submit} className="space-y-3">
                    <Field label={t('manage_merge_primary_id')}>
                        <Input value={primary} placeholder={t('manage_merge_primary_placeholder')} onChange={(e) => setPrimary(e.target.value)} />
                    </Field>
                    <Field label={t('manage_merge_secondary_id')}>
                        <Input value={secondary} placeholder={t('manage_merge_secondary_placeholder')} onChange={(e) => setSecondary(e.target.value)} />
                    </Field>
                    <Field label={t('manage_merge_reason')}>
                        <textarea className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm" rows={3} value={reason}
                            placeholder={t('manage_merge_reason_placeholder')} onChange={(e) => setReason(e.target.value)} />
                    </Field>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={autoArrange} onChange={(e) => setAutoArrange(e.target.checked)} />
                        {t('manage_merge_auto_arrange')}
                    </label>
                    <div className="flex gap-2">
                        <Button type="submit">{t('manage_merge_preview_btn')}</Button>
                        <Button type="button" variant="secondary" onClick={copyLink}>{t('manage_merge_copy_link')}</Button>
                    </div>
                </form>

                {!preview ? (
                    <p className="mt-4 text-sm text-muted-foreground">{t('manage_merge_enter_hint')}</p>
                ) : (
                    <PreviewBody preview={preview} t={t} tc={tc} />
                )}
            </div>
        </DashboardLayout>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-12 items-start gap-2">
            <label className="col-span-12 pt-1.5 text-sm font-medium sm:col-span-2">{label}</label>
            <div className="col-span-12 sm:col-span-10">{children}</div>
        </div>
    );
}

function PreviewBody({ preview, t, tc }: { preview: Preview; t: (k: string, r?: Record<string, string>) => string; tc: (k: string) => string }) {
    const p = preview;
    const primaryAttrs = p.primary_person.attributes ?? {};
    const secondaryAttrs = p.secondary_person.attributes ?? {};

    const comparisons = [
        { label: t('manage_merge_name_col'), status: p.name_match || 'unknown', diffMsg: t('manage_merge_name_diff'), unknownMsg: t('manage_merge_name_unknown') },
        { label: t('manage_merge_gender_col'), status: p.gender_match || 'unknown', diffMsg: t('manage_merge_gender_diff'), unknownMsg: t('manage_merge_gender_unknown') },
        { label: t('manage_merge_dynasty_col'), status: p.dynasty_match || 'unknown', diffMsg: t('manage_merge_dynasty_diff'), unknownMsg: t('manage_merge_dynasty_unknown') },
    ];
    const warnings = comparisons.map((c) => (c.status === 'different' ? c.diffMsg : c.status === 'unknown' ? c.unknownMsg : '')).filter(Boolean);
    const statusLabel = (s: string) => t(s === 'same' ? 'manage_merge_status_same' : s === 'different' ? 'manage_merge_status_different' : 'manage_merge_status_unknown');

    const assocCounts = (counts: Record<string, number | Record<string, number>>) => (counts.assoc as Record<string, number>) ?? {};
    const assocCols: [string, string][] = [
        ['c_personid', t('manage_merge_assoc_personid')], ['c_kin_id', t('manage_merge_assoc_kin_id')],
        ['c_assoc_id', t('manage_merge_assoc_assoc_id')], ['c_assoc_kin_id', t('manage_merge_assoc_kin_assoc_id')],
    ];

    return (
        <div className="mt-4 space-y-4">
            <hr className="border-border" />
            <h4 className="text-lg font-semibold">{t('manage_merge_result_title')}</h4>
            {p.merge_blocked && (
                <div className="rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">
                    <strong>{t('manage_merge_blocked')}</strong>
                </div>
            )}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <PersonTable title={t('manage_merge_primary_person')} person={p.primary_person} id={p.primary_id} genderMatch={p.gender_match} t={t} />
                <PersonTable title={t('manage_merge_secondary_person')} person={p.secondary_person} id={p.secondary_id} genderMatch={p.gender_match} t={t} />
            </div>

            <div className="rounded border border-blue-300 bg-blue-50 px-4 py-2 text-sm text-blue-800">
                <strong>{t('manage_merge_comparison')}</strong>{' '}
                {comparisons.map((c, i) => (
                    <span key={c.label}>
                        {i > 0 && '，'}{c.label}：<span className={STATUS_CLASS[c.status]}>{statusLabel(c.status)}</span>
                    </span>
                ))}
                {warnings.length > 0 && (
                    <div className="mt-2 space-y-0.5 text-xs">
                        {warnings.map((w, i) => <div key={i} className="text-amber-600">{w}</div>)}
                    </div>
                )}
            </div>

            {p.merge_reason && (
                <div className="rounded border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-800">
                    <strong>{t('manage_merge_reason_label')}</strong> {p.merge_reason}
                </div>
            )}

            <div>
                <h5 className="font-semibold">{t('manage_merge_strategy')}</h5>
                <p className="text-sm">{p.auto_arrange ? t('manage_merge_auto_strategy') : t('manage_merge_manual_strategy')}</p>
            </div>

            <div>
                <h5 className="font-semibold">{t('manage_merge_field_comparison')}</h5>
                {p.biog_columns.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('manage_merge_no_biog_cols')}</p>
                ) : (
                    <div className="overflow-x-auto rounded-md border border-border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-2 py-1 text-left">{t('manage_merge_field_col')}</th>
                                    <th className="px-2 py-1 text-left">{t('manage_merge_primary_val')}</th>
                                    <th className="px-2 py-1 text-left">{t('manage_merge_secondary_val')}</th>
                                    <th className="px-2 py-1 text-left">{t('manage_merge_result_val')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {p.biog_columns.map((col) => {
                                    const pv = primaryAttrs[col] ?? null;
                                    const sv = secondaryAttrs[col] ?? null;
                                    const mv = p.merged_person[col] ?? (sv ?? pv);
                                    const diff = pv != sv || mv != sv || Object.prototype.hasOwnProperty.call(p.merged_updates, col);
                                    const nameDiff = (col === 'c_name' || col === 'c_name_chn') && pv != sv;
                                    return (
                                        <tr key={col} className={`border-t border-border ${diff ? 'bg-amber-50' : ''}`}>
                                            <td className="px-2 py-1 font-mono text-xs">{col}</td>
                                            <td className={`px-2 py-1 break-all ${nameDiff ? 'text-red-600' : ''}`}>{val(pv)}</td>
                                            <td className={`px-2 py-1 break-all ${nameDiff ? 'text-red-600' : ''}`}>{val(sv)}</td>
                                            <td className="px-2 py-1 break-all">{val(mv)}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <h5 className="font-semibold">{t('manage_merge_other_data')}</h5>

            <DetailSection title={t('manage_merge_altname_section')} emptyText={t('manage_merge_no_altname')}
                primaryRows={p.altname_details_primary} secondaryRows={p.altname_details_secondary}
                primaryId={p.primary_id} secondaryId={p.secondary_id} t={t} tc={tc} />

            <DetailSection title={t('manage_merge_kin_section')} emptyText={t('manage_merge_no_kin')}
                primaryRows={p.kin_details_primary} secondaryRows={p.kin_details_secondary}
                primaryId={p.primary_id} secondaryId={p.secondary_id} t={t} tc={tc} />

            {/* ASSOC_DATA 各鍵 */}
            <div>
                <h6 className="font-semibold">ASSOC_DATA {t('manage_merge_comparison')}</h6>
                {assocCols.map(([key, label]) => (
                    <DetailSection key={key} title={label} emptyText={tc('no_data')}
                        primaryRows={p.assoc_details_primary?.[key] ?? []} secondaryRows={p.assoc_details_secondary?.[key] ?? []}
                        primaryCount={assocCounts(p.table_counts_primary)[key] ?? 0} secondaryCount={assocCounts(p.table_counts_secondary)[key] ?? 0}
                        primaryId={p.primary_id} secondaryId={p.secondary_id} t={t} tc={tc} />
                ))}
            </div>

            {/* 其他資料表 */}
            {TABLE_MAP.map(([key, label]) => {
                const pr = p.other_details_primary?.[label] ?? [];
                const sr = p.other_details_secondary?.[label] ?? [];
                const pc = (p.table_counts_primary[key] as number) ?? 0;
                const sc = (p.table_counts_secondary[key] as number) ?? 0;
                if (!pc && !sc && !pr.length && !sr.length) {
                    return (
                        <div key={key}>
                            <h6 className="font-semibold">{label}</h6>
                            <p className="text-sm text-muted-foreground">{t('manage_merge_no_data_row')}</p>
                        </div>
                    );
                }
                return (
                    <DetailSection key={key} title={label} emptyText={tc('no_data')}
                        primaryRows={pr} secondaryRows={sr} primaryCount={pc} secondaryCount={sc}
                        primaryId={p.primary_id} secondaryId={p.secondary_id} withSummary t={t} tc={tc} />
                );
            })}

            <div>
                <h5 className="font-semibold">{t('manage_merge_sql_preview')}</h5>
                <p className="text-xs text-muted-foreground">{t('manage_merge_sql_hint')}</p>
                {!!p.auto_min_target && p.auto_min_target != p.primary_id && (
                    <p className="text-xs text-muted-foreground">{t('manage_merge_id_adjust_hint', { id: String(p.auto_min_target) })}</p>
                )}
                <pre className="overflow-auto rounded bg-muted p-3 text-xs">{p.sql_preview.join('\n')}</pre>
            </div>

            <p className="text-sm text-muted-foreground">{p.notes}</p>
        </div>
    );
}

function PersonTable({ title, person, id, genderMatch, t }: { title: string; person: PersonSummary; id: string | number; genderMatch: string; t: (k: string) => string }) {
    return (
        <div>
            <h5 className="font-semibold">{title}</h5>
            <table className="w-full text-sm">
                <tbody>
                    <tr className="border-t border-border">
                        <th className="px-2 py-1 text-left">ID</th>
                        <td className="px-2 py-1">
                            {person.exists ? <a className="text-primary hover:underline" href={`/app/basicinformation/${id}/edit`} target="_blank" rel="noreferrer">{id}</a> : id}
                        </td>
                    </tr>
                    <tr className="border-t border-border">
                        <th className="px-2 py-1 text-left">{t('manage_merge_name_col')}</th>
                        <td className="px-2 py-1">{person.exists ? `${person.name_chn ?? ''} (${person.name ?? ''})` : <span className="text-red-600">{t('manage_merge_no_data')}</span>}</td>
                    </tr>
                    <tr className="border-t border-border">
                        <th className="px-2 py-1 text-left">{t('manage_merge_gender_col')}</th>
                        <td className={`px-2 py-1 ${genderMatch === 'different' ? 'text-red-600' : ''}`}>{person.exists ? (person.gender_label ?? t('manage_merge_unknown')) : '—'}</td>
                    </tr>
                    <tr className="border-t border-border">
                        <th className="px-2 py-1 text-left">{t('manage_merge_dynasty_col')}</th>
                        <td className="px-2 py-1">{person.exists ? (person.dynasty_name ?? t('manage_merge_unknown')) : '—'}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}

function DetailSection({ title, emptyText, primaryRows, secondaryRows, primaryCount, secondaryCount, primaryId, secondaryId, withSummary, t, tc }: {
    title: string; emptyText: string; primaryRows: Attrs[] | OtherRow[]; secondaryRows: Attrs[] | OtherRow[];
    primaryCount?: number; secondaryCount?: number; primaryId: string | number; secondaryId: string | number;
    withSummary?: boolean; t: (k: string) => string; tc: (k: string) => string;
}) {
    const pCount = primaryCount ?? primaryRows.length;
    const sCount = secondaryCount ?? secondaryRows.length;
    if (primaryRows.length === 0 && secondaryRows.length === 0 && !pCount && !sCount) {
        return (
            <div>
                <h6 className="font-semibold">{title}</h6>
                <p className="text-sm text-muted-foreground">{emptyText}</p>
            </div>
        );
    }
    const renderRows = (rows: Attrs[] | OtherRow[]) => {
        if (rows.length === 0) return <div className="text-muted-foreground">{tc('no_data')}</div>;
        return (
            <ul className="space-y-1">
                {rows.map((r, i) => {
                    const raw = withSummary ? ((r as OtherRow).raw ?? r) : r;
                    return (
                        <li key={i} className="text-xs">
                            {withSummary && <div>{(r as OtherRow).summary ?? t('manage_merge_no_summary')}</div>}
                            <code className="block whitespace-pre-wrap break-all text-muted-foreground">{JSON.stringify(raw)}</code>
                        </li>
                    );
                })}
            </ul>
        );
    };
    return (
        <div>
            <h6 className="font-semibold">{title}</h6>
            <div className="overflow-x-auto rounded-md border border-border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50">
                        <tr>
                            <th className="px-2 py-1 text-left">{t('manage_merge_field_col')}</th>
                            <th className="px-2 py-1 text-left">{t('manage_merge_primary_person')} ({primaryId ?? '-'}) — {pCount} {t('manage_merge_records_unit')}</th>
                            <th className="px-2 py-1 text-left">{t('manage_merge_secondary_person')} ({secondaryId ?? '-'}) — {sCount} {t('manage_merge_records_unit')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr className="border-t border-border align-top">
                            <th className="px-2 py-1 text-left">{t('manage_merge_content_summary')}</th>
                            <td className="px-2 py-1">{renderRows(primaryRows)}</td>
                            <td className="px-2 py-1">{renderRows(secondaryRows)}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    );
}
