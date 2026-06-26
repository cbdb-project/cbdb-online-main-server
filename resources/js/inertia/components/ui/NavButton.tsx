import React from 'react';
import { router } from '@inertiajs/react';
import { type VariantProps } from 'class-variance-authority';
import { cn } from '../../lib/utils';
import { buttonVariants } from './Button';

export interface NavButtonProps
    extends Omit<React.AnchorHTMLAttributes<HTMLAnchorElement>, 'href'>,
        VariantProps<typeof buttonVariants> {
    /** 目標頁面 URL。為 null/undefined 時退化為停用態（外觀不變，不可點）。 */
    href?: string | null;
}

/**
 * 導航用「按鈕」：外觀與 {@link Button} 完全一致（共用 buttonVariants），但渲染為真實 `<a href>`，
 * 因此 **Ctrl/⌘/Shift+點擊與中鍵可原生開新分頁**（一般 <button> 做不到，中鍵不觸發 click）。
 *
 * - 一般左鍵點擊：preventDefault 後走傳入的 onClick（通常為 Inertia `router.visit` 的 SPA 導航），不整頁重載。
 * - 修飾鍵（⌘/Ctrl/Shift/Alt）或非左鍵（中鍵）：不攔截，交給瀏覽器原生開新分頁/視窗。
 * - 未提供 onClick 時，左鍵點擊退回 `router.visit(href)`。
 */
const NavButton = React.forwardRef<HTMLAnchorElement, NavButtonProps>(
    ({ className, variant, size, href, onClick, ...props }, ref) => {
        const disabled = href === null || href === undefined || href === '';

        const handleClick = (event: React.MouseEvent<HTMLAnchorElement>) => {
            // 修飾鍵 / 非左鍵 → 交給瀏覽器（開新分頁）。中鍵不會觸發 onClick，故由 <a href> 原生處理。
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
                return;
            }
            if (disabled) {
                return;
            }
            event.preventDefault();
            if (onClick) {
                onClick(event);
                return;
            }
            if (href) {
                router.visit(href);
            }
        };

        return (
            <a
                ref={ref}
                href={disabled ? undefined : href}
                role="button"
                aria-disabled={disabled || undefined}
                className={cn(buttonVariants({ variant, size }), disabled && 'pointer-events-none opacity-50', className)}
                onClick={handleClick}
                {...props}
            />
        );
    }
);
NavButton.displayName = 'NavButton';

export { NavButton };
