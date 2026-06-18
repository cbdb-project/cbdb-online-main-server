import React from 'react';
import * as Dialog from '@radix-ui/react-dialog';
import { cn } from '../../lib/utils';

interface ModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title?: React.ReactNode;
    description?: React.ReactNode;
    children?: React.ReactNode;
    footer?: React.ReactNode;
    className?: string;
}

/**
 * 共用對話框（Radix Dialog：focus trap、Esc 關閉、a11y 內建）。
 * 取代 AdminLTE/Bootstrap 的 jQuery modal。
 */
export function Modal({ open, onOpenChange, title, description, children, footer, className }: ModalProps) {
    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Portal>
                <Dialog.Overlay className="fixed inset-0 z-50 bg-black/50 data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0" />
                <Dialog.Content
                    className={cn(
                        'fixed left-1/2 top-1/2 z-50 w-[92vw] max-w-lg -translate-x-1/2 -translate-y-1/2',
                        'rounded-lg border border-border bg-card p-6 text-card-foreground shadow-lg',
                        'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:fade-in-0 data-[state=closed]:fade-out-0',
                        className
                    )}
                >
                    {/* Radix 要求 Dialog 必有可及標題；無 title 時以 sr-only 後備，
                        確保對話框永遠有 accessible name。 */}
                    {title ? (
                        <Dialog.Title className="text-lg font-semibold">{title}</Dialog.Title>
                    ) : (
                        <Dialog.Title className="sr-only">Dialog</Dialog.Title>
                    )}
                    {description && (
                        <Dialog.Description className="mt-1 text-sm text-muted-foreground">
                            {description}
                        </Dialog.Description>
                    )}
                    {children && <div className="mt-4">{children}</div>}
                    {footer && <div className="mt-6 flex justify-end gap-2">{footer}</div>}
                    <Dialog.Close
                        className="absolute right-4 top-4 rounded p-1 text-muted-foreground hover:bg-muted"
                        aria-label="Close"
                    >
                        <i className="fas fa-times" aria-hidden />
                    </Dialog.Close>
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
