<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 安全敏感操作的應用層審計。
 *
 * 為什麼需要應用層而不只靠 DB trigger：trigger 看得到「哪一列的哪個欄位變了」，但看不到
 * **是誰、從哪個 IP、用什麼客戶端** 做的——那些只存在於 HTTP 請求裡。入侵調查時「這個
 * 密碼變更來自哪個 IP」正是關鍵問題，而 audit_log 原本只審計業務表（BIOG_MAIN 等），
 * users 與 personal_access_tokens 的變更完全沒有紀錄。
 *
 * 寫入慣例：
 *  - `operation` 一律維持 INSERT／UPDATE／DELETE。不自創 'PASSWORD_CHANGE' 這類值——
 *    audit_log.operation 是 varchar(16) 且後台有 operation 篩選，自創值會破壞既有語義。
 *    具體事件名稱放在 payload 的 `__security.event`。
 *  - 請求脈絡放在 INSERT/UPDATE 的 new_data、DELETE 的 old_data。
 *  - **絕不記錄密碼雜湊、token 明文或 token 雜湊**：只記「發生了變更」。洩漏的審計日誌
 *    不該變成第二個憑證來源。
 *
 * 失敗處理：審計寫入失敗絕不可讓業務操作失敗（與 ManagementController::auditUserChange
 * 同一取捨）。使用者改完密碼卻看到 500，只會讓他以為沒改成功而重試。
 */
class SecurityAuditLogger {
    public const CHANNEL_HTTP = 'http';
    public const CHANNEL_CLI = 'cli';

    public function __construct(private AuditLogService $auditLog) {
    }

    /**
     * @param  'INSERT'|'UPDATE'|'DELETE'  $operation
     * @param  array<string, mixed>  $rowPk
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        string $table,
        string $operation,
        array $rowPk,
        string $event,
        array $before = [],
        array $after = [],
        ?string $channel = null
    ): void {
        try {
            // hasTable 也放在 try 內：它會下 information_schema／sqlite_master 查詢，
            // 連線抖動或權限問題造成的拋錯若冒出去，就違反了「審計絕不讓業務操作失敗」。
            if (!Schema::hasTable('audit_log')) {
                return;
            }

            $context = ['__security' => $this->requestContext($event, $channel)];

            $isDelete = strtoupper($operation) === 'DELETE';
            $oldData = $isDelete ? array_merge($before, $context) : ($before === [] ? null : $before);
            $newData = $isDelete ? ($after === [] ? null : $after) : array_merge($after, $context);

            $this->auditLog->write(
                table: $table,
                operation: strtoupper($operation),
                rowPk: $rowPk,
                oldData: $oldData,
                newData: $newData,
                // 未登入（CLI／系統作業）時是 system，不是「不明的 user」——
                // 硬寫 'user' 會讓 AuditLogService 落成 actor_type=user / actor_id=system 的自相矛盾列。
                actorType: Auth::check() ? 'user' : 'system',
                actorId: Auth::check() ? (string) Auth::id() : null
            );
        } catch (\Throwable $e) {
            // 只記 exception 類別與截短訊息：QueryException 的 getMessage() 含完整 SQL 與繫結值，
            // 會把刻意只放進 DB 的稽核 payload（例如變更前後的 email）外流到權限通常更鬆的 laravel.log。
            Log::warning('安全審計寫入失敗（業務操作已完成）', [
                'table' => $table,
                'event' => $event,
                'exception' => get_class($e),
                'message' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContext(string $event, ?string $channel): array {
        $channel = $channel ?? $this->detectChannel();
        $isConsole = $channel === self::CHANNEL_CLI;
        $request = request();

        return [
            'event' => $event,
            // 明確標出來源通道，讓「沒有 IP」與「IP 查不到」可以區分。
            'channel' => $channel,
            'actor_id' => Auth::check() ? (int) Auth::id() : null,
            'actor_name' => Auth::check() ? Auth::user()->name : null,
            // CLI 情境刻意寫 null：Laravel 的 SetRequestForConsole 會用 Request::create() 造一個
            // 假請求，`ip()` 回 '127.0.0.1'、`userAgent()` 回 'Symfony'（都是 Symfony 的預設值，
            // 因為 CLI 的 $_SERVER 沒有真的 REMOTE_ADDR／HTTP_USER_AGENT）。把那組值寫進審計，
            // 事故調查會讀成「有人從本機發 HTTP 請求」而被帶偏——誤導的證據比沒有證據更糟。
            'ip' => $isConsole ? null : $request->ip(),
            // 截斷：User-Agent 是使用者可控字串，沒有理由讓它把 audit 列撐大。
            'user_agent' => $isConsole ? null : mb_substr((string) $request->userAgent(), 0, 512),
        ];
    }

    /**
     * 自動判定來源通道。
     *
     * 刻意排除「測試中」：PHPUnit 本身跑在 CLI，所以 runningInConsole() 在測試裡永遠是 true，
     * 會把測試模擬的 HTTP 請求也標成 cli，於是「HTTP 請求要記下客戶端 IP」這條就無法被測試
     * 覆蓋（而那正是本功能的重點）。因此測試中預設視為 http；真正的 artisan 情境由呼叫端
     * 顯式傳 channel='cli'（見 ManageUser 與 AccountAccessRevoker），不依賴會騙人的訊號。
     */
    private function detectChannel(): string {
        $isRealConsole = app()->runningInConsole() && !app()->runningUnitTests();

        return $isRealConsole ? self::CHANNEL_CLI : self::CHANNEL_HTTP;
    }
}
