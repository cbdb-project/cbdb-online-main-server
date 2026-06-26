import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Button, buttonVariants } from '../../components/ui/Button';
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
    // 對齊 legacy create.blade：只輸入單一中文姓名，姓/名於後端 store()→auto_pinyin 自動切分（含複姓）。
    // 不再要求使用者手動切分（先前的分欄會被後端 auto_pinyin 重切而丟棄，且不符 legacy UX）。
    const [nameChn, setNameChn] = useState('');
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
                        // 只送中文全名；後端 store()→auto_pinyin 依 pinyin 表（含複姓最長前綴）自動切分姓/名並生成拼音。
                        c_name_chn: nameChn,
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

                    <FormField label={t('person_id_label')} htmlFor="c_personid" required error={fieldErrors.c_personid?.[0]}>
                        <Input
                            id="c_personid"
                            type="number"
                            value={personId}
                            onChange={(e) => setPersonId(e.target.value)}
                            required
                        />
                    </FormField>

                    <FormField label={t('name_chn_label')} htmlFor="c_name_chn" required error={fieldErrors.c_name_chn?.[0] || fieldErrors.c_surname_chn?.[0] || fieldErrors.c_mingzi_chn?.[0]}>
                        <Input id="c_name_chn" value={nameChn} onChange={(e) => setNameChn(e.target.value)} required />
                        <p className="mt-1 text-xs text-muted-foreground">{t('name_autosplit_hint')}</p>
                    </FormField>

                    <div className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        {t('create_must_submit_hint')}
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" disabled={submitting}>{submitting ? tc('saving') : tc('create')}</Button>
                        <a href={index_url} className={buttonVariants({ variant: 'outline' })}>
                            {tc('cancel')}
                        </a>
                    </div>
                </form>
            )}
        </DashboardLayout>
    );
}
