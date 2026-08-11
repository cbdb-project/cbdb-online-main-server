import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import AuthLayout from '../../Layouts/AuthLayout';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { FormField } from '../../components/ui/FormField';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';

export default function Register() {
    const { shell } = usePage<SharedProps>().props;
    const t = useTranslation('common');

    const form = useForm({
        name: '',
        email: '',
        institution: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // register_url 為 null＝註冊已關閉；此頁本來就不該渲染得出來（GET /register 也一起消失），
        // 這裡只是不要讓 form.post(null) 變成打到當前頁面的怪異請求。
        if (!shell.register_url) {
            return;
        }
        // POST 到既有 /register（laravel/ui RegistersUsers），後端驗證/建立邏輯不變。
        form.post(shell.register_url, {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            subtitle={t('create_account')}
            heading={t('join_us')}
            footer={
                <span className="text-muted-foreground">
                    {t('have_account')}{' '}
                    <a href={shell.login_url} className="ml-1 text-primary hover:underline">
                        {t('sign_in')}
                    </a>
                </span>
            }
        >
            <form onSubmit={submit} className="space-y-4" noValidate>
                <FormField label={t('name')} htmlFor="name" error={form.errors.name}>
                    <Input
                        id="name"
                        type="text"
                        autoFocus
                        placeholder={t('name')}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                </FormField>

                <FormField label={t('email')} htmlFor="email" error={form.errors.email}>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        placeholder="name@example.com"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                    />
                </FormField>

                <FormField label={t('institution')} htmlFor="institution" error={form.errors.institution}>
                    <Input
                        id="institution"
                        type="text"
                        placeholder={t('institution_placeholder')}
                        value={form.data.institution}
                        onChange={(e) => form.setData('institution', e.target.value)}
                    />
                </FormField>

                <FormField label={t('password')} htmlFor="password" error={form.errors.password}>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        placeholder={t('choose_password')}
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                </FormField>

                <FormField label={t('confirm_password')} htmlFor="password_confirmation">
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
                    {t('create_account')}
                </Button>
            </form>
        </AuthLayout>
    );
}
