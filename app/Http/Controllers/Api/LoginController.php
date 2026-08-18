<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/user/login`（OAuth 時代的遺留）。
 *
 * 這條端點**從來不可能成功**：驗證通過後會轉發到早已不存在的 `oauth/token` 路由，所以最終回 404、
 * 拿不到任何憑證（`API.md` §14.2 早已如此記載）。而失敗路徑更糟——它呼叫 `sendFailedLoginResponse()`
 * → `$this->failed()`，而 `failed()` 在任何地方都不存在，於是落到 `Controller::__call` 拋
 * `BadMethodCallException`＝**HTTP 500**。
 *
 * 也就是說它在下架前是「帳密正確 → 留下一個已登入的 session cookie 再回 404；帳密錯誤 → 500」的
 * 死碼，同時還是一條**沒有節流的密碼驗證端點**（只有 api 群組共用的 600／分鐘，每次一發 bcrypt）。
 *
 * 因此改為明確回 **410 Gone**（#1264）：
 *  - 不再驗證任何密碼，暴力破解與使用者列舉的表面直接消失，也不會再留下 session 副作用；
 *  - 比「修好失敗路徑回 401」誠實——那只會讓一條永遠無法成功的端點看起來像還能用；
 *  - 保留路由而不是刪掉，是為了讓既有呼叫端拿到「已下架、請改用 Bearer token」而不是 404／500。
 *
 * 要程式化存取請改用 Sanctum 的 personal access token（`/profile` 頁面簽發，見 `API.md` 第十四章）。
 *
 * 刻意**不**繼承 `ApiController`：它的建構子對所有 Api controller 掛 `guest`，於是帶著 session 的
 * 使用者會被 `RedirectIfAuthenticated` 導開、拿到 302 而不是 410（review 實測）。這條端點已經不做
 * 任何認證，410 對訪客與已登入者都一樣正確，所以連 `guest` 一起去掉（`routes/api.php` 那條也拿掉）。
 */
class LoginController extends Controller {
    public function login(Request $request) {
        return response()->json([
            'message' => 'This endpoint has been retired. Use a personal access token (Bearer) instead; create one on the /profile page.',
        ], 410);
    }
}
