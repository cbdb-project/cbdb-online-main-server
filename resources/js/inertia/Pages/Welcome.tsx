import React, { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { useTranslation } from '../hooks/useTranslation';
import type { SharedProps } from '../types/page';

interface NameSuggestion {
    c_personid?: number | string;
    c_name_chn?: string;
    c_name?: string;
    c_dynasty_chn?: string;
}

interface WelcomePageProps extends SharedProps {
    is_authenticated: boolean;
    urls: {
        home: string;
        login: string;
        register: string;
        name_api: string;
        person_show: string;
        person_index: string;
    };
}

export default function Welcome() {
    const { is_authenticated, urls } = usePage<WelcomePageProps>().props;
    const t = useTranslation('nav');

    const [term, setTerm] = useState('');
    const [suggestions, setSuggestions] = useState<NameSuggestion[]>([]);
    const abortRef = useRef<AbortController | null>(null);

    const buildUrl = (value: string) => {
        const isNumeric = /^\d+$/.test(value);
        // 數字 → 人物詳情（show base，依 basicinformation.show flag）；其餘 → 搜尋（index base，依 .index flag）。
        return isNumeric
            ? `${urls.person_show}/${encodeURIComponent(value)}`
            : `${urls.person_index}?q=${encodeURIComponent(value)}`;
    };

    const onInput = (value: string) => {
        setTerm(value);
        const trimmed = value.trim();
        if (trimmed.length === 0) {
            setSuggestions([]);
            return;
        }
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        fetch(`${urls.name_api}?q=${encodeURIComponent(trimmed)}&num=8`, { signal: controller.signal })
            .then((res) => {
                if (!res.ok) throw new Error('Network error');
                return res.json();
            })
            .then((data) => {
                setSuggestions(Array.isArray(data?.data) ? data.data : []);
            })
            .catch((err) => {
                if (err.name === 'AbortError') return;
                setSuggestions([]);
            });
    };

    const label = (item: NameSuggestion) => {
        const parts: string[] = [];
        if (item.c_personid != null) parts.push(String(item.c_personid));
        if (item.c_name_chn) parts.push(item.c_name_chn);
        if (item.c_name) parts.push(item.c_name);
        const dynasty = item.c_dynasty_chn ? `（${item.c_dynasty_chn}）` : '';
        return `${parts.join(' - ')}${dynasty}`;
    };

    return (
        <div className="relative flex min-h-screen flex-col items-center justify-center bg-card px-4 text-muted-foreground">
            <div className="absolute right-3 top-4 flex gap-1 text-xs font-semibold uppercase tracking-wider">
                {is_authenticated ? (
                    <a href={urls.home} className="px-3 text-muted-foreground no-underline hover:underline">
                        Home
                    </a>
                ) : (
                    <>
                        <a href={urls.home} className="px-3 text-muted-foreground no-underline hover:underline">
                            Guest
                        </a>
                        <a href={urls.login} className="px-3 text-muted-foreground no-underline hover:underline">
                            Login
                        </a>
                        <a href={urls.register} className="px-3 text-muted-foreground no-underline hover:underline">
                            Register
                        </a>
                    </>
                )}
            </div>

            <h1 className="mb-8 px-3 text-center text-4xl font-thin md:text-6xl">
                {t('welcome_system_title')}
            </h1>

            <div className="relative w-full max-w-[640px] text-left">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        const value = term.trim();
                        if (!value) return;
                        router.visit(buildUrl(value));
                    }}
                >
                    <label htmlFor="person-search" className="mb-1.5 block text-sm text-muted-foreground">
                        {t('welcome_search_label')}
                    </label>
                    <div className="grid grid-cols-[1fr_auto] gap-2">
                        <input
                            type="text"
                            id="person-search"
                            name="q"
                            autoComplete="off"
                            placeholder={t('welcome_search_placeholder')}
                            value={term}
                            onChange={(e) => onInput(e.target.value)}
                            className="w-full rounded-md border border-border px-3.5 py-3 text-base"
                        />
                        <button
                            type="submit"
                            className="cursor-pointer whitespace-nowrap rounded-md border border-border bg-card px-4 py-2.5 text-[15px] hover:bg-muted"
                        >
                            {t('welcome_search_btn')}
                        </button>
                    </div>
                </form>

                {suggestions.length > 0 && (
                    <div className="absolute left-0 right-0 top-full z-10 mt-1.5 max-h-60 overflow-y-auto rounded-md border border-border bg-card shadow-lg">
                        {/* #67：改用真實 <a href>，使 Ctrl/⌘/Shift+點擊與中鍵可原生開新分頁；一般點擊與原 window.location 導航等價。 */}
                        {suggestions.map((item, i) => (
                            <a
                                key={item.c_personid != null ? String(item.c_personid) : `s-${i}`}
                                href={item.c_personid != null ? buildUrl(String(item.c_personid)) : undefined}
                                className="block w-full border-b border-border bg-card px-3 py-2.5 text-left text-sm text-inherit no-underline last:border-b-0 hover:bg-muted focus:bg-muted focus:outline-none"
                            >
                                {label(item)}
                            </a>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}
