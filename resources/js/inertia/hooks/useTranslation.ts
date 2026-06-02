import { useMemo } from 'react';
import { usePage } from '@inertiajs/react';

type TranslationGroup = Record<string, string>;
type Translations = Record<string, TranslationGroup>;

interface PageProps {
    translations?: Translations;
    page_translations?: Translations;
    [key: string]: unknown;
}

/**
 * 取得指定 group 的翻譯函式。
 *
 * - shared translations（HandleInertiaRequests::share()）：common / nav / person / query
 * - page_translations（各控制器傳入）：views / codes / operations / admin
 *
 * 兩者使用不同 prop key，避免 inertia-laravel 淺合併時 shared props 被覆蓋。
 */
export function useTranslation(group: string) {
    const { translations, page_translations } = usePage<PageProps>().props;

    // 優先查 page_translations（頁面特定），再查 shared translations
    const groupDict = page_translations?.[group] ?? translations?.[group];

    return useMemo(() => {
        return (key: string, replace?: Record<string, string>): string => {
            let value = groupDict?.[key] ?? key;
            if (replace) {
                Object.entries(replace).forEach(([k, v]) => {
                    value = value.replaceAll(`:${k}`, v);
                });
            }
            return value;
        };
    }, [groupDict, group]);
}
