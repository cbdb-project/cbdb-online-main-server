<?php

namespace App\Http\Controllers;

use App\Services\PostingAutofillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AiPostingAutofillController extends Controller {
    protected PostingAutofillService $autofillService;

    public function __construct(PostingAutofillService $autofillService) {
        $this->middleware('auth');
        $this->autofillService = $autofillService;
    }

    /**
     * 從古籍文本提取任官信息
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function extract(Request $request) {
        // 權限檢查：只有能直接寫入的用戶才能使用 AI 功能
        if (!Auth::user()->canWriteDirectly()) {
            return response()->json([
                'success' => false,
                'error' => '您沒有使用 AI 功能的權限',
            ], 403);
        }

        $request->validate([
            'source_text' => 'required|string|max:5000',
            'person_id' => 'required|integer',
            'route_name' => 'nullable|string|max:255',
            'route_url' => 'nullable|string|max:500',
        ]);

        $sourceText = $request->input('source_text');
        $personId = $request->input('person_id');

        $startTime = microtime(true);
        $result = $this->autofillService->extractAndMatch($sourceText, $personId);
        $executionTimeMs = (int) round((microtime(true) - $startTime) * 1000);

        // 寫入 AI 填充日誌
        $logId = $this->saveLog($request, $personId, $sourceText, $result, $executionTimeMs);

        if (!$result['success']) {
            $response = $result;
            $response['ai_fill_log_id'] = $logId;

            return response()->json($response, 400);
        }

        $result['ai_fill_log_id'] = $logId;

        return response()->json($result);
    }

    /**
     * 儲存 AI 填充日誌記錄
     */
    private function saveLog(Request $request, int $personId, string $sourceText, array $result, int $executionTimeMs): ?int {
        try {
            $logId = DB::table('ai_fill_logs')->insertGetId([
                'user_id' => Auth::id(),
                'c_personid' => $personId,
                'category' => 'posting',
                'route_name' => $request->input('route_name') ?? '',
                'route_url' => $request->input('route_url') ?? '',
                'source_text' => $sourceText,
                'ai_raw' => isset($result['data']['ai_extracted'])
                    ? json_encode($result['data']['ai_extracted'], JSON_UNESCAPED_UNICODE)
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
            \Log::warning('[AI Fill Log] 日誌寫入失敗: '.$e->getMessage());

            return null;
        }
    }
}
