<?php

namespace App\Http\Controllers;

use App\User;
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
    public function index() {
        if (!Auth::user()->isAdmin()) {
            return redirect('/home');
        }
        $data = User::all()->where('confirmation_token', '!=', '-')->where('remember_token', '!=', '-')->where('password', '!=', '-');

        return view('manage.index', ['data' => $data, 'page_title' => 'Management', 'page_description' => '管理用戶']);
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
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        $user = User::find($id);

        if (!$user) {
            flash('用户不存在 @ '.Carbon::now(), 'error');

            return redirect()->route('manage.index');
        }

        return view('manage.edit', [
            'user' => $user,
            'page_title' => '编辑用户',
            'page_description' => '编辑用户 ' . $user->name . ' 的设置',
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
            flash('该用户没有权限，请联系管理员 @ '.Carbon::now(), 'error');

            return redirect()->back();
        }

        $user = User::find($id);

        if (!$user) {
            flash('用户不存在 @ '.Carbon::now(), 'error');

            return redirect()->route('manage.index');
        }

        // 检查是否要删除用户
        if ($request->has('delete_user') && $request->delete_user == 1) {
            $email = $user->email;
            $user->email = $email . '-' . Carbon::now();
            $user->password = '-';
            $user->confirmation_token = '-';
            $user->remember_token = '-';
            $user->updated_at = Carbon::now();
            $user->save();
            flash('用户已删除 @ '.Carbon::now(), 'danger');

            return redirect()->route('manage.index');
        }

        // 验证输入
        $validated = $request->validate([
            'is_active' => 'required|integer|in:0,1',
            'is_admin' => 'required|integer|in:0,1,2,3',
        ]);

        // 更新用户设置
        $user->is_active = $validated['is_active'];
        $user->is_admin = $validated['is_admin'];
        $user->save();

        flash('用户设置已更新 @ '.Carbon::now(), 'success');

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
