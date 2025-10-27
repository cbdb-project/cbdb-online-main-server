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
    protected $addressCache = [];
    protected $officeCache = [];
    protected $textCache = [];
    protected $entryCache = [];
    protected $eventCache = [];
    protected $statusCache = [];
    protected $possessionCache = [];
    protected $roleCache = [];
    protected $socialInstCache = [];
    protected $personCache = [];

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
        $primaryRaw = $request->input('primary_id', '');
        $secondaryRaw = $request->input('secondary_id', '');
        $reasonRaw = $request->filled('merge_reason') ? $request->input('merge_reason') : $request->input('reason', '');

        $primaryInput = trim((string)$primaryRaw);
        $secondaryInput = trim((string)$secondaryRaw);
        $mergeReason = trim((string)$reasonRaw);

        $shouldPreview = ($primaryInput !== '' && $secondaryInput !== '') &&
            (
                $request->isMethod('post')
                || $request->query->has('primary_id')
                || $request->query->has('secondary_id')
            );

        if ($shouldPreview) {
            $originalPrimary = $primaryInput;
            $originalSecondary = $secondaryInput;

        $primary = $primaryInput;
        $secondary = $secondaryInput;
        $primaryNumeric = (int) $primaryInput;
        $secondaryNumeric = (int) $secondaryInput;
        $minTargetId = $autoArrange ? min($primaryNumeric, $secondaryNumeric) : null;

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
                'sql_preview' => $this->buildSqlPreview($primary, $secondary, $mergedResult, $autoArrange, $minTargetId),
                'merge_reason' => $mergeReason,
                'merge_record' => $mergedResult['merge_record'] ?? null,
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

    protected function buildSqlPreview($primary, $secondary, array $mergedResult, $autoArrange, $minTargetId = null)
    {
        $statements = [];
        $statements[] = 'START TRANSACTION;';

        $mergedUpdates = $mergedResult['updates'] ?? [];
        $mergeRecord = $mergedResult['merge_record'] ?? null;

        $primaryNumeric = (int) $primary;
        $secondaryNumeric = (int) $secondary;
        $primaryValue = $this->formatSqlValue($primaryNumeric);
        $secondaryValue = $this->formatSqlValue($secondaryNumeric);
        $minTargetValue = !is_null($minTargetId) ? $this->formatSqlValue((int)$minTargetId) : null;

        if (!empty($mergedUpdates)) {
            $setParts = [];
            foreach ($mergedUpdates as $field => $value) {
                $setParts[] = sprintf('%s = %s', $field, $this->formatSqlValue($value));
            }
            if (!empty($setParts)) {
                $statements[] = sprintf(
                    'UPDATE BIOG_MAIN SET %s WHERE c_personid = %s;',
                    implode(', ', $setParts),
                    $primaryValue
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
                    $primaryValue,
                    $column,
                    $secondaryValue
                );
            }
        }

        if ($mergeRecord) {
            $personIdValue = $this->formatSqlValue($mergeRecord['person_id'] ?? null);
            $mergedToValue = $this->formatSqlValue($mergeRecord['merged_to'] ?? null);
            $noteValue = $this->formatSqlValue($mergeRecord['note'] ?? null);
            $createdByValue = $this->formatSqlValue($mergeRecord['created_by'] ?? null);
            $createdDateValue = $this->formatSqlValue($mergeRecord['created_date'] ?? null);
            $modifiedByValue = $this->formatSqlValue($mergeRecord['modified_by'] ?? null);
            $modifiedDateValue = $this->formatSqlValue($mergeRecord['modified_date'] ?? null);

            if ($personIdValue !== 'NULL' && $mergedToValue !== 'NULL') {
                $statements[] = sprintf(
                    'INSERT INTO MERGED_PERSON_DATA (c_personid, c_merged_to_personid, c_notes, c_source, c_pages, c_created_by, c_created_date, c_modified_by, c_modified_date) VALUES (%s, %s, %s, NULL, NULL, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE c_notes = VALUES(c_notes), c_modified_by = VALUES(c_modified_by), c_modified_date = VALUES(c_modified_date);',
                    $personIdValue,
                    $mergedToValue,
                    $noteValue,
                    $createdByValue,
                    $createdDateValue,
                    $modifiedByValue,
                    $modifiedDateValue
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
                    $secondaryValue
                );
            }
        }
        $statements[] = 'DELETE FROM BIOG_MAIN WHERE c_personid = '.$secondary.';';
        $statements[] = 'COMMIT;';

        if ($autoArrange && $minTargetId !== null && $primaryNumeric !== $minTargetId) {
            $statements[] = 'START TRANSACTION;';
            $statements[] = sprintf('-- 調整至較小 ID %s', $minTargetId);
            foreach ($map as $table => $columns) {
                foreach ($columns as $column) {
                    $statements[] = sprintf(
                        'UPDATE %s SET %s = %s WHERE %s = %s;',
                        $table,
                        $column,
                        $minTargetValue,
                        $column,
                        $primaryValue
                    );
                }
            }
            if ($mergeRecord) {
                $statements[] = sprintf(
                    'UPDATE MERGED_PERSON_DATA SET c_personid = %s, c_merged_to_personid = %s WHERE c_personid = %s AND c_merged_to_personid = %s;',
                    $primaryValue,
                    $minTargetValue ?? $this->formatSqlValue($minTargetId),
                    $personIdValue ?? $this->formatSqlValue($mergeRecord['person_id'] ?? null),
                    $mergedToValue ?? $this->formatSqlValue($mergeRecord['merged_to'] ?? null)
                );
            }
            $statements[] = sprintf('UPDATE BIOG_MAIN SET c_personid = %s WHERE c_personid = %s;', $minTargetValue, $primaryValue);
            $statements[] = 'COMMIT;';
        }

        return $statements;
    }

    protected function buildShareUrl(Request $request, $originalPrimary, $originalSecondary, $autoArrange, $reason)
    {
        $params = [
            'primary_id' => $originalPrimary,
            'secondary_id' => $originalSecondary,
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
        $mergeTag = $this->buildMergeTag($primaryPersonId, $mergedSourceId, $currentDate, $mergeReason);
        $notesLines[] = $mergeTag;
        $result['c_notes'] = implode('\\n', $notesLines);

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

        $mergeRecord = null;
        $mergeReasonText = trim((string)$mergeReason);
        if (!is_null($primaryPersonId) && $primaryPersonId !== '' && !is_null($mergedSourceId) && $mergedSourceId !== '') {
            $mergeRecord = [
                'person_id' => (int)$mergedSourceId,
                'merged_to' => (int)$primaryPersonId,
                'note' => $mergeReasonText === '' ? null : $mergeReasonText,
                'reason' => $mergeReason,
                'created_by' => $result['c_modified_by'],
                'created_date' => $currentDate,
                'modified_by' => $result['c_modified_by'],
                'modified_date' => $currentDate,
            ];
        }

        return [
            'values' => $result,
            'updates' => $updates,
            'merge_record' => $mergeRecord,
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

    protected function buildMergeTag($targetId, $sourceId, $date, $reason)
    {
        $segments = [];
        if ($targetId !== null && $targetId !== '') {
            $segments[] = '#'.$targetId;
        }
        if ($sourceId !== null && $sourceId !== '') {
            $segments[] = '#'.$sourceId;
        }

        $note = '[merged';
        if (!empty($segments)) {
            $note .= ' '.implode(' and ', $segments);
        }
        $dateText = trim((string)$date);
        if ($dateText !== '') {
            $note .= ' on '.$dateText;
        }
        $note .= ' with reason]';
        $reasonText = trim((string)$reason);
        if ($reasonText !== '') {
            $note .= ' '.$reasonText;
        }
        return $note;
    }

    protected function summarizeOtherRow($table, array $data)
    {
        $get = function ($key) use ($data) {
            return array_key_exists($key, $data) ? $data[$key] : null;
        };
        $format = function ($value) {
            if ($value === null || $value === '') {
                return '(null)';
            }
            return (string)$value;
        };
        $formatId = function ($id, $label = null) {
            if ($id === null || $id === '') {
                return '(null)';
            }
            $idStr = (string)$id;
            if ($label !== null && $label !== '') {
                return $idStr.' '.$label;
            }
            return $idStr;
        };
        $notes = trim((string)$get('c_notes'));
        $noteSuffix = $notes !== '' ? ' | Notes: '.$notes : '';

        switch ($table) {
            case 'BIOG_ADDR_DATA':
                $addrId = $get('c_addr_id');
                $addrLabel = $this->getAddressLabel($addrId);
                return sprintf(
                    'Seq %s — Addr %s (type %s)%s',
                    $format($get('c_sequence')),
                    $formatId($addrId, $addrLabel),
                    $format($get('c_addr_type')),
                    $noteSuffix
                );
            case 'BIOG_INST_DATA':
                $instCode = $get('c_inst_code');
                $instNameCode = $get('c_inst_name_code');
                $instLabel = $this->getSocialInstLabel($instCode, $instNameCode);
                return sprintf(
                    'Inst %s — NameCode %s%s',
                    $formatId($instCode, $instLabel),
                    $format($instNameCode),
                    $noteSuffix
                );
            case 'BIOG_SOURCE_DATA':
                $textId = $get('c_textid');
                $textLabel = $this->getTextLabel($textId);
                return sprintf(
                    'Source %s — Pages %s%s',
                    $formatId($textId, $textLabel),
                    $format($get('c_pages')),
                    $noteSuffix
                );
            case 'BIOG_TEXT_DATA':
                $textId = $get('c_textid');
                $textLabel = $this->getTextLabel($textId);
                $roleId = $get('c_role_id');
                $roleLabel = $this->getRoleLabel($roleId);
                return sprintf(
                    'Text %s — Role %s — Seq %s%s',
                    $formatId($textId, $textLabel),
                    $formatId($roleId, $roleLabel),
                    $format($get('c_sequence')),
                    $noteSuffix
                );
            case 'ENTRY_DATA':
                $entryCode = $get('c_entry_code');
                $entryLabel = $this->getEntryLabel($entryCode);
                return sprintf(
                    'Entry %s — Seq %s — Year %s%s',
                    $formatId($entryCode, $entryLabel),
                    $format($get('c_sequence')),
                    $format($get('c_year')),
                    $noteSuffix
                );
            case 'EVENTS_DATA':
                $eventCode = $get('c_event_code');
                $eventLabel = $this->getEventLabel($eventCode);
                return sprintf(
                    'Event %s — Seq %s — Year %s%s',
                    $formatId($eventCode, $eventLabel),
                    $format($get('c_sequence')),
                    $format($get('c_year')),
                    $noteSuffix
                );
            case 'POSSESSION_DATA':
                $recordId = $get('c_possession_record_id');
                $actCode = $get('c_possession_act_code');
                $actLabel = $this->getPossessionLabel($actCode);
                $desc = $get('c_possession_desc_chn') ?: $get('c_possession_desc');
                $main = $desc ? trim((string)$desc) : $formatId($actCode, $actLabel);
                return sprintf(
                    'Possession %s — %s%s',
                    $format($recordId),
                    $main,
                    $noteSuffix
                );
            case 'STATUS_DATA':
                $statusCode = $get('c_status_code');
                $statusLabel = $this->getStatusLabel($statusCode);
                return sprintf(
                    'Status %s — Seq %s — Year %s%s',
                    $formatId($statusCode, $statusLabel),
                    $format($get('c_sequence')),
                    $format($get('c_year')),
                    $noteSuffix
                );
            case 'POSTED_TO_ADDR_DATA':
                $officeId = $get('c_office_id');
                $officeLabel = $this->getOfficeLabel($officeId);
                $addrId = $get('c_addr_id');
                $addrLabel = $this->getAddressLabel($addrId);
                return sprintf(
                    'Posting %s — Office %s — Addr %s%s',
                    $format($get('c_posting_id')),
                    $formatId($officeId, $officeLabel),
                    $formatId($addrId, $addrLabel),
                    $noteSuffix
                );
            case 'POSTING_DATA':
                $officeId = $get('c_office_id');
                $officeLabel = $this->getOfficeLabel($officeId);
                return sprintf(
                    'Posting %s — Office %s%s',
                    $format($get('c_posting_id')),
                    $formatId($officeId, $officeLabel),
                    $noteSuffix
                );
            case 'POSTED_TO_OFFICE_DATA':
                $officeId = $get('c_office_id');
                $officeLabel = $this->getOfficeLabel($officeId);
                return sprintf(
                    'Posting %s — Office %s — Person %s%s',
                    $format($get('c_posting_id')),
                    $formatId($officeId, $officeLabel),
                    $format($get('c_personid')),
                    $noteSuffix
                );
            case 'MERGED_PERSON_DATA':
                $fromId = $get('c_personid');
                $toId = $get('c_merged_to_personid');
                $fromLabel = $this->getPersonLabel($fromId);
                $toLabel = $this->getPersonLabel($toId);
                $reason = trim((string)$get('c_notes'));
                $reasonSuffix = $reason !== '' ? ' | Reason: '.$reason : '';
                return sprintf(
                    'Merged %s → %s%s',
                    $formatId($fromId, $fromLabel),
                    $formatId($toId, $toLabel),
                    $reasonSuffix
                );
            default:
                $parts = [];
                foreach ($data as $key => $val) {
                    $parts[] = $key.':'.$format($val);
                }
                return sprintf('%s — %s', $table, implode(', ', $parts));
        }
    }

    protected function getAddressLabel($id)
    {
        if ($id === null || $id === '' || (int)$id === 0) {
            return '未詳';
        }
        $id = (int)$id;
        if (!array_key_exists($id, $this->addressCache)) {
            $row = \DB::table('ADDR_CODES')
                ->select('c_name_chn', 'c_name')
                ->where('c_addr_id', $id)
                ->first();
            $this->addressCache[$id] = $row ? trim($row->c_name_chn ?: $row->c_name ?: '') : null;
        }
        return $this->addressCache[$id];
    }

    protected function getOfficeLabel($id)
    {
        if ($id === null || $id === '' || (int)$id === 0) {
            return null;
        }
        $id = (int)$id;
        if (!array_key_exists($id, $this->officeCache)) {
            $row = \DB::table('OFFICE_CODES')
                ->select('c_office_chn', 'c_office_pinyin')
                ->where('c_office_id', $id)
                ->first();
            $this->officeCache[$id] = $row ? trim($row->c_office_chn ?: $row->c_office_pinyin ?: '') : null;
        }
        return $this->officeCache[$id];
    }

    protected function getTextLabel($id)
    {
        if ($id === null || $id === '' || (int)$id === 0) {
            return null;
        }
        $id = (int)$id;
        if (!array_key_exists($id, $this->textCache)) {
            $row = \DB::table('TEXT_CODES')
                ->select('c_title_chn', 'c_title')
                ->where('c_textid', $id)
                ->first();
            $this->textCache[$id] = $row ? trim($row->c_title_chn ?: $row->c_title ?: '') : null;
        }
        return $this->textCache[$id];
    }

    protected function getEntryLabel($code)
    {
        if ($code === null || $code === '' || (int)$code === 0) {
            return null;
        }
        $code = (int)$code;
        if (!array_key_exists($code, $this->entryCache)) {
            $row = \DB::table('ENTRY_CODES')
                ->select('c_entry_desc_chn', 'c_entry_desc')
                ->where('c_entry_code', $code)
                ->first();
            $this->entryCache[$code] = $row ? trim($row->c_entry_desc_chn ?: $row->c_entry_desc ?: '') : null;
        }
        return $this->entryCache[$code];
    }

    protected function getEventLabel($code)
    {
        if ($code === null || $code === '' || (int)$code === 0) {
            return null;
        }
        $code = (int)$code;
        if (!array_key_exists($code, $this->eventCache)) {
            $row = \DB::table('EVENT_CODES')
                ->select('c_event_name_chn', 'c_event_name')
                ->where('c_event_code', $code)
                ->first();
            $this->eventCache[$code] = $row ? trim($row->c_event_name_chn ?: $row->c_event_name ?: '') : null;
        }
        return $this->eventCache[$code];
    }

    protected function getPossessionLabel($code)
    {
        if ($code === null || $code === '' || (int)$code === 0) {
            return null;
        }
        $code = (int)$code;
        if (!array_key_exists($code, $this->possessionCache)) {
            $row = \DB::table('POSSESSION_ACT_CODES')
                ->select('c_possession_act_desc_chn', 'c_possession_act_desc')
                ->where('c_possession_act_code', $code)
                ->first();
            $this->possessionCache[$code] = $row ? trim($row->c_possession_act_desc_chn ?: $row->c_possession_act_desc ?: '') : null;
        }
        return $this->possessionCache[$code];
    }

    protected function getStatusLabel($code)
    {
        if ($code === null || $code === '' || (int)$code === 0) {
            return null;
        }
        $code = (int)$code;
        if (!array_key_exists($code, $this->statusCache)) {
            $row = \DB::table('STATUS_CODES')
                ->select('c_status_desc_chn', 'c_status_desc')
                ->where('c_status_code', $code)
                ->first();
            $this->statusCache[$code] = $row ? trim($row->c_status_desc_chn ?: $row->c_status_desc ?: '') : null;
        }
        return $this->statusCache[$code];
    }

    protected function getRoleLabel($id)
    {
        if ($id === null || $id === '' || (int)$id === 0) {
            return null;
        }
        $id = (int)$id;
        if (!array_key_exists($id, $this->roleCache)) {
            $row = \DB::table('TEXT_ROLE_CODES')
                ->select('c_role_desc_chn', 'c_role_desc')
                ->where('c_role_id', $id)
                ->first();
            $this->roleCache[$id] = $row ? trim($row->c_role_desc_chn ?: $row->c_role_desc ?: '') : null;
        }
        return $this->roleCache[$id];
    }

    protected function getSocialInstLabel($code, $nameCode)
    {
        if ($code === null || $code === '' || $nameCode === null || $nameCode === '') {
            return null;
        }
        $key = $code.'-'.$nameCode;
        if (!array_key_exists($key, $this->socialInstCache)) {
            $row = \DB::table('SOCIAL_INSTITUTION_CODES')
                ->select('c_inst_name_hz', 'c_inst_name_py')
                ->where('c_inst_code', $code)
                ->where('c_inst_name_code', $nameCode)
                ->first();
            $this->socialInstCache[$key] = $row ? trim($row->c_inst_name_hz ?: $row->c_inst_name_py ?: '') : null;
        }
        return $this->socialInstCache[$key];
    }

    protected function getPersonLabel($id)
    {
        if ($id === null || $id === '' || (int)$id === 0) {
            return null;
        }
        $id = (int)$id;
        if (!array_key_exists($id, $this->personCache)) {
            $row = \DB::table('BIOG_MAIN')
                ->select('c_name_chn', 'c_name')
                ->where('c_personid', $id)
                ->first();
            if ($row) {
                $label = trim($row->c_name_chn ?: '');
                if ($row->c_name && trim($row->c_name) !== '') {
                    $label = trim($label.' '.$row->c_name);
                }
                $this->personCache[$id] = $label !== '' ? $label : null;
            } else {
                $this->personCache[$id] = null;
            }
        }
        return $this->personCache[$id];
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
            'BIOG_ADDR_DATA' => ['columns' => ['c_personid']],
            'BIOG_INST_DATA' => ['columns' => ['c_personid']],
            'BIOG_SOURCE_DATA' => ['columns' => ['c_personid']],
            'BIOG_TEXT_DATA' => ['columns' => ['c_personid']],
            'ENTRY_DATA' => ['columns' => ['c_personid']],
            'EVENTS_DATA' => ['columns' => ['c_personid']],
            'POSSESSION_DATA' => ['columns' => ['c_personid']],
            'STATUS_DATA' => ['columns' => ['c_personid']],
            'POSTED_TO_ADDR_DATA' => ['columns' => ['c_personid']],
            'POSTING_DATA' => ['columns' => ['c_personid']],
            'POSTED_TO_OFFICE_DATA' => ['columns' => ['c_personid']],
            'MERGED_PERSON_DATA' => ['columns' => ['c_personid', 'c_merged_to_personid']],
        ];
        $otherDetails = [];
        foreach ($otherConfigs as $table => $info) {
            $columns = $info['columns'] ?? (isset($info['column']) ? [$info['column']] : []);
            if (empty($columns)) {
                continue;
            }
            $otherDetails[$table] = \DB::table($table)
                ->where(function ($query) use ($columns, $personId) {
                    foreach ($columns as $index => $column) {
                        if ($index === 0) {
                            $query->where($column, $personId);
                        } else {
                            $query->orWhere($column, $personId);
                        }
                    }
                })
                ->limit(20)
                ->get()
                ->map(function ($row) use ($table) {
                    $rowArray = (array)$row;
                    return [
                        'summary' => $this->summarizeOtherRow($table, $rowArray),
                        'raw' => $rowArray,
                    ];
                })
                ->toArray();
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
                'merged_person' => 0,
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
            'biog_addr' => ['table' => 'BIOG_ADDR_DATA', 'columns' => ['c_personid']],
            'biog_inst' => ['table' => 'BIOG_INST_DATA', 'columns' => ['c_personid']],
            'biog_source' => ['table' => 'BIOG_SOURCE_DATA', 'columns' => ['c_personid']],
            'biog_text' => ['table' => 'BIOG_TEXT_DATA', 'columns' => ['c_personid']],
            'entry' => ['table' => 'ENTRY_DATA', 'columns' => ['c_personid']],
            'events' => ['table' => 'EVENTS_DATA', 'columns' => ['c_personid']],
            'possession' => ['table' => 'POSSESSION_DATA', 'columns' => ['c_personid']],
            'status' => ['table' => 'STATUS_DATA', 'columns' => ['c_personid']],
            'posted_to_addr' => ['table' => 'POSTED_TO_ADDR_DATA', 'columns' => ['c_personid']],
            'posting' => ['table' => 'POSTING_DATA', 'columns' => ['c_personid']],
            'posted_to_office' => ['table' => 'POSTED_TO_OFFICE_DATA', 'columns' => ['c_personid']],
            'merged_person' => ['table' => 'MERGED_PERSON_DATA', 'columns' => ['c_personid', 'c_merged_to_personid']],
        ];

        $counts = [
            'assoc' => $assocCounts,
        ];

        foreach ($simpleTables as $key => $info) {
            $columns = $info['columns'] ?? (isset($info['column']) ? [$info['column']] : []);
            if (empty($columns)) {
                $counts[$key] = 0;
                continue;
            }
            $counts[$key] = \DB::table($info['table'])
                ->where(function ($query) use ($columns, $id) {
                    foreach ($columns as $index => $column) {
                        if ($index === 0) {
                            $query->where($column, $id);
                        } else {
                            $query->orWhere($column, $id);
                        }
                    }
                })
                ->count();
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
