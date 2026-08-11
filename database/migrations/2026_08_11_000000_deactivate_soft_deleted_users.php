<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 回填既有軟刪除帳號的 is_active。
 *
 * P0-2 讓 ManagementController 的軟刪除順便把 is_active 歸零，但那只約束「未來的刪除」。
 * 在此之前刪掉的列只換了 password／confirmation_token／remember_token，is_active 原封不動，
 * 所以仍有 is_active=1（甚至 is_admin=1）的被刪帳號：
 *
 *  - App\Http\Middleware\Authenticate 的 is_active 複查對它們不會 fire；
 *  - User 的 capability helper（canManageUsers／canReviewProposals／canRestoreOperations）
 *    只看 isActive() + 角色，所以殘存 session 仍有完整權限；
 *  - buildUserListing() 用 `confirmation_token != '-'` 把它們濾掉，管理介面看不到也改不了。
 *
 * 一併撤銷這些帳號殘留的 API token（被刪帳號不該留下任何可用憑證）。
 */
return new class () extends Migration {
    public function up(): void {
        if (!Schema::hasTable('users')) {
            return;
        }

        // 用同一個 WHERE 條件直接下 UPDATE／DELETE，不先 pluck 成 id 清單：
        // 展開成 whereIn 的 bindings 在帳號多時會撞上 SQLite 的變數上限（預設 999）。
        // 兩道語句順序無關——UPDATE 只改 is_active，軟刪除的判斷條件不受影響。
        $softDeletedFilter = function ($q) {
            $q->where('password', '-')
                ->orWhere('confirmation_token', '-')
                ->orWhere('remember_token', '-');
        };

        DB::table('users')->where($softDeletedFilter)->update(['is_active' => 0]);

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\User::class)
                ->whereIn('tokenable_id', function ($query) use ($softDeletedFilter) {
                    $query->select('id')->from('users')->where($softDeletedFilter);
                })
                ->delete();
        }
    }

    public function down(): void {
        // 不可逆：原本哪些被刪帳號的 is_active 是 1 已無從得知，而且回復成 1 等於把
        // 安全問題放回去。故意留空。
    }
};
