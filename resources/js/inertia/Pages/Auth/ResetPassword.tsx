import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { FormField } from '../../components/ui/FormField';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

interface ResetPasswordPageProps extends SharedProps {
    token: string;
    email?: string | null;
    status?: string | null;
}

export default function ResetPassword() {
    const { shell, token, email } = usePage<ResetPasswordPageProps>().props;
    const t = useTranslation('common');

    const form = useForm({
        token,
        email: email ?? '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // POST 到既有 /password/reset（laravel/ui ResetsPasswords::reset，路由名 password.update），後端不變。
        form.post('/password/reset', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            subtitle={t('set_new_password_subtitle')}
            heading={t('update_password_title')}
            footer={
                <a href={shell.login_url} className="text-primary hover:underline">
                    {t('back_to_login')}
                </a>
            }
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                <input type="hidden" name="token" value={form.data.token} />

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

                <FormField label={t('new_password')} htmlFor="password" error={form.errors.password}>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        placeholder={t('choose_password')}
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                </FormField>

                <FormField
                    label={t('confirm_password')}
                    htmlFor="password_confirmation"
                    error={form.errors.password_confirmation}
                >
                    <Input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        placeholder={t('confirm_password')}
                        value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    />
                </FormField>

                <Button type="submit" className="w-full" disabled={form.processing}>
                    {t('reset_password_btn')}
                </Button>
            </form>
        </AuthLayout>
    );
}
