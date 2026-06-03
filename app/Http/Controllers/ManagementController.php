<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        return view('manage.index', [
            'data' => $data,
            'inactiveUsers' => $inactiveUsers,
            'page_title' => __('nav.user_management'),
            'page_title_key' => '用戶管理',
            'page_description' => __('nav.user_management_desc'),
        ]);
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

        // 检查是否要删除用戶
        if ($request->has('delete_user') && $request->delete_user == 1) {
            $email = $user->email;
            $user->email = $email . '-' . Carbon::now();
            $user->password = '-';
            $user->confirmation_token = '-';
            $user->remember_token = '-';
            $user->updated_at = Carbon::now();
            $user->save();
            flash('用戶已刪除 @ '.Carbon::now(), 'danger');

            return redirect()->route('manage.index');
        }

        // 验证输入
        $validated = $request->validate([
            'is_active' => 'required|integer|in:0,1',
            'is_admin' => 'required|integer|in:0,1,2,3',
        ]);

        // 更新用戶設定
        $user->is_active = $validated['is_active'];
        $user->is_admin = $validated['is_admin'];
        $user->save();

        flash('用戶設定已更新 @ '.Carbon::now(), 'success');

        return redirect()->route('manage.index');
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
