import React from 'react';
import { cn } from '../../lib/utils';

export type InputProps = React.InputHTMLAttributes<HTMLInputElement>;

/** 共用文字輸入。錯誤狀態由 aria-invalid 驅動紅框（與 422 欄位錯誤渲染搭配）。 */
const Input = React.forwardRef<HTMLInputElement, InputProps>(({ className, ...props }, ref) => (
    <input
        ref={ref}
        className={cn(
            'flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors',
            'placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            'disabled:cursor-not-allowed disabled:opacity-50',
            'aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-destructive',
            className
        )}
        {...props}
    />
));
Input.displayName = 'Input';

export { Input };
