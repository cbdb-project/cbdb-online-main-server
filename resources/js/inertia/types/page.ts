/**
 * Inertia 共用 props 型別（對應 HandleInertiaRequests::share()）。
 * 所有頁面元件 usePage<SharedProps & 自身 props>() 取用。
 */

export interface AuthUserRoles {
    is_active: boolean;
    is_admin: boolean;
    is_expert: boolean;
    is_super_admin: boolean;
    is_crowdsourcing: boolean;
    is_regular: boolean;
}

export interface AuthUserCan {
    manage_users: boolean;
    restore_operations: boolean;
    review_proposals: boolean;
    view_audit_logs: boolean;
    write_directly: boolean;
    run_batch_import: boolean;
}

export interface AuthUser {
    id: number;
    name: string;
    roles: AuthUserRoles;
    can: AuthUserCan;
}

export interface FlashMessage {
    level: 'success' | 'info' | 'warning' | 'danger' | 'error' | string;
    message: string;
    title: string | null;
    important: boolean;
    overlay: boolean;
}

// 翻譯群組可含巢狀陣列（如 Laravel validation.php 的 custom/attributes），
// 故以遞迴型別表示，而非單層 Record<string, string>。
export type TranslationValue = string | { [key: string]: TranslationValue };
export type TranslationGroup = Record<string, TranslationValue>;

export interface SharedProps {
    app: { version: string };
    auth: { user: AuthUser | null };
    locale: string;
    locale_url: string;
    flash: FlashMessage[];
    translations: Record<string, TranslationGroup>;
    page_translations?: Record<string, TranslationGroup>;
    [key: string]: unknown;
}
