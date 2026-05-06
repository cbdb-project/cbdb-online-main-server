<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AiFillLogController extends Controller {
    /**
     * AI 填充日誌列表（僅 Super Admin）
     */
    public function index(Request $request) {
        if (!Auth::user()->isSuperAdmin()) {
            abort(403, '此功能僅限超級管理員使用');
        }

        $query = DB::table('ai_fill_logs')
            ->leftJoin('users', 'ai_fill_logs.user_id', '=', 'users.id')
            ->select(
                'ai_fill_logs.*',
                'users.name as user_name',
                'users.email as user_email'
            );

        // 關鍵字搜尋
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('ai_fill_logs.source_text', 'like', "%{$search}%")
                    ->orWhere('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            });
        }

        // 用戶篩選
        if ($request->filled('user_id')) {
            $query->where('ai_fill_logs.user_id', $request->input('user_id'));
        }

        // 類別篩選
        if ($request->filled('category')) {
            $query->where('ai_fill_logs.category', $request->input('category'));
        }

        $query->orderBy('ai_fill_logs.created_at', 'desc');

        $logs = $query->paginate(20)->withQueryString();

        // 為每條有 user_submitted 的記錄建構比較數據
        foreach ($logs as $log) {
            $log->comparison_rows = null;
            if ($log->ai_matched && $log->user_submitted) {
                $aiMatched = json_decode($log->ai_matched, true);
                $userSubmitted = json_decode($log->user_submitted, true);
                $logCat = $log->category ?? 'posting';
                if (is_array($aiMatched) && is_array($userSubmitted)) {
                    $log->comparison_rows = ($logCat === 'assoc' || $logCat === 'status')
                        ? $this->buildCodeComparisonRows($aiMatched, $userSubmitted, $logCat)
                        : $this->buildComparisonRows($aiMatched, $userSubmitted);
                }
            }
        }

        // 獲取有日誌記錄的用戶列表
        $users = DB::table('users')
            ->whereIn('id', function ($query) {
                $query->select('user_id')
                    ->from('ai_fill_logs')
                    ->whereNotNull('user_id')
                    ->distinct();
            })
            ->orderBy('name')
            ->get();

        return view('admin.ai_fill_logs.index', [
            'page_title' => 'AI 填充日誌',
            'page_title_key' => 'AI 填充日誌',
            'page_url' => route('admin.ai-fill-logs'),
            'logs' => $logs,
            'users' => $users,
            'filters' => [
                'search' => $request->input('search'),
                'user_id' => $request->input('user_id'),
                'category' => $request->input('category'),
            ],
        ]);
    }

    /**
     * 建構 AI 匹配結果與用戶提交數據的比較列表
     */
    private function buildComparisonRows(array $aiMatched, array $userSubmitted): array {
        $fieldLabels = [
            'c_office_id' => '官名',
            'c_addr' => '地名',
            // 朝代（c_dy）刻意排除：實務上不是 AI 從原文提取，而是依人物或年份自動推得，
            // 與用戶提交值比較容易讓比對結果失真。
            'c_firstyear' => '始年',
            'c_fy_nh_code' => '始年年號',
            'c_fy_nh_year' => '年號年數',
            'c_fy_range' => '始年精確度',
            'c_fy_intercalary' => '始年閏月',
            'c_fy_month' => '始年月',
            'c_fy_day' => '始年日',
            'c_fy_day_gz' => '始年干支日',
            'c_lastyear' => '終年',
            'c_ly_nh_code' => '終年年號',
            'c_ly_nh_year' => '終年年號年數',
            'c_ly_range' => '終年精確度',
            'c_ly_intercalary' => '終年閏月',
            'c_ly_month' => '終年月',
            'c_ly_day' => '終年日',
            'c_ly_day_gz' => '終年干支日',
            'c_appt_code' => '除授類別',
            'c_assume_office_code' => '是否赴任',
            // c_source、c_inst_code、c_sequence 不在 AI 生成範圍內，略過比較
            'c_notes' => '備註',
        ];

        $matched = $aiMatched['matched_fields'] ?? [];
        $suggested = $aiMatched['suggested_fields'] ?? [];
        $allAiFields = array_merge($matched, $suggested);

        // 批次查詢用戶提交代碼欄位的中文名稱
        $codeLabels = $this->resolveCodeLabels($userSubmitted);

        $rows = [];
        foreach ($fieldLabels as $field => $label) {
            // 取得 AI 匹配值
            $aiEntry = $allAiFields[$field] ?? null;
            $aiValue = null;
            $aiText = '';
            $aiType = 'empty';

            if ($aiEntry !== null) {
                $aiValue = $aiEntry['value'] ?? null;
                $aiText = $aiEntry['text'] ?? $aiValue;
                $aiType = isset($matched[$field]) ? 'matched' : 'suggested';
            }

            // 取得用戶提交值
            $userValue = $userSubmitted[$field] ?? null;

            // 格式化顯示值
            $aiDisplay = $this->formatValue($aiText);
            $userDisplay = $this->formatValue($userValue);

            // 若用戶提交的是代碼，附上中文名稱
            if (isset($codeLabels[$field]) && $userDisplay !== '') {
                $userDisplay = $userDisplay.' ('.$codeLabels[$field].')';
            }

            // 若兩者都為空，跳過此欄位
            if ($aiDisplay === '' && $userDisplay === '') {
                continue;
            }

            // 判斷是否匹配（用原始值比較）
            $aiCompare = $this->normalizeForCompare($aiValue);
            $userCompare = $this->normalizeForCompare($userValue);
            $matches = ($aiCompare === $userCompare);

            $rows[] = [
                'field' => $label,
                'field_key' => $field,
                'ai_value' => $aiDisplay,
                'ai_type' => $aiType,
                'user_value' => $userDisplay,
                'matches' => $matches,
            ];
        }

        return $rows;
    }

    /**
     * 批次查詢用戶提交代碼欄位對應的中文名稱
     */
    private function resolveCodeLabels(array $userSubmitted): array {
        $labels = [];

        // 代碼欄位 → [table, pk_column, text_column]
        $codeLookups = [
            'c_office_id' => ['OFFICE_CODES', 'c_office_id', 'c_office_chn'],
            'c_fy_nh_code' => ['NIAN_HAO', 'c_nianhao_id', 'c_nianhao_chn'],
            'c_ly_nh_code' => ['NIAN_HAO', 'c_nianhao_id', 'c_nianhao_chn'],
            'c_appt_code' => ['APPOINTMENT_CODES', 'c_appt_code', 'c_appt_desc_chn'],
            'c_assume_office_code' => ['ASSUME_OFFICE_CODES', 'c_assume_office_code', 'c_assume_office_desc_chn'],
        ];

        foreach ($codeLookups as $field => [$table, $pk, $textCol]) {
            $value = $userSubmitted[$field] ?? null;
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                continue;
            }
            $text = DB::table($table)->where($pk, $value)->value($textCol);
            if ($text !== null && $text !== '') {
                $labels[$field] = $text;
            }
        }

        // 地址欄位特殊處理（可能是陣列）
        $addrValue = $userSubmitted['c_addr'] ?? null;
        if ($addrValue !== null && $addrValue !== '' && $addrValue !== 0 && $addrValue !== '0') {
            $addrIds = is_array($addrValue) ? $addrValue : [$addrValue];
            $addrIds = array_filter($addrIds, fn ($v) => $v !== null && $v !== '' && $v !== 0 && $v !== '0');
            if (!empty($addrIds)) {
                $addrNames = DB::table('ADDR_CODES')
                    ->whereIn('c_addr_id', $addrIds)
                    ->pluck('c_name_chn', 'c_addr_id');
                $names = [];
                foreach ($addrIds as $id) {
                    $names[] = $addrNames[$id] ?? (string) $id;
                }
                $labels['c_addr'] = implode(', ', $names);
            }
        }

        return $labels;
    }

    /**
     * 格式化值為顯示字串
     */
    private function formatValue(mixed $value): string {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => (string) $v, $value));
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * 正規化值以便比較
     */
    private function normalizeForCompare(mixed $value): string {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_array($value)) {
            $arr = array_map(fn ($v) => trim((string) $v), $value);
            $arr = array_filter($arr, fn ($v) => $v !== '' && $v !== '0');
            sort($arr);

            return implode(',', $arr);
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        $str = trim((string) $value);

        // 統一 "false"/"true" 字串
        if ($str === 'false') {
            $str = '0';
        }
        if ($str === 'true') {
            $str = '1';
        }

        // 0 視為空值（表單預設值，等同於「未設定」）
        if ($str === '0') {
            return '';
        }

        return $str;
    }

    /**
     * 建構代碼查詢（assoc/status）的 AI 匹配結果與用戶提交數據的比較列表
     *
     * 每個 AI 候選代碼獨立一行，用戶選中的標綠。
     */
    private function buildCodeComparisonRows(array $aiMatched, array $userSubmitted, string $category): array {
        if ($category === 'assoc') {
            $codeField = 'c_assoc_code';
            $codeTable = 'ASSOC_CODES';
            $codePk = 'c_assoc_code';
            $codeDescChn = 'c_assoc_desc_chn';
            $codeDescEn = 'c_assoc_desc';
        } else {
            $codeField = 'c_status_code';
            $codeTable = 'STATUS_CODES';
            $codePk = 'c_status_code';
            $codeDescChn = 'c_status_desc_chn';
            $codeDescEn = 'c_status_desc';
        }

        $userCodeValue = (string) ($userSubmitted[$codeField] ?? '');

        $rows = [];

        // 每個 AI 候選代碼獨立一行
        $matchedCodes = $aiMatched['matched_codes'] ?? [];
        foreach ($matchedCodes as $i => $code) {
            $codeId = (string) $code['code_id'];
            $isSelected = ($codeId === $userCodeValue);
            $aiText = $codeId . ' ' . ($code['desc_chn'] ?? '') . ' (' . ($code['desc_en'] ?? '') . ')';
            $reason = $code['reason'] ?? '';
            if ($reason !== '') {
                $aiText .= ' — ' . $reason;
            }

            $rows[] = [
                'field' => $i === 0 ? 'AI 候選代碼' : '',
                'field_key' => $codeField . '_' . $i,
                'ai_value' => $aiText,
                'ai_type' => $code['relevance'] === '高' ? 'matched' : 'suggested',
                'user_value' => $isSelected ? '✔ 已選用' : '',
                'matches' => $isSelected,
            ];
        }

        // 若用戶選了一個不在 AI 候選中的代碼
        $aiCodeIds = array_map(fn ($c) => (string) $c['code_id'], $matchedCodes);
        if ($userCodeValue !== '' && $userCodeValue !== '0' && !in_array($userCodeValue, $aiCodeIds, true)) {
            $codeText = DB::table($codeTable)->where($codePk, $userCodeValue)->value($codeDescChn);
            $rows[] = [
                'field' => '',
                'field_key' => $codeField . '_user',
                'ai_value' => '',
                'ai_type' => 'empty',
                'user_value' => $userCodeValue . ($codeText ? ' (' . $codeText . ')' : '') . ' — 手動選擇（非 AI 候選）',
                'matches' => false,
            ];
        }

        return $rows;
    }
}
