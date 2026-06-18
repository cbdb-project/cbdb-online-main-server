import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

interface RepairPageProps extends SharedProps {
    urls: { kinship: string; assoc: string };
}

/** 讀取 XSRF-TOKEN cookie，供同源 JSON 請求帶 CSRF header。 */
function xsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

type ResultState =
    | { kind: 'success'; message: string; original: Record<string, unknown>; created: Record<string, unknown> }
    | { kind: 'error'; message: string }
    | null;

interface FieldConfig {
    name: string;
    labelKey: string;
    phKey: string;
    helpKey: string;
}

interface FormConfig {
    type: 'kinship' | 'assoc';
    url: string;
    titleKey: string;
    icon: string;
    btnKey: string;
    confirmKey: string;
    idField: string; // c_kin_id | c_assoc_id
    codeField: string; // c_kin_code | c_assoc_code
    fields: FieldConfig[];
    accent: 'primary' | 'success';
}

export default function UnidirectionalRelationshipRepairIndex() {
    const props = usePage<RepairPageProps>().props;
    const t = useTranslation('admin');

    const kinship: FormConfig = {
        type: 'kinship',
        url: props.urls.kinship,
        titleKey: 'unidirect_kinship_title',
        icon: 'fa fa-users',
        btnKey: 'unidirect_kinship_btn',
        confirmKey: 'unidirect_kinship_confirm',
        idField: 'c_kin_id',
        codeField: 'c_kin_code',
        accent: 'primary',
        fields: [
            { name: 'c_personid', labelKey: 'unidirect_kinship_personid_label', phKey: 'unidirect_kinship_personid_ph', helpKey: 'unidirect_kinship_personid_help' },
            { name: 'c_kin_id', labelKey: 'unidirect_kinship_kin_id_label', phKey: 'unidirect_kinship_kin_id_ph', helpKey: 'unidirect_kinship_kin_id_help' },
            { name: 'c_kin_code', labelKey: 'unidirect_kinship_kin_code_label', phKey: 'unidirect_kinship_kin_code_ph', helpKey: 'unidirect_kinship_kin_code_help' },
            { name: 'new_c_kin_code', labelKey: 'unidirect_kinship_new_code_label', phKey: 'unidirect_kinship_new_code_ph', helpKey: 'unidirect_kinship_new_code_help' },
        ],
    };
    const assoc: FormConfig = {
        type: 'assoc',
        url: props.urls.assoc,
        titleKey: 'unidirect_assoc_title',
        icon: 'fa fa-sitemap',
        btnKey: 'unidirect_assoc_btn',
        confirmKey: 'unidirect_assoc_confirm',
        idField: 'c_assoc_id',
        codeField: 'c_assoc_code',
        accent: 'success',
        fields: [
            { name: 'c_personid', labelKey: 'unidirect_kinship_personid_label', phKey: 'unidirect_kinship_personid_ph', helpKey: 'unidirect_assoc_personid_help' },
            { name: 'c_assoc_id', labelKey: 'unidirect_assoc_assoc_id_label', phKey: 'unidirect_assoc_assoc_id_ph', helpKey: 'unidirect_kinship_kin_id_help' },
            { name: 'c_assoc_code', labelKey: 'unidirect_assoc_assoc_code_label', phKey: 'unidirect_assoc_assoc_code_ph', helpKey: 'unidirect_kinship_kin_code_help' },
            { name: 'new_c_assoc_code', labelKey: 'unidirect_assoc_new_code_label', phKey: 'unidirect_assoc_new_code_ph', helpKey: 'unidirect_assoc_new_code_help' },
        ],
    };

    return (
        <DashboardLayout title={t('unidirect_title')} breadcrumbs={[{ label: t('unidirect_title') }]}>
            <div className="space-y-6">
                <RepairForm config={kinship} t={t} />
                <RepairForm config={assoc} t={t} />
                <DescriptionPanel t={t} />
            </div>
        </DashboardLayout>
    );
}

function RepairForm({ config, t }: { config: FormConfig; t: (k: string, r?: Record<string, string>) => string }) {
    const empty = Object.fromEntries(config.fields.map((f) => [f.name, ''])) as Record<string, string>;
    const [form, setForm] = useState<Record<string, string>>(empty);
    const [submitting, setSubmitting] = useState(false);
    const [result, setResult] = useState<ResultState>(null);

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!window.confirm(t(config.confirmKey))) return;
        setSubmitting(true);
        setResult(null);
        try {
            const res = await fetch(config.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(form),
            });
            let json: Record<string, unknown> = {};
            try {
                json = await res.json();
            } catch {
                json = {};
            }
            if (res.ok && json.success) {
                setResult({ kind: 'success', message: String(json.message ?? ''), original: (json.original as Record<string, unknown>) ?? {}, created: (json.created as Record<string, unknown>) ?? {} });
                setForm(empty);
            } else {
                const msg = (json.message as string)
                    ?? (res.status === 0 ? t('unidirect_network_failed') : t('unidirect_server_error', { status: String(res.status) }));
                setResult({ kind: 'error', message: msg });
            }
        } catch {
            setResult({ kind: 'error', message: t('unidirect_network_failed') });
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="rounded-lg border border-border bg-card">
            <div className={`rounded-t-lg px-4 py-2 font-semibold text-white ${config.accent === 'primary' ? 'bg-primary' : 'bg-green-600'}`}>
                <i className={`${config.icon} mr-1`} aria-hidden /> {t(config.titleKey)}
            </div>
            <div className="p-4">
                {result?.kind === 'success' && (
                    <div className="mb-3 rounded border border-green-300 bg-green-50 px-4 py-2 text-sm text-green-800">
                        <strong>{t('unidirect_success_title')}</strong> {result.message}
                        <div className="mt-2 rounded bg-muted p-2 font-mono text-xs">
                            <div><strong>{t('unidirect_original_relation')}</strong>c_personid={String(result.original.c_personid)}, {config.idField}={String(result.original[config.idField])}, {config.codeField}={String(result.original[config.codeField])}</div>
                            <div><strong>{t('unidirect_new_relation')}</strong>c_personid={String(result.created.c_personid)}, {config.idField}={String(result.created[config.idField])}, {config.codeField}={String(result.created[config.codeField])}</div>
                        </div>
                    </div>
                )}
                {result?.kind === 'error' && (
                    <div className="mb-3 rounded border border-red-300 bg-red-50 px-4 py-2 text-sm text-red-800">
                        <strong>{t('unidirect_failure_title')}</strong> {result.message}
                    </div>
                )}

                <form onSubmit={submit} className="space-y-4">
                    {config.fields.map((f) => (
                        <div key={f.name}>
                            <label className="mb-1 block text-sm font-medium">{t(f.labelKey)}</label>
                            <input
                                type="number"
                                required
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                placeholder={t(f.phKey)}
                                value={form[f.name]}
                                onChange={(e) => setForm((s) => ({ ...s, [f.name]: e.target.value }))}
                            />
                            <span className="mt-1 block text-xs text-muted-foreground">{t(f.helpKey)}</span>
                        </div>
                    ))}
                    <div className="flex gap-2">
                        <Button type="submit" disabled={submitting} variant={config.accent === 'success' ? 'default' : 'default'}>
                            {submitting ? <><i className="fa fa-spinner fa-spin mr-1" aria-hidden />{t('unidirect_processing')}</> : <><i className="fa fa-check mr-1" aria-hidden />{t(config.btnKey)}</>}
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => { setForm(empty); setResult(null); }}>
                            <i className="fa fa-undo mr-1" aria-hidden />{t('unidirect_reset_btn')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function DescriptionPanel({ t }: { t: (k: string) => string }) {
    const html = (k: string) => ({ __html: t(k) });
    return (
        <div className="rounded-lg border border-blue-200 bg-blue-50/40 p-4 text-sm">
            <h3 className="mb-2 font-semibold"><i className="fa fa-info-circle mr-1" aria-hidden />{t('unidirect_desc_title')}</h3>

            <h4 className="mt-2 font-semibold">{t('unidirect_desc_function_title')}</h4>
            <p>{t('unidirect_desc_function_text')}</p>

            <h4 className="mt-3 font-semibold">{t('unidirect_desc_kinship_title')}</h4>
            <ul className="list-disc space-y-1 pl-5">
                <li dangerouslySetInnerHTML={html('unidirect_desc_kinship_use')} />
                <li>
                    <span dangerouslySetInnerHTML={html('unidirect_desc_kinship_params')} />
                    <ul className="list-disc pl-5">
                        <li><code>c_personid</code>：{t('unidirect_kinship_personid_label')}</li>
                        <li><code>c_kin_id</code>：{t('unidirect_kinship_kin_id_label')}</li>
                        <li><code>c_kin_code</code>：{t('unidirect_kinship_kin_code_label')}</li>
                        <li><code>{t('unidirect_kinship_new_code_label')}</code></li>
                    </ul>
                </li>
                <li>
                    <span dangerouslySetInnerHTML={html('unidirect_desc_logic_title')} />
                    <ol className="list-decimal pl-5">
                        <li>{t('unidirect_desc_logic_1')}</li>
                        <li>{t('unidirect_desc_logic_2')}</li>
                        <li>{t('unidirect_desc_logic_3')}</li>
                        <li>{t('unidirect_desc_logic_4')}</li>
                        <li>{t('unidirect_desc_logic_5')}</li>
                    </ol>
                </li>
                <li dangerouslySetInnerHTML={html('unidirect_desc_kinship_example')} />
            </ul>

            <h4 className="mt-3 font-semibold">{t('unidirect_desc_assoc_title')}</h4>
            <ul className="list-disc space-y-1 pl-5">
                <li dangerouslySetInnerHTML={html('unidirect_desc_assoc_use')} />
                <li>
                    <span dangerouslySetInnerHTML={html('unidirect_desc_kinship_params')} />
                    <ul className="list-disc pl-5">
                        <li><code>c_personid</code>：{t('unidirect_kinship_personid_label')}</li>
                        <li><code>c_assoc_id</code>：{t('unidirect_assoc_assoc_id_label')}</li>
                        <li><code>c_assoc_code</code>：{t('unidirect_assoc_assoc_code_label')}</li>
                        <li><code>{t('unidirect_assoc_new_code_label')}</code></li>
                    </ul>
                </li>
                <li>
                    <span dangerouslySetInnerHTML={html('unidirect_desc_logic_title')} />
                    <ol className="list-decimal pl-5">
                        <li>{t('unidirect_desc_assoc_logic_1')}</li>
                        <li>{t('unidirect_desc_logic_2')}</li>
                        <li>{t('unidirect_desc_logic_3')}</li>
                        <li>{t('unidirect_desc_logic_4')}</li>
                        <li>{t('unidirect_desc_assoc_logic_5')}</li>
                    </ol>
                </li>
                <li dangerouslySetInnerHTML={html('unidirect_desc_assoc_example')} />
            </ul>

            <h4 className="mt-3 font-semibold">{t('unidirect_desc_notes_title')}</h4>
            <ul className="list-disc space-y-1 pl-5">
                <li className="text-red-600" dangerouslySetInnerHTML={html('unidirect_desc_unique')} />
                <li className="text-red-600" dangerouslySetInnerHTML={html('unidirect_desc_duplicate')} />
                <li className="text-amber-600" dangerouslySetInnerHTML={html('unidirect_desc_code_warning')} />
                <li className="text-sky-700" dangerouslySetInnerHTML={html('unidirect_desc_integrity')} />
                <li className="text-sky-700" dangerouslySetInnerHTML={html('unidirect_desc_permission')} />
            </ul>

            <h4 className="mt-3 font-semibold">{t('unidirect_desc_tables_title')}</h4>
            <ul className="list-disc pl-5">
                <li><code>KIN_DATA</code></li>
                <li><code>KINSHIP_CODES</code></li>
                <li><code>ASSOC_DATA</code></li>
                <li><code>ASSOC_CODES</code></li>
            </ul>
        </div>
    );
}
