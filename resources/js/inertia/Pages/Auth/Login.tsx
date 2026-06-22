import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { FormField } from '../../components/ui/FormField';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

interface LoginPageProps extends SharedProps {
    status?: string | null;
    intended?: string | null;
}

export default function Login() {
    const { shell, status, intended } = usePage<LoginPageProps>().props;
    const t = useTranslation('common');

    const form = useForm({
        email: '',
        password: '',
        remember: false,
        redirect: intended ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // POST 到既有 /login（laravel/ui AuthenticatesUsers），後端邏輯不變。
        form.post(shell.login_url, {
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <AuthLayout
            subtitle={t('sign_in_subtitle')}
            heading={t('welcome_back')}
            footer={
                <span className="text-muted-foreground">
                    {t('need_account')}{' '}
                    <a href={shell.register_url} className="ml-1 text-primary hover:underline">
                        {t('register_now')}
                    </a>
                </span>
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

                <FormField label={t('password')} htmlFor="password" error={form.errors.password}>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="current-password"
                        placeholder={t('enter_password')}
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                </FormField>

                <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm text-muted-foreground">
                        <input
                            type="checkbox"
                            className="h-4 w-4 rounded border-input"
                            checked={form.data.remember}
                            onChange={(e) => form.setData('remember', e.target.checked)}
                        />
                        {t('remember_me')}
                    </label>
                    <a href="/password/reset" className="text-sm text-primary hover:underline">
                        {t('forgot_password')}
                    </a>
                </div>

                <Button type="submit" className="w-full" disabled={form.processing}>
                    {t('sign_in')}
                </Button>
            </form>
        </AuthLayout>
    );
}
