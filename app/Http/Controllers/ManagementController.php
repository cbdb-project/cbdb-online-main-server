<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountAccessRevoker;
use App\Services\SecurityAuditLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class ManagementController extends Controller {
    public function __construct() {
        $this->middleware('auth');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request) {
        if (!Auth::user()->isAdmin()) {
            return redirect('/home');
        }

        [$data, $inactiveUsers] = $this->buildUserListing($request);

        return view('manage.index', [
            'data' => $data,
            'inactiveUsers' => $inactiveUsers,
            'page_title' => __('nav.user_management'),
            'page_title_key' => '用戶管理',
            'page_description' => __('nav.user_management_desc'),
        ]);
    }

    /**
     * Inertia + React 版：使用者管理列表（與 Blade index 共用 buildUserListing）。
     */
    public function appIndex(Request $request) {
        if (!Auth::user()->isAdmin()) {
            return redirect('/home');
        }

        [$data, $inactiveUsers] = $this->buildUserListing($request);

        $serialize = fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'institution' => $u->institution,
            'is_active' => $u->isActive(),
            'role_name' => $u->getRoleName(),
        ];

        $editIsNew = migration_flag_is_new('manage') && \Illuminate\Support\Facades\Route::has('app.manage.edit');

        return Inertia::render('Admin/Manage/Index', [
            'data' => [
                'rows' => array_map($serialize, $data->items()),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                    'from' => $data->firstItem(),
                    'to' => $data->lastItem(),
                ],
            ],
            'inactive_users' => $inactiveUsers->map($serialize)->values()->all(),
            'filters' => [
                'search' => (string) $request->get('search', ''),
                'sort_by' => (string) $request->get('sort_by', 'id'),
                'sort_order' => strtolower((string) $request->get('sort_order', 'asc')) === 'desc' ? 'desc' : 'asc',
                'per_page' => (int) $request->get('per_page', 50),
            ],
            'edit_template' => $editIsNew
                ? route('app.manage.edit', ['manage' => '__ID__'], false)
                : route('manage.edit', ['manage' => '__ID__'], false),
            'page_translations' => [
                'admin' => is_array($t = trans('admin')) ? $t : [],
            ],
        ]);
    }

    /**
     * 使用者列表查詢（有效用戶 + 搜尋 + 排序 + 分頁）與近 7 天未激活用戶。
     * Blade 與 Inertia 共用。
     *
     * @return array{0: \Illuminate\Pagination\LengthAwarePaginator, 1: \Illuminate\Support\Collection}
     */
    protected function buildUserListing(Request $request): array {
        // 構建查詢：只顯示有效用戶（未被軟刪除的用戶）
        $query = User::query()
            ->where('confirmation_token', '!=', '-')
            ->where(function ($q) {
                $q->whereNull('remember_token')
                    ->orWhere('remember_token', '!=', '-');
            })
            ->where('password', '!=', '-');

        // 支持搜索
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('institution', 'LIKE', "%{$search}%");
            });
        }

        // 支持排序
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = strtolower((string) $request->get('sort_order', 'asc'));
        if (!in_array($sortOrder, ['asc', 'desc'], true)) {
            $sortOrder = 'asc';
        }
        $allowedSorts = ['id', 'name', 'email', 'institution', 'is_active', 'is_admin'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // 分頁
        $perPage = (int) $request->get('per_page', 50);
        $data = $query->paginate($perPage)->appends($request->except('page'));

        // 最近 7 天內註冊的未激活用戶（ID 倒序，最多 15 個）
        $inactiveUsers = User::query()
            ->where('is_active', User::STATUS_INACTIVE)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->where('confirmation_token', '!=', '-')
            ->where('password', '!=', '-')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        return [$data, $inactiveUsers];
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create() {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request) {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id) {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id) {
        if (!Auth::user()->canManageUsers()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        $user = User::find($id);

        if (!$user) {
            flash('用戶不存在 @ '.Carbon::now(), 'error');

            return redirect()->route('manage.index');
        }

        return view('manage.edit', [
            'user' => $user,
            'page_title' => '編輯用戶',
            'page_description' => __('admin.manage_edit_desc', ['name' => $user->name]),
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    /**
     * Inertia + React 版：使用者編輯表單頁。
     */
    public function appEdit($id) {
        if (!Auth::user()->canManageUsers()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        $user = User::find($id);
        if (!$user) {
            flash('用戶不存在 @ '.Carbon::now(), 'error');

            return redirect()->route('app.manage.index');
        }

        return Inertia::render('Admin/Manage/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'institution' => $user->institution,
                'is_active' => (int) $user->is_active,
                'is_admin' => (int) $user->is_admin,
                'role_name' => $user->getRoleName(),
                'created_at' => optional($user->created_at)->format('Y-m-d H:i:s'),
                'updated_at' => optional($user->updated_at)->format('Y-m-d H:i:s'),
            ],
            'urls' => [
                'update' => route('app.manage.update', ['manage' => $user->id], false),
                'index' => route('app.manage.index', [], false),
            ],
            'page_translations' => [
                'admin' => is_array($t = trans('admin')) ? $t : [],
            ],
        ]);
    }

    public function update(Request $request, $id) {
        if (!Auth::user()->canManageUsers()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        $user = User::find($id);

        if (!$user) {
            flash('用戶不存在 @ '.Carbon::now(), 'error');

            return redirect()->route('manage.index');
        }

        return $this->performUserUpdate($request, $user, 'manage.index');
    }

    /**
     * Inertia + React 版：更新使用者（與 Blade update 共用 performUserUpdate）。
     */
    public function appUpdate(Request $request, $id) {
        if (!Auth::user()->canManageUsers()) {
            flash('該用戶沒有權限，請聯絡管理員 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        $user = User::find($id);
        if (!$user) {
            flash('用戶不存在 @ '.Carbon::now(), 'error');

            return redirect()->route('app.manage.index');
        }

        return $this->performUserUpdate($request, $user, 'app.manage.index');
    }

    /**
     * 更新/軟刪除使用者的共用實作；$indexRoute 控制完成後重導目標。
     */
    protected function performUserUpdate(Request $request, User $user, string $indexRoute) {
        $actor = Auth::user();

        // 检查是否要删除用戶
        if ($request->has('delete_user') && $request->delete_user == 1) {
            $before = ['is_active' => (int) $user->is_active, 'is_admin' => (int) $user->is_admin];
            $email = $user->email;
            $user->email = $email . '-' . Carbon::now();
            $user->password = '-';
            $user->confirmation_token = '-';
            $user->remember_token = '-';
            // 一併停用：軟刪除原本只換掉密碼與 token，is_active 原封不動，於是被刪帳號
            // 當下已存在的 session 仍會通過 App\Http\Middleware\Authenticate 的 is_active
            // 複查而保有完整權限；capability helper（canManageUsers 等）也只看 isActive()+角色。
            $user->is_active = User::STATUS_INACTIVE;
            $user->updated_at = Carbon::now();
            $user->save();

            // 刪除/停用帳號一律撤銷其 API token（sanctum token 不會因帳號失效而自動失效）。
            $this->revokeApiTokens($user);
            $this->auditUserChange($user, $before, ['is_active' => (int) $user->is_active, 'is_admin' => (int) $user->is_admin], $actor, 'DELETE');

            flash('用戶已刪除 @ '.Carbon::now(), 'danger');

            return redirect()->route($indexRoute);
        }

        // 验证输入
        $validated = $request->validate([
            'is_active' => 'required|integer|in:0,1',
            'is_admin' => 'required|integer|in:0,1,2,3',
        ]);

        $currentActive = (int) $user->is_active;
        $currentAdmin = (int) $user->is_admin;
        $newActive = (int) $validated['is_active'];
        $newAdmin = (int) $validated['is_admin'];

        // 角色（is_admin）變更為高敏感操作，於 canManageUsers() 之上再收斂授權：
        //  1) 僅系統管理員可變更角色——專家（is_admin=1）雖能管理帳號啟用，但不得授予/調整
        //     任何帳號的角色，杜絕「專家把自己或他人提為系統管理員」這條提權路徑；
        //  2) 不得變更自己的角色——避免自我提權，或自我降權後失去管理權而鎖死。
        // （不採「不得授予高於自身」的數值比較：角色值非線性——2=眾包並不高於 1=專家；
        //   規則 1 僅允許系統管理員改角色、其本身已是最高級，數值比較無實質意義。）
        if ($newAdmin !== $currentAdmin) {
            if (!$actor->isSuperAdmin()) {
                flash('僅系統管理員可變更使用者角色 @ '.Carbon::now(), 'error');

                return redirect()->back();
            }
            if ((int) $actor->id === (int) $user->id) {
                flash('不可變更自己的角色 @ '.Carbon::now(), 'error');

                return redirect()->back();
            }
        }

        // 更新用戶設定
        $user->is_active = $newActive;
        $user->is_admin = $newAdmin;
        $user->save();

        // 停用或角色異動時撤銷既有 API token（停用不再保留可用憑證；改權亦重置憑證）。
        if ($newActive === User::STATUS_INACTIVE || $newAdmin !== $currentAdmin) {
            $this->revokeApiTokens($user);
        }

        // 應用層審計：記錄操作者與 old→new，與 DB trigger 互為獨立佐證，且能帶到 app 端操作者。
        $this->auditUserChange(
            $user,
            ['is_active' => $currentActive, 'is_admin' => $currentAdmin],
            ['is_active' => $newActive, 'is_admin' => $newAdmin],
            $actor,
            'UPDATE'
        );

        flash('用戶設定已更新 @ '.Carbon::now(), 'success');

        return redirect()->route($indexRoute);
    }

    /**
     * 撤銷指定使用者的所有 personal access token；表不存在時為 no-op（兼容未建該表的測試）。
     *
     * 實作委派給 AccountAccessRevoker，讓「撤銷了哪些 token」本身也留下 audit_log 紀錄
     * （上面的 auditUserChange 記的是 users 欄位 old→new，看不出憑證被銷毀了什麼）。
     */
    private function revokeApiTokens(User $user): void {
        if (Schema::hasTable('personal_access_tokens')) {
            app(AccountAccessRevoker::class)->revokeApiTokens($user, 'management_ui');
        }
    }

    /**
     * 寫入 users 表變更的應用層審計（audit_log 不存在時為 no-op）。
     * 審計寫入失敗絕不可回退已完成的帳號變更，故以 try/catch 包住、僅記 warning——
     * DB trigger 才是權威 tripwire，此處是帶 app 端操作者的補充佐證。
     */
    private function auditUserChange(User $user, array $before, array $after, ?User $actor, string $operation): void {
        // 委派給 SecurityAuditLogger：它統一處理請求脈絡（IP／User-Agent／操作者，且 CLI 下
        // 寫 null 而不是 Laravel 造的假 127.0.0.1）、actor 型別、DELETE 的脈絡放 old_data
        // 的慣例，以及「審計失敗絕不回退已完成的帳號變更」。
        //
        // DB trigger 看得到欄位變了，但看不到是誰從哪裡改的，而那正是入侵調查要問的第一個問題。
        app(SecurityAuditLogger::class)->record(
            table: 'users',
            operation: $operation,
            rowPk: ['id' => (int) $user->id],
            event: $operation === 'DELETE' ? 'user_soft_deleted' : 'user_role_or_status_changed',
            before: $before,
            after: $after
        );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id) {
        //
    }
}
