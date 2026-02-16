<?php

namespace App\Repositories;

use App\Repositories\Concerns\DetectsModelChanges;
use App\Services\AuditLogService;
use App\Support\CompositePrimaryKey;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficePostingRepository {
    use DetectsModelChanges;

    public function officeUpdateById(Request $request, $id, $c_personid) {
        $data = $request->all();
        $_id = $data['_id'];
        $_postingid = $data['_postingid'];
        $_officeid = $data['_officeid']; //目前与officeid无关

        // 檢查地址欄位：
        // - c_addr 存在時：使用傳入的值
        // - c_addr 不存在但 c_addr_cleared == '1'：用戶清除了所有地址（視為空陣列）
        // - 兩者都不存在：用戶沒有修改地址（視為 null）
        $incomingAddr = array_key_exists('c_addr', $data)
            ? (array)$data['c_addr']
            : (($data['c_addr_cleared'] ?? '0') === '1' ? [] : null);

        $oriRecord = DB::table('POSTED_TO_OFFICE_DATA')->where([['c_office_id' , '=', $_officeid], ['c_posting_id' , '=', $_postingid]])->first();
        $ori = $oriRecord ? json_decode(json_encode($oriRecord), true) : [];

        $addressCollection = DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $_id)
            ->where('c_posting_id', $_postingid)
            ->pluck('c_addr_id');

        $existingAddresses = $addressCollection ? $addressCollection->all() : [];
        // 地址變更檢測邏輯：
        // - $incomingAddr === null: 用戶沒有修改地址（表單未傳送 c_addr）
        // - $incomingAddr === []: 用戶明確清除所有地址（表單傳送空 c_addr）
        // - $incomingAddr 有值: 用戶修改了地址列表
        $hasAddressChange = $incomingAddr !== null
            && $this->selectionListHasChanges($incomingAddr, $existingAddresses, -999);

        $data = Arr::except($data, ['_method', '_token', 'action', '__proposal_comment', 'c_addr', 'c_addr_cleared', '_id', '_postingid', '_officeid', 'ai_fill_log_id']);
        $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
        $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);
        $data['c_office_id'] = $data['c_office_id'] == -999 ? '0' : $data['c_office_id'];
        //$data['c_inst_code'] = $data['c_inst_code'] == -999 ? '0' : $data['c_inst_code'];
        $data['c_source'] = $data['c_source'] == -999 ? '0' : $data['c_source'];

        $hasPostingChange = $this->hasMeaningfulChanges($data, $ori, ['c_modified_by', 'c_modified_date']);

        if (!$hasPostingChange && !$hasAddressChange) {
            return [
                'id' => CompositePrimaryKey::buildStoredResourceId([
                    'c_office_id' => $_officeid,
                    'c_posting_id' => $_postingid,
                ]),
                'no_changes' => true,
            ];
        }

        return DB::transaction(function () use ($data, $ori, $c_personid, $_officeid, $_postingid, $hasPostingChange, $hasAddressChange, $incomingAddr, $_id, $existingAddresses) {
            $auditLog = new AuditLogService();
            $previousOfficeId = (int) ($ori['c_office_id'] ?? $_officeid);
            $currentOfficeId = $previousOfficeId;
            $officeOperation = null;
            if ($hasPostingChange) {
                $timestamped = (new ToolsRepository())->timestamp($data);

                DB::table('POSTED_TO_OFFICE_DATA')
                    ->where([['c_office_id' , '=', $_officeid], ['c_posting_id' , '=', $_postingid]])
                    ->update($timestamped);

                $currentOfficeId = (int) ($timestamped['c_office_id'] ?? $currentOfficeId);

                $officeOperation = (new OperationRepository())->store(
                    Auth::id(),
                    $c_personid,
                    3,
                    'POSTED_TO_OFFICE_DATA',
                    CompositePrimaryKey::buildStoredResourceId([
                        'c_office_id' => $currentOfficeId,
                        'c_posting_id' => $_postingid,
                    ]),
                    $timestamped,
                    $ori
                );

                $updatedOfficeRow = DB::table('POSTED_TO_OFFICE_DATA')
                    ->where([['c_office_id' , '=', $currentOfficeId], ['c_posting_id' , '=', $_postingid]])
                    ->first();
                $updatedOfficeData = $auditLog->normalizeRow($updatedOfficeRow);
                $officeRowPk = $auditLog->buildRowPkFromData('POSTED_TO_OFFICE_DATA', $updatedOfficeData);

                $auditLog->logChange(
                    'POSTED_TO_OFFICE_DATA',
                    'UPDATE',
                    $officeRowPk,
                    $ori,
                    $updatedOfficeData,
                    $officeOperation ? (string) $officeOperation->id : null
                );
            }

            $shouldUpdateAddress = $hasAddressChange || $previousOfficeId !== $currentOfficeId;

            //20251204 issues-487，用戶在「修改」頁面保存時，若地名資訊（POSTED_TO_ADDR_DATA.c_addr_id）並沒有改變，用戶在保存的時候，則不會修改 POSTED_TO_ADDR_DATA 的 c_modified_by, c_modified_date 欄位。
            //有修改地名資訊時$shouldUpdateAddress = true
            //dd($shouldUpdateAddress);

            $c_created_by = Auth::user()->name;
            $c_created_date = Carbon::now();

            DB::table('POSTING_DATA')->where([
                    ['c_personid', '=', $_id],
                    ['c_posting_id', '=', $_postingid],
                ])->update(['c_modified_by' => $c_created_by, 'c_modified_date' => $c_created_date]);

            $addrBeforeAuditRows = [];
            if ($shouldUpdateAddress) {
                $addrBeforeAuditRows = DB::table('POSTED_TO_ADDR_DATA')
                    ->where('c_personid', $_id)
                    ->where('c_posting_id', $_postingid)
                    ->get();

                $beforeRows = DB::table('POSTED_TO_ADDR_DATA')
                    ->where('c_personid', $_id)
                    ->where('c_posting_id', $_postingid)
                    ->where('c_office_id', $previousOfficeId)
                    ->get()
                    ->map(function ($row) {
                        return [
                            'c_personid' => (int) $row->c_personid,
                            'c_posting_id' => (int) $row->c_posting_id,
                            'c_office_id' => (int) $row->c_office_id,
                            'c_addr_id' => (int) $row->c_addr_id,
                        ];
                    })
            ->all();

                //dd($previousOfficeId, $currentOfficeId, $beforeRows);

                // 先計算最終要保留的地址列表（用於衝突檢測和後續操作）
                // - $incomingAddr === null: 保留現有地址（用戶沒有修改）
                // - $incomingAddr 有值（包含空陣列）: 使用用戶指定的地址列表
                // 將 -999 正規化為 0（-999 是表單中「未選擇」的 sentinel 值，0 代表「未詳」地址）
                $sourceAddresses = $incomingAddr !== null ? $incomingAddr : $existingAddresses;
                $addressesForInsert = array_map(function ($v) {
                    $v = (int) $v;

                    return $v === -999 ? 0 : $v;
                }, $sourceAddresses);

                // 當 c_office_id 改變時，將現有地址記錄遷移到新的 office_id
                // 使用 UPDATE 而非 DELETE，避免地址記錄丟失
                if ($previousOfficeId !== $currentOfficeId && !empty($beforeRows)) {
                    // 檢查目標 office_id 下是否已存在「將保留的地址」記錄（主鍵衝突檢測）
                    // POSTED_TO_ADDR_DATA 主鍵為 (c_addr_id, c_office_id, c_posting_id)
                    // 只檢查「將保留/新增」的地址，允許用戶通過移除衝突地址來解決問題
                    // 這裡額外加上 c_personid 條件是為了縮小查詢範圍，確保只檢查同一人的記錄
                    // $addressesForInsert 已正規化（-999 → 0）
                    $addressesToKeep = $addressesForInsert;
                    if (!empty($addressesToKeep)) {
                        $conflictingRecords = DB::table('POSTED_TO_ADDR_DATA')
                            ->where('c_personid', $_id)
                            ->where('c_posting_id', $_postingid)
                            ->where('c_office_id', $currentOfficeId)
                            ->whereIn('c_addr_id', $addressesToKeep)
                            ->count();

                        if ($conflictingRecords > 0) {
                            throw ValidationException::withMessages([
                                'c_office_id' => "無法修改官名：目標官名（c_office_id={$currentOfficeId}）下已存在相同的地址記錄，" .
                                    '可能會導致數據衝突。請先檢查並處理 POSTED_TO_ADDR_DATA 表中的異常數據。',
                            ]);
                        }
                    }

                    // 遷移地址記錄到新的 office_id（只遷移將保留的地址）
                    // 將被移除的地址不需要遷移，會在後續的差異比對中被刪除
                    if (!empty($addressesToKeep)) {
                        DB::table('POSTED_TO_ADDR_DATA')
                            ->where('c_personid', $_id)
                            ->where('c_posting_id', $_postingid)
                            ->where('c_office_id', $previousOfficeId)
                            ->whereIn('c_addr_id', $addressesToKeep)
                            ->update([
                                'c_office_id' => $currentOfficeId,
                                'c_modified_by' => $c_created_by,
                                'c_modified_date' => $c_created_date,
                            ]);
                    }
                    // 更新 beforeRows 中已遷移地址的 c_office_id 以反映遷移後的狀態
                    $beforeRows = array_map(function ($row) use ($currentOfficeId, $addressesToKeep) {
                        if (in_array($row['c_addr_id'], $addressesToKeep)) {
                            $row['c_office_id'] = $currentOfficeId;
                        }

                        return $row;
                    }, $beforeRows);
                }

                //比對修改前後的Addr陣列，刪除更新時被移除的addr。
                $beforeAddressesForUpdate = [];
                $beforeRowsByAddrId = [];  // 用於記錄每個地址的 office_id
                foreach ($beforeRows as $addr_v) {
                    $beforeAddressesForUpdate[] = $addr_v['c_addr_id'];
                    $beforeRowsByAddrId[$addr_v['c_addr_id']] = $addr_v['c_office_id'];
                }
                //dd($beforeRows, $addressesForInsert);
                //dd($beforeAddressesForUpdate, $addressesForInsert);
                $oldHave_diff = array_diff($beforeAddressesForUpdate, $addressesForInsert);
                $newHave_diff = array_diff($addressesForInsert, $beforeAddressesForUpdate);
                //dd($oldHave_diff);
                //dd($newHave_diff);

                //比對結束，刪除更新時被移除的addr。
                // 使用 beforeRows 中記錄的 office_id，因為未遷移的地址仍在原 office_id 下
                foreach ($oldHave_diff as $addr_v) {
                    $addrOfficeId = $beforeRowsByAddrId[$addr_v] ?? $currentOfficeId;
                    DB::table('POSTED_TO_ADDR_DATA')
                        ->where('c_personid', $_id)
                        ->where('c_posting_id', $_postingid)
                        ->where('c_office_id', $addrOfficeId)
                        ->where('c_addr_id', $addr_v)
                        ->delete();
                }

                //比對結束，新增後來新加的addr。
                // 注意：使用 $currentOfficeId 確保新地址插入到正確的 office_id
                // $addr_v 已經正規化（-999 → 0），直接使用即可
                foreach ($newHave_diff as $addr_v) {
                    DB::table('POSTED_TO_ADDR_DATA')->insert([
                        'c_personid' => $_id,
                        'c_posting_id' => $_postingid,
                        'c_office_id' => $currentOfficeId,
                        'c_addr_id' => $addr_v,
                        'c_created_by' => $c_created_by,
                        'c_created_date' => $c_created_date,
                        'c_modified_by' => $c_created_by,
                        'c_modified_date' => $c_created_date,
                    ]);
                }


                $this->updateAddr($addressesForInsert, $_id, $_postingid, $currentOfficeId, $c_created_by, $c_created_date);

                $afterRows = DB::table('POSTED_TO_ADDR_DATA')
                    ->where('c_personid', $_id)
                    ->where('c_posting_id', $_postingid)
                    ->where('c_office_id', $currentOfficeId)
                    ->get()
                    ->map(function ($row) {
                        return [
                            'c_personid' => (int) $row->c_personid,
                            'c_posting_id' => (int) $row->c_posting_id,
                            'c_office_id' => (int) $row->c_office_id,
                            'c_addr_id' => (int) $row->c_addr_id,
                        ];
                    })
                    ->all();

                $addrResourceId = CompositePrimaryKey::buildStoredResourceId([
                    'c_office_id' => $currentOfficeId,
                    'c_posting_id' => $_postingid,
                ]);

                $addrOperation = $officeOperation;
                if ($addrOperation === null) {
                    $addrOperation = (new OperationRepository())->store(
                        Auth::id(),
                        $c_personid,
                        3,
                        'POSTED_TO_ADDR_DATA',
                        $addrResourceId,
                        ['rows' => $afterRows],
                        ['rows' => $beforeRows]
                    );
                }

                $addrAfterAuditRows = DB::table('POSTED_TO_ADDR_DATA')
                    ->where('c_personid', $_id)
                    ->where('c_posting_id', $_postingid)
                    ->get();

                $beforeMap = [];
                foreach ($addrBeforeAuditRows as $row) {
                    $rowData = $auditLog->normalizeRow($row);
                    $rowPk = $auditLog->buildRowPkFromData('POSTED_TO_ADDR_DATA', $rowData);
                    $rowPkText = $auditLog->buildRowPkText('POSTED_TO_ADDR_DATA', $rowPk);
                    $beforeMap[$rowPkText] = ['pk' => $rowPk, 'row' => $rowData];
                }

                $afterMap = [];
                foreach ($addrAfterAuditRows as $row) {
                    $rowData = $auditLog->normalizeRow($row);
                    $rowPk = $auditLog->buildRowPkFromData('POSTED_TO_ADDR_DATA', $rowData);
                    $rowPkText = $auditLog->buildRowPkText('POSTED_TO_ADDR_DATA', $rowPk);
                    $afterMap[$rowPkText] = ['pk' => $rowPk, 'row' => $rowData];
                }

                $addrOperationId = $addrOperation ? (string) $addrOperation->id : null;
                $allKeys = array_unique(array_merge(array_keys($beforeMap), array_keys($afterMap)));
                foreach ($allKeys as $key) {
                    $beforeEntry = $beforeMap[$key] ?? null;
                    $afterEntry = $afterMap[$key] ?? null;

                    if ($beforeEntry && !$afterEntry) {
                        $auditLog->logChange(
                            'POSTED_TO_ADDR_DATA',
                            'DELETE',
                            $beforeEntry['pk'],
                            $beforeEntry['row'],
                            null,
                            $addrOperationId
                        );

                        continue;
                    }

                    if (!$beforeEntry && $afterEntry) {
                        $auditLog->logChange(
                            'POSTED_TO_ADDR_DATA',
                            'INSERT',
                            $afterEntry['pk'],
                            null,
                            $afterEntry['row'],
                            $addrOperationId
                        );

                        continue;
                    }

                    if ($beforeEntry && $afterEntry && $beforeEntry['row'] != $afterEntry['row']) {
                        $auditLog->logChange(
                            'POSTED_TO_ADDR_DATA',
                            'UPDATE',
                            $afterEntry['pk'],
                            $beforeEntry['row'],
                            $afterEntry['row'],
                            $addrOperationId
                        );
                    }
                }
            }

            $updateResourceId = CompositePrimaryKey::buildStoredResourceId([
                'c_office_id' => $currentOfficeId,
                'c_posting_id' => $_postingid,
            ]);

            return [
                'id' => $updateResourceId,
                'no_changes' => false,
            ];
        });
    }

    public function officeStoreById(Request $request, $id) {
        return DB::transaction(function () use ($request, $id) {
            $auditLog = new AuditLogService();
            $payload = $request->all();
            $c_addr = $payload['c_addr'] ?? [];
            $data = Arr::except($payload, ['_token', 'action', '__proposal_comment', 'c_addr', 'c_addr_cleared', 'ai_fill_log_id']);

            $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
            $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);

            $lastPostingId = DB::table('POSTING_DATA')
                ->lockForUpdate()
                ->orderByDesc('c_posting_id')
                ->value('c_posting_id');
            $data['c_posting_id'] = ((int) $lastPostingId) + 1;
            $data['c_personid'] = $id;

            //將操作新增的使用者與當下時間紀錄為c_created_by與c_created_date。
            $c_created_by = Auth::user()->name;
            $c_created_date = Carbon::now();
            $data['c_created_by'] = $c_created_by;
            $data['c_created_date'] = $c_created_date;

            DB::table('POSTING_DATA')->insert([
                'c_personid' => $data['c_personid'],
                'c_posting_id' => $data['c_posting_id'],
                'c_created_by' => $data['c_created_by'],
                'c_created_date' => $data['c_created_date'],
            ]);

            $this->insertAddr($c_addr, $id, $data['c_posting_id'], $data['c_office_id'], $c_created_by, $c_created_date);

            $data = (new ToolsRepository())->timestamp($data, true);
            DB::table('POSTED_TO_OFFICE_DATA')->insert($data);

            $officeOperation = (new OperationRepository())->store(Auth::id(), $id, 1, 'POSTED_TO_OFFICE_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_office_id' => $data['c_office_id'],
                'c_posting_id' => $data['c_posting_id'],
            ]), $data);

            $officeRow = DB::table('POSTED_TO_OFFICE_DATA')->where([
                ['c_office_id', '=', $data['c_office_id']],
                ['c_posting_id', '=', $data['c_posting_id']],
            ])->first();
            $officeRowData = $auditLog->normalizeRow($officeRow);
            $officeRowPk = $auditLog->buildRowPkFromData('POSTED_TO_OFFICE_DATA', $officeRowData);
            $auditLog->logChange(
                'POSTED_TO_OFFICE_DATA',
                'INSERT',
                $officeRowPk,
                null,
                $officeRowData,
                $officeOperation ? (string) $officeOperation->id : null
            );

            $addressRows = DB::table('POSTED_TO_ADDR_DATA')
                ->where('c_personid', $id)
                ->where('c_posting_id', $data['c_posting_id'])
                ->where('c_office_id', $data['c_office_id'])
                ->get()
                ->map(function ($row) {
                    return [
                        'c_personid' => (int) $row->c_personid,
                        'c_posting_id' => (int) $row->c_posting_id,
                        'c_office_id' => (int) $row->c_office_id,
                        'c_addr_id' => (int) $row->c_addr_id,
                    ];
                })
                ->values()
                ->all();

            $officeResourceId = CompositePrimaryKey::buildStoredResourceId([
                'c_office_id' => $data['c_office_id'],
                'c_posting_id' => $data['c_posting_id'],
            ]);

            if (!empty($addressRows)) {
                $addrOperation = (new OperationRepository())->store(
                    Auth::id(),
                    $id,
                    1,
                    'POSTED_TO_ADDR_DATA',
                    $officeResourceId,
                    ['rows' => $addressRows]
                );

                $addrAuditRows = DB::table('POSTED_TO_ADDR_DATA')
                    ->where('c_personid', $id)
                    ->where('c_posting_id', $data['c_posting_id'])
                    ->where('c_office_id', $data['c_office_id'])
                    ->get();

                $addrOperationId = $addrOperation ? (string) $addrOperation->id : null;
                foreach ($addrAuditRows as $row) {
                    $rowData = $auditLog->normalizeRow($row);
                    $rowPk = $auditLog->buildRowPkFromData('POSTED_TO_ADDR_DATA', $rowData);
                    $auditLog->logChange(
                        'POSTED_TO_ADDR_DATA',
                        'INSERT',
                        $rowPk,
                        null,
                        $rowData,
                        $addrOperationId
                    );
                }
            }

            return $officeResourceId;
        });
    }

    public function officeCloneById($id, $cpk) {
        return DB::transaction(function () use ($id, $cpk) {
            $temp = explode('-', $cpk);
            $sourceRow = DB::table('POSTED_TO_OFFICE_DATA')->where([
                ['c_office_id', '=', $temp[0]],
                ['c_posting_id', '=', $temp[1]],
            ])->first();

            if (!$sourceRow) {
                return null;
            }

            $payload = json_decode(json_encode($sourceRow), true);
            $c_addr = DB::table('POSTED_TO_ADDR_DATA')
                ->where('c_personid', $sourceRow->c_personid)
                ->where('c_posting_id', $sourceRow->c_posting_id)
                ->pluck('c_addr_id')
                ->all();

            $data = Arr::except($payload, ['_token', 'c_addr']);
            $data['c_fy_intercalary'] = (int)($data['c_fy_intercalary']);
            $data['c_ly_intercalary'] = (int)($data['c_ly_intercalary']);

            $lastPostingId = DB::table('POSTING_DATA')
                ->lockForUpdate()
                ->orderByDesc('c_posting_id')
                ->value('c_posting_id');
            $data['c_posting_id'] = ((int) $lastPostingId) + 1;
            $data['c_personid'] = $id;

            $c_created_by = Auth::user()->name;
            $c_created_date = Carbon::now();
            $data['c_modified_by'] = $data['c_modified_date'] = '';
            $data['c_created_by'] = $c_created_by;
            $data['c_created_date'] = $c_created_date;

            DB::table('POSTING_DATA')->insert([
                'c_personid' => $data['c_personid'],
                'c_posting_id' => $data['c_posting_id'],
                'c_created_by' => $data['c_created_by'],
                'c_created_date' => $data['c_created_date'],
            ]);

            $this->insertAddr($c_addr, $id, $data['c_posting_id'], $data['c_office_id'], $c_created_by, $c_created_date);

            $data = (new ToolsRepository())->timestamp($data, true);
            DB::table('POSTED_TO_OFFICE_DATA')->insert($data);

            $officeResourceId = CompositePrimaryKey::buildStoredResourceId([
                'c_office_id' => $data['c_office_id'],
                'c_posting_id' => $data['c_posting_id'],
            ]);

            (new OperationRepository())->store(Auth::id(), $id, 1, 'POSTED_TO_OFFICE_DATA', $officeResourceId, $data);

            $addressRows = DB::table('POSTED_TO_ADDR_DATA')
                ->where('c_personid', $id)
                ->where('c_posting_id', $data['c_posting_id'])
                ->where('c_office_id', $data['c_office_id'])
                ->get()
                ->map(function ($row) {
                    return [
                        'c_personid' => (int) $row->c_personid,
                        'c_posting_id' => (int) $row->c_posting_id,
                        'c_office_id' => (int) $row->c_office_id,
                        'c_addr_id' => (int) $row->c_addr_id,
                    ];
                })
                ->values()
                ->all();

            if (!empty($addressRows)) {
                (new OperationRepository())->store(
                    Auth::id(),
                    $id,
                    1,
                    'POSTED_TO_ADDR_DATA',
                    $officeResourceId,
                    ['rows' => $addressRows]
                );
            }

            return $officeResourceId;
        });
    }

    public function officeDeleteById($id, $c_personid) {
        DB::transaction(function () use ($id, $c_personid) {
            $auditLog = new AuditLogService();
            $addr_l = explode('-', $id);
            $row = DB::table('POSTED_TO_OFFICE_DATA')
                ->where([
                    ['c_office_id', '=', $addr_l[0]],
                    ['c_posting_id', '=', $addr_l[1]],
                    ['c_personid', '=', $c_personid],
                ])
                ->first();

            if (!$row) {
                return;
            }

            $addrRows = DB::table('POSTED_TO_ADDR_DATA')
                ->where('c_office_id', $addr_l[0])
                ->where('c_posting_id', $addr_l[1])
                ->where('c_personid', $c_personid)
                ->get();

            DB::table('POSTED_TO_OFFICE_DATA')
                ->where([
                    ['c_office_id', '=', $addr_l[0]],
                    ['c_posting_id', '=', $addr_l[1]],
                    ['c_personid', '=', $c_personid],
                ])
                ->delete();
            DB::table('POSTED_TO_ADDR_DATA')
                ->where('c_office_id', $addr_l[0])
                ->where('c_posting_id', $row->c_posting_id)
                ->where('c_personid', $c_personid)
                ->delete();
            DB::table('POSTING_DATA')->where('c_posting_id', $row->c_posting_id)->delete();

            $officeOperation = (new OperationRepository())->store(Auth::id(), $c_personid, 4, 'POSTED_TO_OFFICE_DATA', CompositePrimaryKey::buildStoredResourceId([
                'c_office_id' => $addr_l[0],
                'c_posting_id' => $addr_l[1],
            ]), $row);

            $officeRowData = $auditLog->normalizeRow($row);
            $officeRowPk = $auditLog->buildRowPkFromData('POSTED_TO_OFFICE_DATA', $officeRowData);
            $operationId = $officeOperation ? (string) $officeOperation->id : null;
            $auditLog->logChange(
                'POSTED_TO_OFFICE_DATA',
                'DELETE',
                $officeRowPk,
                $officeRowData,
                null,
                $operationId
            );

            foreach ($addrRows as $addrRow) {
                $addrRowData = $auditLog->normalizeRow($addrRow);
                $addrRowPk = $auditLog->buildRowPkFromData('POSTED_TO_ADDR_DATA', $addrRowData);
                $auditLog->logChange(
                    'POSTED_TO_ADDR_DATA',
                    'DELETE',
                    $addrRowPk,
                    $addrRowData,
                    null,
                    $operationId
                );
            }
        });
    }

    protected function insertAddr(array $c_addr, $_id, $_postingid, $_officeid, $c_created_by = '', $c_created_date = '') {
        DB::table('POSTED_TO_ADDR_DATA')
            ->where('c_personid', $_id)
            ->where('c_posting_id', $_postingid)
            ->where('c_office_id', $_officeid)
            ->delete();
        foreach ($c_addr as $item) {
            DB::table('POSTED_TO_ADDR_DATA')->insert(
                [
                    'c_personid' => $_id,
                    'c_posting_id' => $_postingid,
                    'c_office_id' => $_officeid,
                    'c_addr_id' => $item == -999 ? 0 : $item,
                    'c_created_by' => $c_created_by,
                    'c_created_date' => $c_created_date,
                ]
            );
        }
    }

    protected function updateAddr(array $c_addr, $_id, $_postingid, $_officeid, $c_created_by = '', $c_created_date = '') {
        foreach ($c_addr as $item) {
            $addr = '';
            $addr = DB::table('POSTED_TO_ADDR_DATA')->where([
                ['c_personid', '=', $_id],
                ['c_posting_id', '=', $_postingid],
                ['c_office_id', '=', $_officeid],
                ['c_addr_id', '=', $item],
            ])->get();

            if (!empty($addr)) {
                //有資料就更新
                DB::table('POSTED_TO_ADDR_DATA')->where([
                    ['c_personid', '=', $_id],
                    ['c_posting_id', '=', $_postingid],
                    ['c_office_id', '=', $_officeid],
                    ['c_addr_id', '=', $item],
                ])
                    ->update(['c_modified_by' => $c_created_by, 'c_modified_date' => $c_created_date]);
            }
        }
    }
}
