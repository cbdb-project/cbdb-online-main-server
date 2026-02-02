<?php

namespace App\Http\Controllers;

use App\Services\PostingAutofillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        ]);

        $sourceText = $request->input('source_text');
        $personId = $request->input('person_id');

        $result = $this->autofillService->extractAndMatch($sourceText, $personId);

        if (!$result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result);
    }
}
