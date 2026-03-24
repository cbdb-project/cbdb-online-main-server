<?php

namespace App\Http\Controllers;

use App\Services\CodeLookupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CodeLookupController extends Controller {
    public function __construct() {
        $this->middleware('auth');
    }

    /**
     * AI 語義識別代碼 API
     */
    public function suggest(Request $request, CodeLookupService $service) {
        if (!Auth::user()->isActive()) {
            return response()->json([
                'success' => false,
                'error' => '您的帳號尚未啟用',
            ], 403);
        }

        $request->validate([
            'query' => 'required|string|max:500',
            'table' => 'required|in:ASSOC_CODES,STATUS_CODES',
            'person_id' => 'nullable|integer',
            'route_name' => 'nullable|string|max:255',
            'route_url' => 'nullable|string|max:500',
        ]);

        $query = trim($request->input('query'));
        $table = $request->input('table');
        $personId = $request->input('person_id', 0);
        $category = $table === 'ASSOC_CODES' ? 'assoc' : 'status';

        $startTime = microtime(true);
        $result = $service->lookup($query, $table);
        $executionTimeMs = (int) round((microtime(true) - $startTime) * 1000);

        // 寫入 AI 填充日誌
        $logId = $this->saveLog($request, $personId, $query, $result, $executionTimeMs, $category);

        $result['ai_fill_log_id'] = $logId;

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * 儲存 AI 填充日誌記錄
     */
    private function saveLog(Request $request, int $personId, string $sourceText, array $result, int $executionTimeMs, string $category): ?int {
        try {
            $logId = DB::table('ai_fill_logs')->insertGetId([
                'user_id' => Auth::id(),
                'c_personid' => $personId,
                'category' => $category,
                'route_name' => $request->input('route_name') ?? '',
                'route_url' => $request->input('route_url') ?? '',
                'source_text' => $sourceText,
                'ai_raw' => isset($result['data'])
                    ? json_encode($result['data'], JSON_UNESCAPED_UNICODE)
                    : null,
                'ai_matched' => $result['success'] && isset($result['data'])
                    ? json_encode($result['data'], JSON_UNESCAPED_UNICODE)
                    : null,
                'success' => $result['success'],
                'error_message' => $result['error'] ?? null,
                'execution_time_ms' => $executionTimeMs,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $logId;
        } catch (\Exception $e) {
            \Log::warning('[AI Fill Log] 日誌寫入失敗: ' . $e->getMessage());

            return null;
        }
    }
}
