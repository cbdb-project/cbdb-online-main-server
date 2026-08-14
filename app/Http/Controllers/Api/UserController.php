<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller {
    /**
     * 回傳目前 bearer token 對應的帳號資料。
     *
     * **顯式白名單，不是回傳整個模型。** 先前這裡是 `return $request->user()`，序列化範圍
     * 全靠 `User::$hidden` 的黑名單決定，於是 `confirmation_token`（`/api/operations/*`
     * 舊通道用的長期憑證，無到期、無撤銷）會一併交給任何持有 Sanctum token 的呼叫者，
     * 等於讓唯讀 token 換到可直接寫 operations 的憑證。改成白名單後，日後 `users`
     * 新增欄位也不會再默默流出去——要對外就得在這裡顯式加。
     *
     * 刻意不回傳 `settings`（使用者偏好的自由格式 JSON，對呼叫端無用且內容不可預期）。
     *
     * 欄位契約記於 API.md §14.2；變動請同步該處。
     */
    public function show(Request $request): JsonResponse {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'institution' => $user->institution,
            'avatar' => $user->avatar,
            'is_admin' => $user->is_admin,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ]);
    }
}
