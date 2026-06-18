import React, { useCallback, useEffect, useState } from 'react';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { FormField } from '../../components/ui/FormField';
import { useTranslation } from '../../hooks/useTranslation';
import { cn } from '../../lib/utils';

export interface ApiTokenUrls {
    index: string;
    store: string;
    destroy_all: string;
    destroy_template: string; // 含 __ID__ 佔位
}

interface ApiToken {
    id: number;
    name: string;
    created_at: string | null;
    last_used_at: string | null;
    expires_at: string | null;
}

/** 讀取 XSRF-TOKEN cookie（Laravel 加密 token），供同源 JSON 請求帶 CSRF header。 */
function xsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function apiFetch(url: string, method: string, body?: unknown): Promise<Response> {
    return fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });
}

/**
 * API token 管理（建立 / 列表 / 撤銷 / 全部撤銷）。與舊 Blade 頁同樣呼叫
 * api-tokens.* JSON 端點；CSRF 以 XSRF-TOKEN cookie → X-XSRF-TOKEN header。
 */
export default function TokenManager({ urls, className }: { urls: ApiTokenUrls; className?: string }) {
    const t = useTranslation('common');
    const [tokens, setTokens] = useState<ApiToken[]>([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [name, setName] = useState('');
    const [expiresIn, setExpiresIn] = useState('');
    const [creating, setCreating] = useState(false);
    const [createError, setCreateError] = useState<string | null>(null);
    const [newToken, setNewToken] = useState<string | null>(null);

    const load = useCallback(async () => {
        setLoading(true);
        setLoadError(false);
        try {
            const res = await apiFetch(urls.index, 'GET');
            if (!res.ok) {
                throw new Error('load failed');
            }
            setTokens(await res.json());
        } catch {
            setLoadError(true);
        } finally {
            setLoading(false);
        }
    }, [urls.index]);

    useEffect(() => {
        load();
    }, [load]);

    const create = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!name.trim()) {
            return;
        }
        setCreating(true);
        setCreateError(null);
        try {
            const payload: Record<string, unknown> = { name: name.trim() };
            if (expiresIn) {
                payload.expires_in = parseInt(expiresIn, 10);
            }
            const res = await apiFetch(urls.store, 'POST', payload);
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                // 浮現伺服器驗證訊息（message 或 errors），與舊頁行為一致。
                const msg =
                    data?.message ??
                    (data?.errors ? Object.values(data.errors).flat().join(', ') : null) ??
                    t('network_error_retry');
                setCreateError(String(msg));
                return;
            }
            const plain = data?.token?.plainTextToken ?? data?.plainTextToken ?? null;
            if (plain) {
                setNewToken(plain);
            }
            setName('');
            setExpiresIn('');
            await load();
        } catch {
            setCreateError(t('network_error_retry'));
        } finally {
            setCreating(false);
        }
    };

    const revoke = async (token: ApiToken) => {
        if (!window.confirm(t('token_revoke_confirm').replace(':name', token.name))) {
            return;
        }
        await apiFetch(urls.destroy_template.replace('__ID__', String(token.id)), 'DELETE');
        await load();
    };

    const revokeAll = async () => {
        if (!window.confirm(t('token_revoke_all_confirm'))) {
            return;
        }
        await apiFetch(urls.destroy_all, 'DELETE');
        await load();
    };

    const fmt = (d: string | null, fallback: string) => {
        if (!d) {
            return fallback;
        }
        const date = new Date(d);
        return Number.isNaN(date.getTime()) ? d : date.toLocaleString();
    };

    return (
        <section className={cn('rounded-lg border border-border bg-card', className)}>
            <div className="border-b border-border px-4 py-2 font-medium">{t('api_token_management')}</div>
            <div className="space-y-4 p-4">
                <p className="text-sm text-muted-foreground">{t('api_token_description')}</p>

                {newToken && (
                    <div className="rounded border border-green-400 bg-green-50 p-3 text-sm">
                        <div className="font-semibold text-green-800">{t('token_created_success')}</div>
                        <div className="text-red-700">{t('token_save_warning')}</div>
                        <code className="mt-1 block break-all rounded bg-white p-2 text-xs">{newToken}</code>
                    </div>
                )}

                <form onSubmit={create} className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <FormField className="md:col-span-2" label={t('token_name')} htmlFor="token-name" required hint={t('token_name_hint')}>
                        <Input id="token-name" value={name} placeholder={t('token_name_placeholder')} onChange={(e) => setName(e.target.value)} required />
                    </FormField>
                    <FormField label={t('token_expiry')} htmlFor="token-expires">
                        <Select id="token-expires" value={expiresIn} onChange={(e) => setExpiresIn(e.target.value)}>
                            <option value="">{t('token_never_expires')}</option>
                            <option value="30">{t('token_30_days')}</option>
                            <option value="90">{t('token_90_days')}</option>
                            <option value="180">{t('token_180_days')}</option>
                            <option value="365">{t('token_1_year')}</option>
                        </Select>
                    </FormField>
                    {createError && (
                        <p className="text-sm text-destructive md:col-span-3" role="alert">
                            {createError}
                        </p>
                    )}
                    <div className="md:col-span-3">
                        <Button type="submit" disabled={creating}>
                            <i className="fa fa-key" aria-hidden /> {t('create_token_btn')}
                        </Button>
                    </div>
                </form>

                <div>
                    <h5 className="mb-2 font-medium">{t('existing_tokens')}</h5>
                    {loading ? (
                        <p className="text-sm text-muted-foreground">{t('tokens_loading')}</p>
                    ) : loadError ? (
                        <p className="text-sm text-destructive">{t('token_load_failed')}</p>
                    ) : tokens.length === 0 ? (
                        <p className="text-sm text-muted-foreground">{t('no_tokens_yet')}</p>
                    ) : (
                        <>
                            <div className="overflow-x-auto rounded border border-border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50">
                                        <tr>
                                            <th className="px-3 py-2 text-left font-medium">{t('token_col_name')}</th>
                                            <th className="px-3 py-2 text-left font-medium">{t('token_col_created')}</th>
                                            <th className="px-3 py-2 text-left font-medium">{t('token_col_last_used')}</th>
                                            <th className="px-3 py-2 text-left font-medium">{t('token_col_expires')}</th>
                                            <th className="px-3 py-2 text-left font-medium">{t('token_col_actions')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {tokens.map((tok) => (
                                            <tr key={tok.id} className="border-t border-border">
                                                <td className="px-3 py-2">{tok.name}</td>
                                                <td className="px-3 py-2">{fmt(tok.created_at, '')}</td>
                                                <td className="px-3 py-2">{fmt(tok.last_used_at, t('token_never_used'))}</td>
                                                <td className="px-3 py-2">{fmt(tok.expires_at, t('token_permanent'))}</td>
                                                <td className="px-3 py-2">
                                                    <Button size="sm" variant="destructive" onClick={() => revoke(tok)}>
                                                        <i className="fa fa-trash" aria-hidden /> {t('token_revoke')}
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {tokens.length > 1 && (
                                <Button size="sm" variant="secondary" className="mt-2" onClick={revokeAll}>
                                    <i className="fa fa-trash-alt" aria-hidden /> {t('token_revoke_all')}
                                </Button>
                            )}
                        </>
                    )}
                </div>
            </div>
        </section>
    );
}
