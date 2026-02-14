<?php

namespace App\Repositories;

use App\Models\AddrBelong;
use App\Models\AddrCode;
use App\Models\EventCode;
use App\Models\StatusCode;
use App\Models\TextCode;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class EventStatusRepository {
    public function statuseById($id) {
        $id = str_replace("--", "-minus", $id);
        $temp_l = explode("-", $id);
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus", "-", $value);
        }
        $row = DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->first();
        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;
        }
        $statuse_str = null;
        if ($row->c_status_code || $row->c_status_code === 0) {
            $text_ = StatusCode::find($row->c_status_code);
            $statuse_str = $text_->c_status_code." ".$text_->c_status_desc_chn." ".$text_->c_status_desc;
        }

        return ['row' => $row, 'text_str' => $text_str, 'statuse_str' => $statuse_str];
    }

    public function statuseUpdateById(Request $request, $id, $c_personid) {
        $id = str_replace("--", "-minus", $id);
        $temp_l = explode("-", $id);
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus", "-", $value);
        }
        $row = DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->first();
        $data = $request->all();
        $data = Arr::except($data, ['_token', '_method']);
        $data['c_status_code'] = $data['c_status_code'] == -999 ? '0' : $data['c_status_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data);
        DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->update($data);
        $operation = (new OperationRepository())->store(Auth::id(), $c_personid, 3, 'STATUS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $c_personid,
            'c_sequence' => $data['c_sequence'],
            'c_status_code' => $data['c_status_code'],
        ]), $data, $row);
        (new AuditLogService())->write(
            'STATUS_DATA',
            'UPDATE',
            [
                'c_personid' => $data['c_personid'],
                'c_sequence' => $data['c_sequence'],
                'c_status_code' => $data['c_status_code'],
            ],
            (new AuditLogService())->normalizeRow($row),
            $data,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        return $data;
    }

    public function statuseStoreById(Request $request, $id) {
        $data = $request->all();
        $data = Arr::except($data, ['_token']);
        $data['c_personid'] = $id;
        $data['c_status_code'] = $data['c_status_code'] == -999 ? '0' : $data['c_status_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];
        $data = (new ToolsRepository())->timestamp($data, true);
        DB::table('STATUS_DATA')->insert($data);
        $operation = (new OperationRepository())->store(Auth::id(), $data['c_personid'], 1, 'STATUS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $data['c_personid'],
            'c_sequence' => $data['c_sequence'],
            'c_status_code' => $data['c_status_code'],
        ]), $data);
        (new AuditLogService())->write(
            'STATUS_DATA',
            'INSERT',
            [
                'c_personid' => $data['c_personid'],
                'c_sequence' => $data['c_sequence'],
                'c_status_code' => $data['c_status_code'],
            ],
            null,
            $data,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        return $data;
    }

    public function statuseDeleteById($id, $c_personid) {
        $id = str_replace("--", "-minus", $id);
        $temp_l = explode("-", $id);
        foreach ($temp_l as $key => $value) {
            $temp_l[$key] = str_replace("minus", "-", $value);
        }
        $row = DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->first();
        DB::table('STATUS_DATA')->where([
            ['c_personid', '=', $temp_l[0]],
            ['c_sequence', '=', $temp_l[1]],
            ['c_status_code', '=', $temp_l[2]],
        ])->delete();
        $operation = (new OperationRepository())->store(Auth::id(), $id, 4, 'STATUS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $temp_l[0],
            'c_sequence' => $temp_l[1],
            'c_status_code' => $temp_l[2],
        ]), $row);
        (new AuditLogService())->write(
            'STATUS_DATA',
            'DELETE',
            [
                'c_personid' => $temp_l[0],
                'c_sequence' => $temp_l[1],
                'c_status_code' => $temp_l[2],
            ],
            (new AuditLogService())->normalizeRow($row),
            null,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );
    }

    public function eventById($id) {
        $id_arr = explode("-", $id);
        $query = DB::table('EVENTS_DATA')
            ->where('c_personid', $id_arr[0])
            ->where('c_sequence', $id_arr[1]);
        // 新格式包含 c_event_code（第 3 個字段），舊格式僅 c_personid-c_sequence
        if (isset($id_arr[2])) {
            $query->where('c_event_code', $id_arr[2]);
        }
        $row = $query->first();
        $text_str = null;
        if ($row->c_source || $row->c_source === 0) {
            $text_ = TextCode::find($row->c_source);
            $text_str = $text_->c_textid." ".$text_->c_title." ".$text_->c_title_chn;

        }
        $addr_ = DB::table('EVENTS_ADDR')
            ->where('c_personid', $row->c_personid)
            ->where('c_sequence', $row->c_sequence)
            ->where('c_event_code', $row->c_event_code)
            ->get();
        $addr_str = [];
        foreach ($addr_ as $key => $value) {
            $id_ = $value->c_addr_id == 0 ? -999 : $value->c_addr_id;
            $item = [$id_, $this->addr_str($value->c_addr_id)];
            $addr_str[$key] = $item;
        }
        $event_str = null;
        if ($row->c_event_code || $row->c_event_code === 0) {
            $text_ = EventCode::find($row->c_event_code);
            $event_str = $text_->c_event_code." ".$text_->c_event_name_chn." ".$text_->c_event_name;
        }

        return ['row' => $row, 'text_str' => $text_str, 'addr_str' => $addr_str, 'event_str' => $event_str];
    }

    public function eventUpdateById(Request $request, $id, $id_) {
        // $id = c_personid, $id_ = "c_sequence-c_event_code"（新格式）或 "c_sequence"（舊格式）
        $parts = explode("-", (string) $id_);
        $c_sequence = $parts[0];
        $c_event_code = $parts[1] ?? null;

        $data = $request->all();
        $data = $this->formatSelect($data);

        // 先獲取原始記錄，用於刪除舊的 EVENTS_ADDR
        $oriQuery = DB::table('EVENTS_DATA')
            ->where('c_personid', $id)
            ->where('c_sequence', $c_sequence);
        if ($c_event_code !== null) {
            $oriQuery->where('c_event_code', $c_event_code);
        }
        $ori = $oriQuery->first();

        // 使用原始值刪除舊地址，再用新值插入新地址
        // 這樣即使用戶修改了 c_sequence 或 c_event_code，也不會留下孤兒記錄
        $this->updateAddrEvent(
            $data['c_addr_id'],
            $id,
            $ori->c_sequence,
            $ori->c_event_code,
            $data['c_sequence'],
            $data['c_event_code']
        );

        $data = Arr::except($data, ['_method', '_token', 'c_addr_id']);
        $data['c_intercalary'] = (int)($data['c_intercalary']);
        $data = (new ToolsRepository())->timestamp($data);
        $updateQuery = DB::table('EVENTS_DATA')
            ->where('c_personid', $id)
            ->where('c_sequence', $c_sequence);
        if ($c_event_code !== null) {
            $updateQuery->where('c_event_code', $c_event_code);
        }
        $updateQuery->update($data);

        $operation = (new OperationRepository())->store(Auth::id(), $id, 3, 'EVENTS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $id,
            'c_sequence' => $data['c_sequence'],
            'c_event_code' => $data['c_event_code'],
        ]), $data, $ori);
        (new AuditLogService())->write(
            'EVENTS_DATA',
            'UPDATE',
            [
                'c_personid' => $id,
                'c_sequence' => $data['c_sequence'],
                'c_event_code' => $data['c_event_code'],
            ],
            (new AuditLogService())->normalizeRow($ori),
            $data,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        return ['c_sequence' => $data['c_sequence'], 'c_event_code' => $data['c_event_code']];
    }

    public function eventStoreById(Request $request, $id) {
        $data = $request->all();
        $data = $this->formatSelect($data);
        $data['c_personid'] = $id;
        $this->insertAddrEvent($data['c_addr_id'], $id, $data['c_sequence'], $data['c_event_code']);
        $data = Arr::except($data, ['_token', 'c_addr_id']);
        $data['c_intercalary'] = (int)($data['c_intercalary']);
        $data = (new ToolsRepository())->timestamp($data, true);
        DB::table('EVENTS_DATA')->insert($data);

        $operation = (new OperationRepository())->store(Auth::id(), $id, 1, 'EVENTS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $id,
            'c_sequence' => $data['c_sequence'],
            'c_event_code' => $data['c_event_code'],
        ]), $data);
        (new AuditLogService())->write(
            'EVENTS_DATA',
            'INSERT',
            [
                'c_personid' => $id,
                'c_sequence' => $data['c_sequence'],
                'c_event_code' => $data['c_event_code'],
            ],
            null,
            $data,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );

        return ['c_sequence' => $data['c_sequence'], 'c_event_code' => $data['c_event_code']];
    }

    public function eventDeleteById($id, $c_personid) {
        // $id = "c_sequence-c_event_code"（新格式）或 "c_sequence"（舊格式）
        $parts = explode("-", (string) $id);
        $c_sequence = $parts[0];
        $c_event_code = $parts[1] ?? null;

        $query = DB::table('EVENTS_DATA')
            ->where('c_personid', $c_personid)
            ->where('c_sequence', $c_sequence);
        if ($c_event_code !== null) {
            $query->where('c_event_code', $c_event_code);
        }
        $row = (clone $query)->first();
        $query->delete();

        DB::table('EVENTS_ADDR')
            ->where('c_personid', $row->c_personid)
            ->where('c_sequence', $row->c_sequence)
            ->where('c_event_code', $row->c_event_code)
            ->delete();

        $operation = (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'EVENTS_DATA', CompositePrimaryKey::buildStoredResourceId([
            'c_personid' => $c_personid,
            'c_sequence' => $row->c_sequence,
            'c_event_code' => $row->c_event_code,
        ]), $row);
        (new AuditLogService())->write(
            'EVENTS_DATA',
            'DELETE',
            [
                'c_personid' => $c_personid,
                'c_sequence' => $row->c_sequence,
                'c_event_code' => $row->c_event_code,
            ],
            (new AuditLogService())->normalizeRow($row),
            null,
            'user',
            (string) Auth::id(),
            $operation ? (string) $operation->id : null
        );
    }

    protected function addr_str($id) {
        $row = AddrCode::find($id);
        if (!$row) {
            return '';
        }
        $belongs = "";
        $originalText = $row->c_addr_id." ".$row->c_name." ".$row->c_name_chn." ".trim($belongs)." ".$row->c_firstyear."~".$row->c_lastyear;
        $add = "";
        $dy = AddrBelong::where('c_addr_id', $row->c_addr_id)->value('c_belongs_to');
        $dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
        if ($dy == null) {
            $dy = 0;
            $add = "";
        } else {
            $dy2 = AddrCode::where('c_addr_id', $dy)->value('c_name_chn');
            $add = "[[".$dy." ".$dy2."]]";
        }

        return $originalText." ".$add;
    }

    protected function insertAddrEvent(array $c_addr_id, $c_personid, $c_sequence, $c_event_code) {
        DB::table('EVENTS_ADDR')
            ->where('c_personid', $c_personid)
            ->where('c_sequence', $c_sequence)
            ->where('c_event_code', $c_event_code)
            ->delete();
        foreach ($c_addr_id as $item) {
            DB::table('EVENTS_ADDR')->insert(
                [
                    'c_personid' => $c_personid,
                    'c_sequence' => $c_sequence,
                    'c_event_code' => $c_event_code,
                    'c_addr_id' => $item == -999 ? 0 : $item,
                ]
            );
        }
    }

    protected function updateAddrEvent(
        array $c_addr_id,
        $c_personid,
        $old_sequence,
        $old_event_code,
        $new_sequence,
        $new_event_code
    ) {
        // 使用原始值刪除舊的地址記錄
        DB::table('EVENTS_ADDR')
            ->where('c_personid', $c_personid)
            ->where('c_sequence', $old_sequence)
            ->where('c_event_code', $old_event_code)
            ->delete();

        // 使用新值插入新的地址記錄
        foreach ($c_addr_id as $item) {
            DB::table('EVENTS_ADDR')->insert(
                [
                    'c_personid' => $c_personid,
                    'c_sequence' => $new_sequence,
                    'c_event_code' => $new_event_code,
                    'c_addr_id' => $item == -999 ? 0 : $item,
                ]
            );
        }
    }

    protected function formatSelect(array $array) {
        foreach ($array as $key => $value) {
            if ($value == -999) {
                $array[$key] = 0;
            }
        }

        return $array;
    }
}
