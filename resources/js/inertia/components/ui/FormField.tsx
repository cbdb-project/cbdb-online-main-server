import React from 'react';
import * as LabelPrimitive from '@radix-ui/react-label';
import { cn } from '../../lib/utils';

let fieldIdSeq = 0;

interface FormFieldProps {
    label?: string;
    /** 控制項 id；省略時自動產生，並與 label/錯誤訊息關聯。 */
    htmlFor?: string;
    required?: boolean;
    /** Laravel 422 回傳的該欄位錯誤訊息（字串或字串陣列）。 */
    error?: string | string[] | null;
    hint?: string;
    className?: string;
    children: React.ReactNode;
}

/**
 * 表單欄位包裝：label + 控制項 + 422 錯誤訊息。與 Inertia useForm 的 errors 搭配，
 * 取代 Blade 的 @error/old() 機制。
 *
 * a11y：自動把 aria-invalid 與 aria-describedby 注入到單一子控制項，使紅框樣式
 * （Input/Select 的 aria-[invalid=true]）與螢幕報讀的錯誤關聯自動生效，呼叫端
 * 不需手動傳遞。
 */
export function FormField({ label, htmlFor, required, error, hint, className, children }: FormFieldProps) {
    const messages = Array.isArray(error) ? error : error ? [error] : [];
    const hasError = messages.length > 0;

    // 穩定 id（每個 FormField 實例一組）。
    const autoId = React.useMemo(() => `ff-${++fieldIdSeq}`, []);
    // 控制項實際 id 優先序：子控制項自帶 id > htmlFor > 自動產生；label 對齊此 id。
    const childId =
        React.isValidElement(children) ? (children.props as { id?: string }).id : undefined;
    const controlId = childId ?? htmlFor ?? autoId;
    const describedById = `${controlId}-desc`;

    // 注入 a11y 屬性到單一子控制項（若為合法 React element）。
    const child = React.isValidElement(children)
        ? React.cloneElement(children as React.ReactElement<Record<string, unknown>>, {
              id: controlId,
              'aria-invalid': hasError ? true : undefined,
              'aria-describedby': hasError || hint ? describedById : undefined,
          })
        : children;

    return (
        <div className={cn('space-y-1', className)}>
            {label && (
                <LabelPrimitive.Root htmlFor={controlId} className="text-sm font-medium">
                    {label}
                    {required && <span className="ml-0.5 text-destructive">*</span>}
                </LabelPrimitive.Root>
            )}
            {child}
            <div id={describedById}>
                {hint && !hasError && <p className="text-xs text-muted-foreground">{hint}</p>}
                {messages.map((m, i) => (
                    <p key={i} className="text-xs text-destructive" role="alert">
                        {m}
                    </p>
                ))}
            </div>
        </div>
    );
}
