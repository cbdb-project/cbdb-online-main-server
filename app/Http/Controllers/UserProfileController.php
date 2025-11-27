<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the user profile edit form.
     *
     * @return \Illuminate\Http\Response
     */
    public function edit()
    {
        return view('profile.edit', [
            'user' => Auth::user(),
            'page_title' => '個人資料設定',
            'page_description' => '修改您的個人資料'
        ]);
    }

    /**
     * Update the user's profile information.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request)
    {
        $user = Auth::user();

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
        ];

        // 只有當用戶填寫了新密碼時才驗證密碼
        if ($request->filled('new_password')) {
            $rules['current_password'] = 'required';
            $rules['new_password'] = 'required|string|min:6|confirmed';
        }

        $validatedData = $request->validate($rules);

        // 如果用戶想要修改密碼
        if ($request->filled('new_password')) {
            // 驗證當前密碼
            if (!Hash::check($request->current_password, $user->password)) {
                return back()
                    ->withErrors(['current_password' => '當前密碼不正確'])
                    ->withInput();
            }

            // 更新密碼
            $user->password = Hash::make($request->new_password);
        }

        // 更新基本資料
        $user->name = $validatedData['name'];
        $user->email = $validatedData['email'];
        $user->institution = $validatedData['institution'];

        $user->save();

        return redirect()->route('profile.edit')
            ->with('success', '個人資料已成功更新');
    }
}
