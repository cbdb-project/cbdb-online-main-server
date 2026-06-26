import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { FormField } from '../../components/ui/FormField';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

interface ForgotPasswordPageProps extends SharedProps {
    status?: string | null;
}

export default function ForgotPassword() {
    const { shell, status } = usePage<ForgotPasswordPageProps>().props;
    const t = useTranslation('common');

    const form = useForm({ email: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // POST 到既有 /password/email（laravel/ui SendsPasswordResetEmails），後端不變。
        form.post('/password/email');
    };

    return (
        <AuthLayout
            subtitle={t('reset_password_subtitle')}
            heading={t('send_reset_link_title')}
            footer={
                <a href={shell.login_url} className="text-primary hover:underline">
                    {t('back_to_login')}
                </a>
            }
        >
            {status && (
                <div className="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                    {status}
                </div>
            )}

            <form onSubmit={submit} className="space-y-4" noValidate>
                <FormField label={t('email')} htmlFor="email" error={form.errors.email}>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        autoFocus
                        placeholder="name@example.com"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </FormField>

                <Button type="submit" className="w-full" disabled={form.processing}>
                    {t('send_reset_link_btn')}
                </Button>
            </form>
        </AuthLayout>
    );
}
