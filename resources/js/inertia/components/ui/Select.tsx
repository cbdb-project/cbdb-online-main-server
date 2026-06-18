import React from 'react';
import { cn } from '../../lib/utils';

export type SelectProps = React.SelectHTMLAttributes<HTMLSelectElement>;

/**
 * 共用下拉（原生 <select> 包裝）。Phase 4 的 Select2 替代（自動完成）將另建專用
 * Combobox 元件；此處供一般選單/篩選使用。
 */
const Select = React.forwardRef<HTMLSelectElement, SelectProps>(({ className, children, ...props }, ref) => (
    <select
        ref={ref}
        className={cn(
            'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            'disabled:cursor-not-allowed disabled:opacity-50',
            'aria-[invalid=true]:border-destructive',
            className
        )}
        {...props}
    >
        {children}
    </select>
));
Select.displayName = 'Select';

export { Select };
