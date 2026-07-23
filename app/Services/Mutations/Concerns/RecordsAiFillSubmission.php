<?php

namespace App\Services\Mutations\Concerns;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * v2 direct mutation 成功後，把使用者實際提交的資料回寫 ai_fill_logs（user_submitted + submitted_at），
 * 使 /admin/ai-fill-logs 正確顯示「已提交」。
 *
 * 背景：AI 智能識別代碼（AiCodeLookupPanel → CodeLookupController@suggest）於識別當下建立 ai_fill_logs 一筆；
 * 舊 Blade store/update 以 ai_fill_log_id 回寫 user_submitted。React 遷移後 /app 路徑改走 v2 mutation，
 * 遺漏此步 → 上線後所有經 AI 識別的社會關係（assoc）／社會區分（status）日誌一律誤顯示「未提交」，
 * 與是否人工修改 AI 建議無關（以 log id 連結、不比對欄位值）。任官（offices）已於 PostingCreateHandler 修復，
 * 此 trait 讓子資源 create／update handler 共用同一套回寫與守衛。
 *
 * 用法：handler 覆寫 {@see aiFillCategory()} 回傳自身 category（'assoc' / 'status'）；
 * 抽象基底類於 direct 成功後呼叫 {@see recordAiFillSubmission()}。預設 category=null＝該資源無 AI 識別、不回寫（no-op）。
 *
 * 守衛（對齊 PostingCreateHandler::recordAiFillSubmission）：
 * - log id 由前端經 meta.ai_fill_log_id 傳入；非正整數則略過。
 * - WHERE 以 id + user_id(Auth) + category + c_personid 四重限定：user_id 防覆寫他人日誌；
 *   category 防誤寫他類日誌；c_personid（handler 權威值）確保只回寫「同一人物」的日誌。
 * - Schema::hasTable 守衛，使無 ai_fill_logs 的既有測試不受影響。
 * - 任何例外只記 warning、不影響主流程（主資料已成功寫入）。
 * - 僅 direct 模式呼叫；proposal 於核准時才落庫、提交當下不回寫（另計）。
 */
trait RecordsAiFillSubmission {
    /**
     * 此資源對應的 ai_fill_logs.category；null＝無 AI 識別、不回寫。
     * 子類覆寫回傳 'assoc' / 'status' 以啟用回寫。
     */
    protected function aiFillCategory(): ?string {
        return null;
    }

    /**
     * 回寫 ai_fill_logs：使用者實際提交的資料落 user_submitted、時間落 submitted_at。
     *
     * @param array $meta      mutation meta（含 ai_fill_log_id）
     * @param int   $personId  handler 權威人物 ID（用於 c_personid 守衛）
     * @param array $submitted 使用者實際提交、已正規化的欄位資料（原樣記錄，不比對 AI 建議）
     */
    protected function recordAiFillSubmission(array $meta, int $personId, array $submitted): void {
        $category = $this->aiFillCategory();
        if ($category === null) {
            return;
        }

        $logId = $meta['ai_fill_log_id'] ?? null;
        if (!is_numeric($logId) || (int) $logId <= 0) {
            return;
        }

        if (!Schema::hasTable('ai_fill_logs')) {
            return;
        }

        try {
            DB::table('ai_fill_logs')
                ->where('id', (int) $logId)
                ->where('user_id', Auth::id())
                ->where('category', $category)
                ->where('c_personid', $personId)
                ->update([
                    'user_submitted' => json_encode($submitted, JSON_UNESCAPED_UNICODE),
                    'submitted_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('[AI Fill Log] v2 '.$category.' 提交回寫失敗: '.$e->getMessage());
        }
    }
}
