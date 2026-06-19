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
    avatar: string | null;
    institution: string | null;
    roles: AuthUserRoles;
    can: AuthUserCan;
}

export interface ShellRoutes {
    home_url: string;
    profile_url: string | null;
    logout_url: string;
    login_url: string;
    register_url: string;
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

/** 導覽節點（對應 App\Support\Navigation 輸出）。 */
export interface NavBadge {
    label: string;
    variant: string;
    show: boolean;
}

export interface NavActive {
    pages: string[];
    patterns: string[];
}

export interface NavNode {
    key: string;
    label: string;
    icon: string;
    href: string | null;
    suffix: string | null;
    badge: NavBadge | null;
    active: NavActive;
    children: NavNode[];
}

export interface SharedProps {
    app: { name: string | null; version: string };
    auth: { user: AuthUser | null };
    locale: string;
    locale_url: string;
    flash: FlashMessage[];
    nav: NavNode[];
    shell: ShellRoutes;
    translations: Record<string, TranslationGroup>;
    page_translations?: Record<string, TranslationGroup>;
    [key: string]: unknown;
}
