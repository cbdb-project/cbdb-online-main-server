import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * 合併 Tailwind class 名稱：clsx 處理條件式，twMerge 解決衝突（後者勝出）。
 * shadcn/ui 元件的標準工具，F3 之後的基元元件都會用到。
 */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
