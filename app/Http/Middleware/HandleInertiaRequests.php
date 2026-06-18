<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware {
    /**
     * Inertia 使用的根模板
     */
    protected $rootView = 'inertia';

    /**
     * 每個 Inertia 回應共用的 props
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'app' => [
                'version' => get_app_version(),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    // 角色旗標（對齊 User::is* 方法）；前端側邊欄/頁面閘門用。
                    // ⚠️ 僅供 UX，後端每條 mutation 路由仍須獨立授權（AGENTS.md §5）。
                    'roles' => [
                        'is_active' => $user->isActive(),
                        'is_admin' => $user->isAdmin(),
                        'is_expert' => $user->isExpert(),
                        'is_super_admin' => $user->isSuperAdmin(),
                        'is_crowdsourcing' => $user->isCrowdsourcingUser(),
                        'is_regular' => $user->isRegularUser(),
                    ],
                    // 能力旗標（對齊 User::can* 方法）；側邊欄連結顯示與否。
                    'can' => [
                        'manage_users' => $user->canManageUsers(),
                        'restore_operations' => $user->canRestoreOperations(),
                        'review_proposals' => $user->canReviewProposals(),
                        'view_audit_logs' => $user->canViewAuditLogs(),
                        'write_directly' => $user->canWriteDirectly(),
                        'run_batch_import' => $user->canRunBatchImport(),
                    ],
                ] : null,
            ],
            'locale' => app()->getLocale(),
            'locale_url' => route('locale.switch', [], false),
            // flash 訊息橋接：把 laracasts/flash 的 session 訊息轉成陣列，
            // 由 React AppShell 統一渲染 toast/alert（取代 Blade flash::message partial）。
            'flash' => $this->flashMessages(),
            // ⚠️ 頁面特定翻譯群組（views、codes、operations、admin）
            //   請由控制器以 'page_translations' key 傳入，不可複用此 'translations' key，
            //   否則 inertia-laravel 的淺合併會覆蓋此處的 shared 翻譯。
            'translations' => [
                'common' => is_array($t = trans('common')) ? $t : [],
                'nav' => is_array($t = trans('nav')) ? $t : [],
                'person' => is_array($t = trans('person')) ? $t : [],
                'query' => is_array($t = trans('query')) ? $t : [],
                // 殼所需翻譯群組常駐（驗證錯誤訊息、共用按鈕等）
                'auth' => is_array($t = trans('auth')) ? $t : [],
                'validation' => is_array($t = trans('validation')) ? $t : [],
            ],
        ]);
    }

    /**
     * 將 laracasts/flash 的 session 訊息（session key `flash_notification`）
     * 正規化成前端可消費的陣列。flash 訊息屬一次性 session flash data，
     * 在本次請求被讀取後即隨 Laravel flash 生命週期清除，不需手動 forget。
     *
     * @return array<int, array<string, mixed>>
     */
    protected function flashMessages(): array {
        $messages = session('flash_notification', collect());

        if (!$messages instanceof \Illuminate\Support\Collection) {
            $messages = collect($messages);
        }

        return $messages->map(function ($message) {
            // Message 物件或已是陣列皆可能出現，統一取欄位。
            $get = fn ($key, $default = null) => is_array($message)
                ? ($message[$key] ?? $default)
                : ($message->{$key} ?? $default);

            return [
                'level' => $get('level', 'info'),
                'message' => $get('message', ''),
                'title' => $get('title'),
                'important' => (bool) $get('important', false),
                'overlay' => (bool) $get('overlay', false),
            ];
        })->values()->all();
    }
}
