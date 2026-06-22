import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { FormField } from '../../components/ui/FormField';
import { getCsrfToken } from '../../components/PersonBrowser/shared/csrf';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

interface BasicInfoCreatePageProps extends SharedProps {
    temp_id: number;
    can_create: boolean;
    create_endpoint: string;
    edit_template: string;
    index_url: string;
}

export default function BasicInformationCreate() {
    const props = usePage<BasicInfoCreatePageProps>().props;
    const { temp_id, can_create, create_endpoint, edit_template, index_url } = props;
    const t = useTranslation('person');
    const tc = useTranslation('common');

    const [personId, setPersonId] = useState(String(temp_id ?? ''));
    const [surnameChn, setSurnameChn] = useState('');
    const [mingziChn, setMingziChn] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    const submit = async (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);
        setError(null);
        setFieldErrors({});

        const idNum = Number(personId);

        try {
            const response = await fetch(create_endpoint, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    resource: 'basicinformation',
                    person_id: idNum,
                    mode: 'direct',
                    operation: 'create',
                    target: { pk: { c_personid: idNum } },
                    changes: {
                        // 中文姓名於後端由 repository store() 合成；此處提供姓/名核心欄位。
                        c_surname_chn: surnameChn,
                        c_mingzi_chn: mingziChn,
                        c_name_chn: `${surnameChn}${mingziChn}`,
                    },
                }),
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok || !json?.ok) {
                if (json?.errors && typeof json.errors === 'object') {
                    setFieldErrors(json.errors as Record<string, string[]>);
                }
                throw new Error(json?.message || `${t('create_failed')} (HTTP ${response.status})`);
            }
            const newId = json?.result?.pk?.c_personid ?? idNum;
            router.visit(edit_template.replace('__ID__', String(newId)));
        } catch (err) {
            setError(err instanceof Error ? err.message : t('create_failed'));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <DashboardLayout
            title={t('create_person')}
            breadcrumbs={[
                { label: t('person_records'), url: index_url },
                { label: tc('add') },
            ]}
        >
            {!can_create ? (
                <div className="rounded-lg border border-border bg-card p-4 text-sm text-muted-foreground">
                    {t('create_person_hint')}
                </div>
            ) : (
                <form onSubmit={submit} className="max-w-2xl space-y-3 rounded-lg border border-border bg-card p-4">
                    <p className="text-sm text-muted-foreground">{t('create_person_hint')}</p>

                    {error && (
                        <div className="rounded border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                            {error}
                        </div>
                    )}

                    <FormField label={t('person_id_label')} htmlFor="c_personid" error={fieldErrors.c_personid?.[0]}>
                        <Input
                            id="c_personid"
                            type="number"
                            value={personId}
                            onChange={(e) => setPersonId(e.target.value)}
                            required
                        />
                    </FormField>

                    <FormField label={t('surname_chn_label')} htmlFor="c_surname_chn" error={fieldErrors.c_surname_chn?.[0]}>
                        <Input id="c_surname_chn" value={surnameChn} onChange={(e) => setSurnameChn(e.target.value)} />
                    </FormField>

                    <FormField label={t('mingzi_chn_label')} htmlFor="c_mingzi_chn" error={fieldErrors.c_mingzi_chn?.[0]}>
                        <Input id="c_mingzi_chn" value={mingziChn} onChange={(e) => setMingziChn(e.target.value)} />
                    </FormField>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={submitting}>{tc('create')}</Button>
                        <a href={index_url} className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                            {tc('cancel')}
                        </a>
                    </div>
                </form>
            )}
        </DashboardLayout>
    );
}
