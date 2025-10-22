<?php

namespace App\Http\Controllers;

use App\BiogMain;
use App\Dynasty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class MergePreviewController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request)
    {
        if (Auth::user()->is_admin != 1) {
            flash('該用戶沒有權限，請聯絡管理員。', 'error');
            return redirect('/home');
        }

        $preview = null;
        if ($request->isMethod('post')) {
            $autoArrange = $request->has('auto_arrange');
        } else {
            $autoParam = $request->input('merge_to_min', null);
            if (is_null($autoParam)) {
                $autoArrange = true;
            } else {
                if (is_string($autoParam)) {
                    $autoArrange = !in_array(strtolower($autoParam), ['0', 'false', 'no'], true);
                } else {
                    $autoArrange = (bool)$autoParam;
                }
            }
        }
        $primaryRaw = $request->filled('primary_id') ? $request->input('primary_id') : $request->input('from', '');
        $secondaryRaw = $request->filled('secondary_id') ? $request->input('secondary_id') : $request->input('to', '');
        $reasonRaw = $request->filled('merge_reason') ? $request->input('merge_reason') : $request->input('reason', '');

        $primaryInput = trim((string)$primaryRaw);
        $secondaryInput = trim((string)$secondaryRaw);
        $mergeReason = trim((string)$reasonRaw);

        $shouldPreview = ($primaryInput !== '' && $secondaryInput !== '') &&
            (
                $request->isMethod('post')
                || $request->query->has('primary_id')
                || $request->query->has('secondary_id')
                || $request->query->has('from')
                || $request->query->has('to')
            );

        if ($shouldPreview) {
            $originalPrimary = $primaryInput;
            $originalSecondary = $secondaryInput;

            $primary = $primaryInput;
            $secondary = $secondaryInput;
            $minTargetId = $autoArrange ? min((int)$primaryInput, (int)$secondaryInput) : null;

        $primarySummary = $this->buildPersonSummary($primary, $mergeReason);
        $secondarySummary = $this->buildPersonSummary($secondary, $mergeReason);

            $mergedResult = $this->calculateMergedPerson($primarySummary, $secondarySummary, $mergeReason);
            $secondaryDetails = $this->loadSecondaryDetails($secondarySummary);
            $primaryDetails = $this->loadSecondaryDetails($primarySummary);
            $primaryCounts = $this->buildTableSummaries($primarySummary);
            $secondaryCounts = $this->buildTableSummaries($secondarySummary);

            $mergeBlocked = $this->shouldBlockMerge($primarySummary, $secondarySummary);

            $preview = [
                'primary_id' => $primary,
                'secondary_id' => $secondary,
                'auto_arrange' => $autoArrange,
                'primary_person' => $primarySummary,
                'secondary_person' => $secondarySummary,
                'merged_person' => $mergedResult['values'],
                'merged_updates' => $mergedResult['updates'],
                'name_match' => $this->compareName($primarySummary, $secondarySummary),
                'dynasty_match' => $this->compareDynasty($primarySummary, $secondarySummary),
                'gender_match' => $this->compareGender($primarySummary, $secondarySummary),
                'altname_details_primary' => $primaryDetails['altname'],
                'altname_details_secondary' => $secondaryDetails['altname'],
                'kin_details_primary' => $primaryDetails['kin'],
                'kin_details_secondary' => $secondaryDetails['kin'],
                'assoc_details_primary' => $primaryDetails['assoc'],
                'assoc_details_secondary' => $secondaryDetails['assoc'],
                'other_details_primary' => $primaryDetails['other'],
                'other_details_secondary' => $secondaryDetails['other'],
                'table_counts_primary' => $primaryCounts,
                'table_counts_secondary' => $secondaryCounts,
                'sql_preview' => $this->buildSqlPreview($primary, $secondary, $mergedResult['updates'], $autoArrange, $minTargetId),
                'merge_reason' => $mergeReason,
                'biog_columns' => $this->getBiogColumns(),
                'share_url' => $this->buildShareUrl($request, $originalPrimary, $originalSecondary, $autoArrange, $mergeReason),
                'merge_blocked' => $mergeBlocked,
                'notes' => $mergeBlocked ? '姓名資訊不一致，請重新確認後再合併。' : '此區為示意合併結果，實作時將整合人物欄位。',
                'auto_min_target' => $minTargetId,
            ];
        }

        return view('manage.merge-preview', [
            'page_title' => 'MergePreview',
            'page_description' => '人物記錄合併預覽',
            'page_url' => '/merge-preview',
            'preview' => $preview,
            'auto_arrange' => $autoArrange,
            'merge_reason' => $mergeReason,
            'form_primary' => $primaryInput,
            'form_secondary' => $secondaryInput,
            'merge_blocked' => $preview['merge_blocked'] ?? false,
        ]);
    }

    protected function buildPersonSummary($personId, $mergeReason = '')
    {
        if ($personId === '') {
            return [
                'exists' => false,
                'id' => null,
                'name_chn' => null,
                'name' => null,
                'dynasty_code' => null,
                'dynasty_name' => null,
                'gender_code' => null,
                'gender_label' => null,
            ];
        }

        $person = BiogMain::find($personId);
        if (!$person) {
            return [
                'exists' => false,
                'id' => $personId,
                'name_chn' => null,
                'name' => null,
                'dynasty_code' => null,
                'dynasty_name' => null,
                'attributes' => [],
                'gender_code' => null,
                'gender_label' => null,
            ];
        }

        $dynastyName = null;
        if (!empty($person->c_dy)) {
            $dynasty = Dynasty::find($person->c_dy);
            if ($dynasty) {
                $dynastyName = $dynasty->c_dynasty_chn ?: $dynasty->c_dynasty_name;
            }
        }

        $genderCode = is_null($person->c_female) || $person->c_female === '' ? null : (int)$person->c_female;

        return [
            'exists' => true,
            'id' => $person->c_personid,
            'name_chn' => $person->c_name_chn,
            'name' => $person->c_name,
            'dynasty_code' => $person->c_dy,
            'dynasty_name' => $dynastyName,
            'attributes' => $person->getAttributes(),
            'gender_code' => $genderCode,
            'gender_label' => $this->formatGenderLabel($genderCode),
        ];
    }

    protected function compareDynasty(array $primary, array $secondary)
    {
        if (!$primary['exists'] || !$secondary['exists']) {
            return 'unknown';
        }

        if ($primary['dynasty_code'] === $secondary['dynasty_code']) {
            return 'same';
        }

        return 'different';
    }

    protected function compareName(array $primary, array $secondary)
    {
        if (!$primary['exists'] || !$secondary['exists']) {
            return 'unknown';
        }

        $primaryName = $primary['name'] ?? null;
        $primaryNameChn = $primary['name_chn'] ?? null;
        $secondaryName = $secondary['name'] ?? null;
        $secondaryNameChn = $secondary['name_chn'] ?? null;

        $primaryHasName = $this->normalizeName($primaryName) !== '' || $this->normalizeName($primaryNameChn) !== '';
        $secondaryHasName = $this->normalizeName($secondaryName) !== '' || $this->normalizeName($secondaryNameChn) !== '';

        if (!$primaryHasName || !$secondaryHasName) {
            return 'unknown';
        }

        $different = $this->namesDiffer($primaryName, $secondaryName) || $this->namesDiffer($primaryNameChn, $secondaryNameChn);

        return $different ? 'different' : 'same';
    }

    protected function compareGender(array $primary, array $secondary)
    {
        if (!$primary['exists'] || !$secondary['exists']) {
            return 'unknown';
        }

        $primaryGender = $primary['gender_code'];
        $secondaryGender = $secondary['gender_code'];

        if ($primaryGender === null || $secondaryGender === null) {
            return 'unknown';
        }

        return ((int)$primaryGender === (int)$secondaryGender) ? 'same' : 'different';
    }

    protected function formatGenderLabel($genderCode)
    {
        if ($genderCode === null) {
            return '未詳';
        }

        $value = (int)$genderCode;
        if ($value === 0) {
            return '0-男';
        }
        if ($value === 1) {
            return '1-女';
        }

        return (string)$genderCode;
    }

    protected function buildSqlPreview($primary, $secondary, array $mergedUpdates, $autoArrange, $minTargetId = null)
    {
        $statements = [];
        $statements[] = 'START TRANSACTION;';

        if (!empty($mergedUpdates)) {
            $setParts = [];
            foreach ($mergedUpdates as $field => $value) {
                $setParts[] = sprintf('%s = %s', $field, $this->formatSqlValue($value));
            }
            if (!empty($setParts)) {
                $statements[] = sprintf(
                    'UPDATE BIOG_MAIN SET %s WHERE c_personid = %s;',
                    implode(', ', $setParts),
                    $primary
                );
            }
        }

        $map = [
            'ALTNAME_DATA' => ['c_personid'],
            'ASSOC_DATA' => ['c_personid', 'c_kin_id', 'c_assoc_id', 'c_assoc_kin_id'],
            'BIOG_ADDR_DATA' => ['c_personid'],
            'BIOG_INST_DATA' => ['c_personid'],
            'BIOG_SOURCE_DATA' => ['c_personid'],
            'BIOG_TEXT_DATA' => ['c_personid'],
            'ENTRY_DATA' => ['c_personid'],
            'EVENTS_DATA' => ['c_personid'],
            'KIN_DATA' => ['c_personid', 'c_kin_id'],
            'POSSESSION_DATA' => ['c_personid'],
            'POSTED_TO_ADDR_DATA' => ['c_personid'],
            'POSTING_DATA' => ['c_personid'],
            'POSTED_TO_OFFICE_DATA' => ['c_personid'],
            'STATUS_DATA' => ['c_personid'],
        ];

        foreach ($map as $table => $columns) {
            foreach ($columns as $column) {
                $statements[] = sprintf(
                    'UPDATE %s SET %s = %s WHERE %s = %s;',
                    $table,
                    $column,
                    $primary,
                    $column,
                    $secondary
                );
            }
        }

        $statements[] = '-- 確認以下查詢結果皆為 0 後，再刪除來源人物資料';
        foreach ($map as $table => $columns) {
            foreach ($columns as $column) {
                $statements[] = sprintf(
                    'SELECT COUNT(*) AS remaining_%s_%s FROM %s WHERE %s = %s;',
                    $table,
                    $column,
                    $table,
                    $column,
                    $secondary
                );
            }
        }

        $statements[] = 'DELETE FROM BIOG_MAIN WHERE c_personid = '.$secondary.';';
        $statements[] = 'COMMIT;';

        if ($autoArrange && $minTargetId !== null && $primary !== $minTargetId) {
            $statements[] = 'START TRANSACTION;';
            $statements[] = sprintf('-- 調整至較小 ID %s', $minTargetId);
            foreach ($map as $table => $columns) {
                foreach ($columns as $column) {
                    $statements[] = sprintf(
                        'UPDATE %s SET %s = %s WHERE %s = %s;',
                        $table,
                        $column,
                        $minTargetId,
                        $column,
                        $primary
                    );
                }
            }
            $statements[] = sprintf('UPDATE BIOG_MAIN SET c_personid = %s WHERE c_personid = %s;', $minTargetId, $primary);
            $statements[] = 'COMMIT;';
        }

        return $statements;
    }

    protected function buildShareUrl(Request $request, $originalPrimary, $originalSecondary, $autoArrange, $reason)
    {
        $params = [
            'from' => $originalPrimary,
            'to' => $originalSecondary,
        ];
        $params['merge_to_min'] = $autoArrange ? 'true' : 'false';
        if ($reason !== '') {
            $params['reason'] = $reason;
        }

        return $request->url().'?'.http_build_query($params);
    }

    protected function getBiogColumns()
    {
        static $columns = null;
        if ($columns === null) {
            try {
                $columns = Schema::getColumnListing('BIOG_MAIN');
            } catch (\Throwable $e) {
                $columns = [];
            }
        }
        return $columns;
    }

    protected function calculateMergedPerson(array $primary, array $secondary, $mergeReason)
    {
        if (!$secondary['exists']) {
            return [
                'values' => [],
                'updates' => [],
            ];
        }

        $primaryAttributes = $primary['attributes'] ?? [];
        $secondaryAttributes = $secondary['attributes'] ?? [];

        // 模擬更新後的狀態：以保留人物的非空值覆寫次要人物
        $result = $secondaryAttributes;
        foreach ($primaryAttributes as $key => $value) {
            if (!is_null($value) && $value !== '') {
                $result[$key] = $value;
            }
        }

        // c_personid 最終會取保留人物
        if (isset($primaryAttributes['c_personid'])) {
            $result['c_personid'] = $primaryAttributes['c_personid'];
        }

        // 更新操作者與日期
        $currentDate = Carbon::now()->format('Ymd');
        $result['c_modified_by'] = Auth::check() ? (Auth::user()->name ?? Auth::user()->email ?? 'admin') : 'admin';
        $result['c_modified_date'] = $currentDate;

        $primaryNotes = isset($primaryAttributes['c_notes']) ? trim((string)$primaryAttributes['c_notes']) : '';
        $secondaryNotes = isset($secondaryAttributes['c_notes']) ? trim((string)$secondaryAttributes['c_notes']) : '';
        $notesLines = [];
        if ($primaryNotes !== '') {
            $notesLines[] = $primaryNotes;
        }
        if ($secondaryNotes !== '') {
            $notesLines[] = $secondaryNotes;
        }
        $mergedSourceId = $secondaryAttributes['c_personid'] ?? ($secondary['id'] ?? null);
        $primaryPersonId = $primaryAttributes['c_personid'] ?? ($primary['id'] ?? null);
        $idSegments = [];
        if (!is_null($primaryPersonId) && $primaryPersonId !== '') {
            $idSegments[] = '#'.$primaryPersonId;
        }
        if (!is_null($mergedSourceId) && $mergedSourceId !== '') {
            $idSegments[] = '#'.$mergedSourceId;
        }
        $mergeTag = '[merged';
        if (!empty($idSegments)) {
            $mergeTag .= ' '.implode(' and ', $idSegments);
        }
        $mergeTag .= ' on '.$currentDate.' with reason]';
        $reasonText = trim((string)$mergeReason);
        if ($reasonText !== '') {
            $mergeTag .= ' '.$reasonText;
        }
        $notesLines[] = $mergeTag;
        $result['c_notes'] = implode("\n", $notesLines);

        $updates = [];
        foreach ($result as $field => $value) {
            if ($field === 'c_personid') {
                continue;
            }
            $original = $primaryAttributes[$field] ?? null;
            // normalise empty string vs null
            if ($original instanceof \DateTimeInterface) {
                $original = $original->format('Y-m-d H:i:s');
            }
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }
            if ($original !== $value) {
                $updates[$field] = $value;
            }
        }

        return [
            'values' => $result,
            'updates' => $updates,
        ];
    }

    protected function formatSqlValue($value)
    {
        if (is_null($value)) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_numeric($value) && !is_string($value)) {
            return (string)$value;
        }

        $string = (string)$value;
        $escaped = str_replace("'", "''", $string);
        return "'".$escaped."'";
    }

    protected function loadSecondaryDetails(array $summary)
    {
        if (!$summary['exists']) {
            return ['altname' => [], 'kin' => [], 'assoc' => [], 'other' => []];
        }

        $personId = $summary['id'];

        $altNames = \DB::table('ALTNAME_DATA')
            ->select(
                'ALTNAME_DATA.c_personid',
                'ALTNAME_DATA.c_sequence',
                'ALTNAME_DATA.c_alt_name_type_code',
                'ALTNAME_DATA.c_alt_name_chn',
                'ALTNAME_DATA.c_alt_name',
                'ALTNAME_DATA.c_notes',
                'ALTNAME_CODES.c_name_type_desc_chn as alt_type_label_chn',
                'ALTNAME_CODES.c_name_type_desc as alt_type_label'
            )
            ->leftJoin('ALTNAME_CODES', 'ALTNAME_CODES.c_name_type_code', '=', 'ALTNAME_DATA.c_alt_name_type_code')
            ->where('ALTNAME_DATA.c_personid', $personId)
            ->orderBy('c_sequence')
            ->limit(20)
            ->get();

        $kin = \DB::table('KIN_DATA')
            ->select(
                'KIN_DATA.c_personid',
                'KIN_DATA.c_kin_id',
                'KIN_DATA.c_kin_code',
                'KIN_DATA.c_notes',
                'KINSHIP_CODES.c_kinrel_chn as kinship_label_chn',
                'KINSHIP_CODES.c_kinrel as kinship_label',
                'BIOG_MAIN.c_name_chn as kin_name_chn',
                'BIOG_MAIN.c_name as kin_name'
            )
            ->leftJoin('KINSHIP_CODES', 'KINSHIP_CODES.c_kincode', '=', 'KIN_DATA.c_kin_code')
            ->leftJoin('BIOG_MAIN', 'BIOG_MAIN.c_personid', '=', 'KIN_DATA.c_kin_id')
            ->where('KIN_DATA.c_personid', $personId)
            ->limit(20)
            ->get();

        $assocColumns = [
            'c_personid' => 'c_personid',
            'c_kin_id' => 'c_kin_id',
            'c_assoc_id' => 'c_assoc_id',
            'c_assoc_kin_id' => 'c_assoc_kin_id',
        ];
        $assocDetails = [];
        foreach ($assocColumns as $key => $column) {
            $assocDetails[$key] = \DB::table('ASSOC_DATA')
                ->select('c_personid', 'c_assoc_id', 'c_kin_id', 'c_assoc_kin_id', 'c_notes')
                ->where($column, $personId)
                ->limit(20)
                ->get();
        }

        $otherConfigs = [
            'BIOG_ADDR_DATA' => ['column' => 'c_personid'],
            'BIOG_INST_DATA' => ['column' => 'c_personid'],
            'BIOG_SOURCE_DATA' => ['column' => 'c_personid'],
            'BIOG_TEXT_DATA' => ['column' => 'c_personid'],
            'ENTRY_DATA' => ['column' => 'c_personid'],
            'EVENTS_DATA' => ['column' => 'c_personid'],
            'POSSESSION_DATA' => ['column' => 'c_personid'],
            'STATUS_DATA' => ['column' => 'c_personid'],
            'POSTED_TO_ADDR_DATA' => ['column' => 'c_personid'],
            'POSTING_DATA' => ['column' => 'c_personid'],
            'POSTED_TO_OFFICE_DATA' => ['column' => 'c_personid'],
        ];
        $otherDetails = [];
        foreach ($otherConfigs as $table => $info) {
            $otherDetails[$table] = \DB::table($table)
                ->where($info['column'], $personId)
                ->limit(20)
                ->get();
        }

        return [
            'altname' => $altNames,
            'kin' => $kin,
            'assoc' => $assocDetails,
            'other' => $otherDetails,
        ];
    }

    protected function buildTableSummaries(array $summary)
    {
        if (!$summary['exists']) {
            return [
                'assoc' => ['c_personid' => 0, 'c_kin_id' => 0, 'c_assoc_id' => 0, 'c_assoc_kin_id' => 0],
                'biog_addr' => 0,
                'biog_inst' => 0,
                'biog_source' => 0,
                'biog_text' => 0,
                'entry' => 0,
                'events' => 0,
                'possession' => 0,
                'status' => 0,
                'posted_to_addr' => 0,
                'posting' => 0,
                'posted_to_office' => 0,
            ];
        }

        $id = $summary['id'];

        $assocCounts = [
            'c_personid' => \DB::table('ASSOC_DATA')->where('c_personid', $id)->count(),
            'c_kin_id' => \DB::table('ASSOC_DATA')->where('c_kin_id', $id)->count(),
            'c_assoc_id' => \DB::table('ASSOC_DATA')->where('c_assoc_id', $id)->count(),
            'c_assoc_kin_id' => \DB::table('ASSOC_DATA')->where('c_assoc_kin_id', $id)->count(),
        ];

        $simpleTables = [
            'biog_addr' => ['table' => 'BIOG_ADDR_DATA', 'column' => 'c_personid'],
            'biog_inst' => ['table' => 'BIOG_INST_DATA', 'column' => 'c_personid'],
            'biog_source' => ['table' => 'BIOG_SOURCE_DATA', 'column' => 'c_personid'],
            'biog_text' => ['table' => 'BIOG_TEXT_DATA', 'column' => 'c_personid'],
            'entry' => ['table' => 'ENTRY_DATA', 'column' => 'c_personid'],
            'events' => ['table' => 'EVENTS_DATA', 'column' => 'c_personid'],
            'possession' => ['table' => 'POSSESSION_DATA', 'column' => 'c_personid'],
            'status' => ['table' => 'STATUS_DATA', 'column' => 'c_personid'],
            'posted_to_addr' => ['table' => 'POSTED_TO_ADDR_DATA', 'column' => 'c_personid'],
            'posting' => ['table' => 'POSTING_DATA', 'column' => 'c_personid'],
            'posted_to_office' => ['table' => 'POSTED_TO_OFFICE_DATA', 'column' => 'c_personid'],
        ];

        $counts = [
            'assoc' => $assocCounts,
        ];

        foreach ($simpleTables as $key => $info) {
            $counts[$key] = \DB::table($info['table'])->where($info['column'], $id)->count();
        }

        return $counts;
    }

    protected function shouldBlockMerge(array $primary, array $secondary)
    {
        if (!$primary['exists'] || !$secondary['exists']) {
            return false;
        }

        $nameDiff = $this->namesDiffer($primary['name'], $secondary['name']);
        $nameChnDiff = $this->namesDiffer($primary['name_chn'], $secondary['name_chn']);

        return $nameDiff || $nameChnDiff;
    }

    protected function namesDiffer($a, $b)
    {
        $normA = $this->normalizeName($a);
        $normB = $this->normalizeName($b);

        if ($normA === '' && $normB === '') {
            return false;
        }

        return $normA !== $normB;
    }

    protected function normalizeName($value)
    {
        if ($value === null) {
            return '';
        }
        $trimmed = trim((string)$value);
        if ($trimmed === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($trimmed, 'UTF-8');
        }

        return strtolower($trimmed);
    }
}
