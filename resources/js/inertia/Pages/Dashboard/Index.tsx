import React from 'react';
import { usePage } from '@inertiajs/react';
import { User, IdCard, Briefcase, BookText, Users, History, type LucideIcon } from 'lucide-react';
import DashboardLayout from '../../Layouts/DashboardLayout';
import { useTranslation } from '../../hooks/useTranslation';
import type { SharedProps } from '../../types/page';
import { cn } from '../../lib/utils';

interface UserStat {
    user_name: string | null;
    count: number;
}

interface DashboardPageProps extends SharedProps {
    totalPersons: number;
    totalAltnames: number;
    totalOffices: number;
    totalTexts: number;
    totalUsers: number;
    totalOperations: number;
    dailyStats: UserStat[];
    weeklyStats: UserStat[];
    monthlyStats: UserStat[];
    operationTypeStats: Record<string, number>;
}

const nf = new Intl.NumberFormat();

// 統計卡圖示改用 Lucide 線性圖示（現代、與 shadcn 風格一致），並以「柔和淡底 + 彩色線圖」取代
// 舊版高飽和實心色塊；每個色調都帶 dark: 變體，深淺模式皆協調不刺眼。
function InfoBox({ icon: Icon, tone, label, value }: { icon: LucideIcon; tone: string; label: string; value: number }) {
    return (
        <div className="flex items-center gap-3 rounded-lg border border-border bg-card p-3 shadow-sm">
            <span className={cn('flex h-12 w-12 items-center justify-center rounded-lg', tone)}>
                <Icon className="h-6 w-6" strokeWidth={2} aria-hidden />
            </span>
            <div>
                <div className="text-xs text-muted-foreground">{label}</div>
                <div className="text-xl font-semibold">{nf.format(value)}</div>
            </div>
        </div>
    );
}

function StatTable({ title, stats, headBy, headCount, empty, unknown }: { title: string; stats: UserStat[]; headBy: string; headCount: string; empty: string; unknown: string }) {
    return (
        <div className="rounded-lg border border-border bg-card">
            <div className="border-b border-border px-4 py-2 font-medium">{title}</div>
            {stats.length > 0 ? (
                <table className="w-full text-sm">
                    <thead className="bg-muted/50">
                        <tr>
                            <th className="px-3 py-2 text-left font-medium">{headBy}</th>
                            <th className="px-3 py-2 text-right font-medium">{headCount}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {stats.map((s, i) => (
                            <tr key={`${s.user_name ?? ''}-${i}`} className="border-t border-border">
                                <td className="px-3 py-1.5">{s.user_name ?? unknown}</td>
                                <td className="px-3 py-1.5 text-right">{nf.format(s.count)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            ) : (
                <div className="p-3 text-sm text-muted-foreground">{empty}</div>
            )}
        </div>
    );
}

export default function DashboardIndex() {
    const props = usePage<DashboardPageProps>().props;
    const t = useTranslation('common');
    const tNav = useTranslation('nav');

    const opTypeEntries = Object.entries(props.operationTypeStats ?? {});

    return (
        <DashboardLayout title={tNav('dashboard')}>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3">
                <InfoBox icon={User} tone="bg-sky-500/12 text-sky-600 dark:bg-sky-400/15 dark:text-sky-400" label={t('stat_persons')} value={props.totalPersons} />
                <InfoBox icon={IdCard} tone="bg-emerald-500/12 text-emerald-600 dark:bg-emerald-400/15 dark:text-emerald-400" label={t('stat_altnames')} value={props.totalAltnames} />
                <InfoBox icon={Briefcase} tone="bg-amber-500/12 text-amber-600 dark:bg-amber-400/15 dark:text-amber-400" label={t('stat_offices')} value={props.totalOffices} />
                <InfoBox icon={BookText} tone="bg-blue-500/12 text-blue-600 dark:bg-blue-400/15 dark:text-blue-400" label={t('stat_texts')} value={props.totalTexts} />
                <InfoBox icon={Users} tone="bg-violet-500/12 text-violet-600 dark:bg-violet-400/15 dark:text-violet-400" label={t('stat_users')} value={props.totalUsers} />
                <InfoBox icon={History} tone="bg-rose-500/12 text-rose-600 dark:bg-rose-400/15 dark:text-rose-400" label={t('stat_operations')} value={props.totalOperations} />
            </div>

            <div className="mt-4 rounded-lg border border-border bg-card">
                <div className="border-b border-border px-4 py-2 font-medium">{t('op_type_stats_title')}</div>
                <div className="grid grid-cols-2 gap-3 p-4 md:grid-cols-4">
                    {opTypeEntries.length > 0 ? (
                        opTypeEntries.map(([type, count]) => (
                            <div key={type} className="rounded bg-muted/40 p-3">
                                <div className="text-2xl font-semibold">{nf.format(count)}</div>
                                <div className="text-sm text-muted-foreground">{type}</div>
                            </div>
                        ))
                    ) : (
                        <div className="text-sm text-muted-foreground">{t('no_data_yet')}</div>
                    )}
                </div>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                <StatTable title={t('stat_daily_title')} stats={props.dailyStats} headBy={t('submitted_by')} headCount={t('op_count')} empty={t('no_data_yet')} unknown={t('unknown')} />
                <StatTable title={t('stat_weekly_title')} stats={props.weeklyStats} headBy={t('submitted_by')} headCount={t('op_count')} empty={t('no_data_yet')} unknown={t('unknown')} />
                <StatTable title={t('stat_monthly_title')} stats={props.monthlyStats} headBy={t('submitted_by')} headCount={t('op_count')} empty={t('no_data_yet')} unknown={t('unknown')} />
            </div>
        </DashboardLayout>
    );
}
