<?php

namespace App\Http\Controllers;

use App\Services\SecurityAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserProfileController extends Controller {
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(private SecurityAuditLogger $securityAudit) {
        $this->middleware('auth');
    }

    /**
     * Show the user profile edit form.
     *
     * @return \Illuminate\Http\Response
     */
    public function edit() {
        return view('profile.edit', [
            'user' => Auth::user(),
            'page_title' => __('common.profile_settings'),
            'page_description' => __('common.profile_settings_desc'),
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    /**
     * 驗證規則（Blade 與 Inertia 共用）。
     *
     * @return array<string, mixed>
     */
    protected function rules(Request $request, $user): array {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
            'institution' => 'nullable|string|max:255',
            'avatar' => [
                'required',
                'string',
                Rule::in($this->getAvailableAvatars()),
            ],
        ];

        if ($request->filled('new_password')) {
            $rules['current_password'] = 'required';
            $rules['new_password'] = 'required|string|min:6|confirmed';
        }

        return $rules;
    }

    /**
     * 套用更新（含可選密碼變更）。回傳 false 表示當前密碼不正確（由呼叫端 withErrors）。
     */
    protected function applyProfileUpdate(Request $request, array $validatedData): bool {
        $user = Auth::user();

        $passwordChanged = false;
        $emailBefore = $user->email;

        if ($request->filled('new_password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return false;
            }
            $user->password = Hash::make($request->new_password);
            $passwordChanged = true;
        }

        $user->name = $validatedData['name'];
        $user->email = $validatedData['email'];
        // institution 為 nullable，未送出時 validated() 不含此鍵，預設 null。
        $user->institution = $validatedData['institution'] ?? null;
        $user->avatar = $validatedData['avatar'];
        $user->save();

        // 安全敏感變更寫應用層審計（含 IP／UA——DB trigger 拿不到這兩個）：
        //  - 密碼變更是帳號接管的首要指標；
        //  - email 變更可用來劫持密碼重設，等於換走帳號的復原管道。
        // 只記「變更發生了」與 email 的前後值，**不記密碼雜湊**。
        if ($passwordChanged) {
            $this->securityAudit->record(
                table: 'users',
                operation: 'UPDATE',
                rowPk: ['id' => (int) $user->id],
                event: 'password_changed',
                // before 刻意留空（old_data 為 null）：兩邊都寫 password_changed=true 會讓
                // 後台審計檢視器（相等即略過）把整條 diff 吃掉，而且「變更前 password_changed
                // 就是 true」在 diff 視角下讀不通。
                after: ['password_changed' => true]
            );
        }

        if ($emailBefore !== $user->email) {
            $this->securityAudit->record(
                table: 'users',
                operation: 'UPDATE',
                rowPk: ['id' => (int) $user->id],
                event: 'email_changed',
                before: ['email' => $emailBefore],
                after: ['email' => $user->email]
            );
        }

        return true;
    }

    public function update(Request $request) {
        $user = Auth::user();
        $validatedData = $request->validate($this->rules($request, $user));

        if (!$this->applyProfileUpdate($request, $validatedData)) {
            return back()->withErrors(['current_password' => '當前密碼不正確'])->withInput();
        }

        return redirect()->route('profile.edit')->with('success', '個人資料已成功更新');
    }

    /**
     * Inertia + React 版：個人資料表單頁。
     */
    public function appEdit() {
        $user = Auth::user();

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'institution' => $user->institution,
                'avatar' => $user->avatar,
            ],
            'avatars' => $this->getAvailableAvatars(),
            'update_url' => route('app.profile.update', [], false),
            // API token 管理（僅在該功能路由存在時啟用）。
            'api_tokens' => Route::has('api-tokens.index') ? [
                'index' => route('api-tokens.index', [], false),
                'store' => route('api-tokens.store', [], false),
                'destroy_all' => route('api-tokens.destroy-all', [], false),
                'destroy_template' => route('api-tokens.destroy', ['tokenId' => '__ID__'], false),
            ] : null,
        ]);
    }

    /**
     * Inertia + React 版：更新。驗證/儲存邏輯與 Blade 版共用。
     */
    public function appUpdate(Request $request) {
        $user = Auth::user();
        $validatedData = $request->validate($this->rules($request, $user));

        if (!$this->applyProfileUpdate($request, $validatedData)) {
            return back()->withErrors(['current_password' => '當前密碼不正確'])->withInput();
        }

        return redirect()->route('app.profile.edit')->with('success', '個人資料已成功更新');
    }

    /**
     * 獲取所有可用的頭像列表
     *
     * @return array
     */
    private function getAvailableAvatars(): array {
        $avatars = ['avatar0.png']; // CBDB 默認頭像
        for ($i = 1; $i <= 18; $i++) {
            $avatars[] = "avatar{$i}.png";
        }

        return $avatars;
    }
}
