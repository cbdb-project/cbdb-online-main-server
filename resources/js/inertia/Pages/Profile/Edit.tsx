import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { FormField } from '../../components/ui/FormField';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';
import { cn } from '../../lib/utils';
import TokenManager, { type ApiTokenUrls } from './TokenManager';

interface ProfileEditPageProps extends SharedProps {
    profile: { name: string; email: string; institution: string | null; avatar: string };
    avatars: string[];
    update_url: string;
    api_tokens: ApiTokenUrls | null;
}

interface ProfileForm {
    name: string;
    email: string;
    institution: string;
    avatar: string;
    current_password: string;
    new_password: string;
    new_password_confirmation: string;
    [key: string]: string;
}

export default function ProfileEdit() {
    const props = usePage<ProfileEditPageProps>().props;
    const { profile, avatars, update_url, api_tokens } = props;
    const t = useTranslation('common');

    const form = useForm<ProfileForm>({
        name: profile.name ?? '',
        email: profile.email ?? '',
        institution: profile.institution ?? '',
        avatar: profile.avatar ?? 'avatar0.png',
        current_password: '',
        new_password: '',
        new_password_confirmation: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.patch(update_url, {
            preserveScroll: true,
            onSuccess: () => form.reset('current_password', 'new_password', 'new_password_confirmation'),
        });
    };

    return (
        <DashboardLayout title={t('profile_settings')} description={t('profile_settings_desc')}>
            <form onSubmit={submit} className="max-w-3xl space-y-4">
                <section className="rounded-lg border border-border bg-card">
                    <div className="border-b border-border px-4 py-2 font-medium">{t('basic_info_section')}</div>
                    <div className="space-y-3 p-4">
                        <FormField label={t('name')} htmlFor="name" required error={form.errors.name}>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </FormField>
                        <FormField label="Email" htmlFor="email" required error={form.errors.email}>
                            <Input id="email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </FormField>
                        <FormField label={t('institution_label')} htmlFor="institution" error={form.errors.institution}>
                            <Input id="institution" value={form.data.institution} onChange={(e) => form.setData('institution', e.target.value)} />
                        </FormField>

                        <FormField label={t('avatar')} error={form.errors.avatar}>
                            <div>
                                <div className="mb-2 flex items-center gap-3">
                                    <img
                                        src={`/images/avatar/${form.data.avatar}`}
                                        alt={t('current_using')}
                                        className="h-16 w-16 rounded border border-border object-cover"
                                    />
                                    <span className="text-sm text-muted-foreground">{form.data.avatar}</span>
                                </div>
                                <p className="mb-2 text-xs text-muted-foreground">{t('avatar_select_hint_msg')}</p>
                                <div className="flex gap-2 overflow-x-auto py-1">
                                    {avatars.map((a, i) => (
                                        <button
                                            type="button"
                                            key={a}
                                            title={i === 0 ? t('default_avatar_title') : `${t('avatar')} ${i}`}
                                            onClick={() => form.setData('avatar', a)}
                                            className={cn(
                                                'relative h-16 w-16 shrink-0 overflow-hidden rounded border-2',
                                                form.data.avatar === a ? 'border-green-500' : 'border-border'
                                            )}
                                        >
                                            <img src={`/images/avatar/${a}`} alt={a} className="h-full w-full object-cover" />
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </FormField>
                    </div>
                </section>

                <section className="rounded-lg border border-border bg-card">
                    <div className="flex flex-wrap items-center justify-between border-b border-border px-4 py-2">
                        <span className="font-medium">{t('change_password')}</span>
                        <span className="text-xs text-muted-foreground">{t('password_no_change_hint')}</span>
                    </div>
                    <div className="space-y-3 p-4">
                        <FormField label={t('current_password')} htmlFor="current_password" hint={t('current_password_hint')} error={form.errors.current_password}>
                            <Input id="current_password" type="password" autoComplete="current-password" value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)} />
                        </FormField>
                        <FormField label={t('new_password')} htmlFor="new_password" hint={t('new_password_hint')} error={form.errors.new_password}>
                            <Input id="new_password" type="password" autoComplete="new-password" value={form.data.new_password} onChange={(e) => form.setData('new_password', e.target.value)} />
                        </FormField>
                        <FormField label={t('confirm_new_password')} htmlFor="new_password_confirmation">
                            <Input id="new_password_confirmation" type="password" autoComplete="new-password" value={form.data.new_password_confirmation} onChange={(e) => form.setData('new_password_confirmation', e.target.value)} />
                        </FormField>
                    </div>
                </section>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>{t('save_changes')}</Button>
                    <a href="/home" className="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm hover:bg-muted">
                        {t('cancel')}
                    </a>
                </div>
            </form>

            {api_tokens && <TokenManager urls={api_tokens} className="mt-4 max-w-3xl" />}
        </DashboardLayout>
    );
}
