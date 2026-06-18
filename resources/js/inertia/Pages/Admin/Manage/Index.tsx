import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import DashboardLayout from '../../../Layouts/DashboardLayout';
import { Button } from '../../../components/ui/Button';
import { Input } from '../../../components/ui/Input';
import { Select } from '../../../components/ui/Select';
import { Pagination, type PaginationMeta } from '../../../components/ui/Pagination';
import { useTranslation } from '../../../hooks/useTranslation';
import type { SharedProps } from '../../../types/page';

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    institution: string | null;
    is_active: boolean;
    role_name: string;
}

interface ManageIndexPageProps extends SharedProps {
    data: { rows: ManagedUser[]; meta: PaginationMeta };
    inactive_users: ManagedUser[];
    filters: { search: string; sort_by: string; sort_order: 'asc' | 'desc'; per_page: number };
    edit_template: string;
}

const SORTABLE: { key: string; label: string }[] = [
    { key: 'id', label: 'ID' },
    { key: 'name', label: 'Name' },
    { key: 'email', label: 'Email' },
    { key: 'institution', label: 'Institution' },
    { key: 'is_active', label: 'manage_approved_col' },
    { key: 'is_admin', label: 'manage_role_col' },
];

export default function ManageIndex() {
    const props = usePage<ManageIndexPageProps>().props;
    const { data, inactive_users, filters, edit_template } = props;
    const t = useTranslation('admin');
    const tc = useTranslation('common');
    const tNav = useTranslation('nav');

    const [search, setSearch] = useState(filters.search ?? '');
    const path = typeof window !== 'undefined' ? window.location.pathname : '/app/manage';

    const reload = (params: Record<string, string | number | undefined>) =>
        router.get(path, params, { preserveState: true, preserveScroll: true, replace: true });

    const base = (overrides: Record<string, string | number | undefined> = {}) => ({
        search: search || undefined,
        sort_by: filters.sort_by,
        sort_order: filters.sort_order,
        per_page: filters.per_page,
        ...overrides,
    });

    const doSearch = (e: React.FormEvent) => {
        e.preventDefault();
        reload(base({ page: undefined }));
    };
    const toggleSort = (col: string) => {
        const nextOrder = filters.sort_by === col && filters.sort_order === 'asc' ? 'desc' : 'asc';
        reload(base({ sort_by: col, sort_order: nextOrder, page: 1 }));
    };
    const sortIcon = (col: string) => (filters.sort_by === col ? (filters.sort_order === 'asc' ? '▲' : '▼') : '');

    const editUrl = (id: number) => edit_template.replace('__ID__', String(id));

    const statusBadge = (active: boolean) =>
        active ? (
            <span className="rounded bg-green-100 px-2 py-0.5 text-xs text-green-800">{t('manage_activated')}</span>
        ) : (
            <span className="rounded bg-yellow-100 px-2 py-0.5 text-xs text-yellow-800">{t('manage_not_activated')}</span>
        );

    const UserRow = ({ u }: { u: ManagedUser }) => (
        <tr className="border-t border-border hover:bg-muted/30">
            <td className="px-3 py-1.5">{u.id}</td>
            <td className="px-3 py-1.5">{u.name}</td>
            <td className="px-3 py-1.5">{u.email}</td>
            <td className="px-3 py-1.5">{u.institution}</td>
            <td className="px-3 py-1.5">{statusBadge(u.is_active)}</td>
            <td className="px-3 py-1.5">
                <span className="rounded bg-blue-100 px-2 py-0.5 text-xs text-blue-800">{u.role_name}</span>
            </td>
            <td className="px-3 py-1.5">
                <a href={editUrl(u.id)} className="rounded bg-primary px-2 py-0.5 text-xs text-primary-foreground hover:bg-primary/90">
                    <i className="fa fa-edit" aria-hidden /> {tc('edit')}
                </a>
            </td>
        </tr>
    );

    return (
        <DashboardLayout title={tNav('user_management')}>
            {inactive_users.length > 0 && (
                <div className="mb-4 rounded-lg border border-yellow-400 bg-card">
                    <div className="border-b border-border px-4 py-2 font-medium">
                        <i className="fas fa-user-clock mr-1" aria-hidden />
                        {t('manage_inactive_users_title', { count: String(inactive_users.length) })}
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-3 py-2 text-left font-medium">ID</th>
                                    <th className="px-3 py-2 text-left font-medium">Name</th>
                                    <th className="px-3 py-2 text-left font-medium">Email</th>
                                    <th className="px-3 py-2 text-left font-medium">Institution</th>
                                    <th className="px-3 py-2 text-left font-medium">{t('manage_approved_col')}</th>
                                    <th className="px-3 py-2 text-left font-medium">{t('manage_role_col')}</th>
                                    <th className="px-3 py-2 text-left font-medium">{t('manage_actions_col')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {inactive_users.map((u) => <UserRow key={u.id} u={u} />)}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="rounded-lg border border-border bg-card">
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-2">
                    <span className="font-medium">{tNav('user_management')}</span>
                    <form onSubmit={doSearch} className="flex items-center gap-1">
                        <Input value={search} placeholder={t('manage_search_placeholder')} onChange={(e) => setSearch(e.target.value)} className="h-8 w-52" />
                        <Button type="submit" size="sm"><i className="fa fa-search" aria-hidden /> {tc('search')}</Button>
                        {filters.search && (
                            <Button type="button" size="sm" variant="secondary" onClick={() => { setSearch(''); reload(base({ search: undefined, page: undefined })); }}>
                                {tc('clear')}
                            </Button>
                        )}
                    </form>
                </div>
                <div className="overflow-x-auto p-2">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50">
                            <tr>
                                {SORTABLE.map((c) => (
                                    <th key={c.key} className="px-3 py-2 text-left font-medium">
                                        <button type="button" className="hover:underline" onClick={() => toggleSort(c.key)}>
                                            {c.label.startsWith('manage_') ? t(c.label) : c.label} {sortIcon(c.key)}
                                        </button>
                                    </th>
                                ))}
                                <th className="px-3 py-2 text-left font-medium">{t('manage_actions_col')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.rows.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                                        {filters.search ? t('manage_no_results') : t('manage_no_users')}
                                    </td>
                                </tr>
                            ) : (
                                data.rows.map((u) => <UserRow key={u.id} u={u} />)
                            )}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                    <label className="flex items-center gap-2 text-sm text-muted-foreground">
                        {t('manage_per_page')}
                        <Select
                            value={String(filters.per_page)}
                            onChange={(e) => reload(base({ per_page: Number(e.target.value), page: 1 }))}
                            className="h-8 w-20"
                        >
                            {[10, 25, 50, 75, 100].map((n) => <option key={n} value={n}>{n}</option>)}
                        </Select>
                    </label>
                    <Pagination
                        meta={data.meta}
                        onPageChange={(page) => reload(base({ page }))}
                        summaryTemplate="{from}–{to} / {total}"
                        labels={{ previous: tc('previous'), next: tc('next') }}
                    />
                </div>
            </div>
        </DashboardLayout>
    );
}
