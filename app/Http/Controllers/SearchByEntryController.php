<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchByEntryController extends Controller {
    /**
     * 顯示按入仕查詢頁面
     */
    public function index() {
        return view('search-by.entry.index');
    }

    /**
     * 獲取入仕類型樹狀結構（AJAX API）
     */
    public function getEntryTypes() {
        $types = DB::table('ENTRY_TYPES')
            ->select(
                'c_entry_type',
                'c_entry_type_desc',
                'c_entry_type_desc_chn',
                'c_entry_type_parent_id',
                'c_entry_type_level',
                'c_entry_type_sortorder'
            )
            ->orderBy('c_entry_type_sortorder')
            ->orderBy('c_entry_type')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $types,
        ]);
    }

    /**
     * 根據入仕類型獲取對應的入仕代碼（AJAX API）
     */
    public function getEntryCodes(Request $request) {
        $typeId = $request->input('type_id');

        $query = DB::table('ENTRY_CODES as ec')
            ->select(
                'ec.c_entry_code',
                'ec.c_entry_desc',
                'ec.c_entry_desc_chn'
            )
            ->orderBy('ec.c_entry_code');

        // 如果指定了類型，則通過關聯表過濾
        if ($typeId) {
            $query->join('ENTRY_CODE_TYPE_REL as rel', 'ec.c_entry_code', '=', 'rel.c_entry_code')
                ->where('rel.c_entry_type', $typeId);
        }

        $codes = $query->get();

        return response()->json([
            'success' => true,
            'data' => $codes,
        ]);
    }

    /**
     * 執行搜索查詢
     */
    public function search(Request $request) {
        $request->validate([
            'entry_codes' => 'nullable|array',
            'entry_codes.*' => 'integer',
            'year_from' => 'nullable|integer',
            'year_to' => 'nullable|integer',
            'addr_id' => 'nullable|integer',
        ]);

        $entryCodes = $request->input('entry_codes', []);
        $yearFrom = $request->input('year_from');
        $yearTo = $request->input('year_to');
        $addrId = $request->input('addr_id');

        // 構建查詢
        $query = DB::table('ENTRY_DATA as ed')
            ->join('BIOG_MAIN as bm', 'ed.c_personid', '=', 'bm.c_personid')
            ->join('ENTRY_CODES as ec', 'ed.c_entry_code', '=', 'ec.c_entry_code')
            ->leftJoin('DYNASTIES as dy', 'dy.c_dy', '=', 'bm.c_dy')
            ->leftJoin('ADDR_CODES as addr', 'addr.c_addr_id', '=', 'bm.c_index_addr_id')
            ->select(
                'bm.c_personid',
                'bm.c_name_chn',
                'bm.c_name',
                'bm.c_dy',
                'dy.c_dynasty',
                'dy.c_dynasty_chn',
                'bm.c_index_year',
                'bm.c_index_addr_id',
                'addr.c_name as c_index_addr_name',
                'addr.c_name_chn as c_index_addr_chn',
                'ec.c_entry_code',
                'ec.c_entry_desc_chn',
                'ec.c_entry_desc',
                'ed.c_year',
                'ed.c_sequence'
            );

        // 過濾：入仕代碼
        if (!empty($entryCodes)) {
            $query->whereIn('ed.c_entry_code', $entryCodes);
        }

        // 過濾：年份範圍
        if ($yearFrom !== null) {
            $query->where('ed.c_year', '>=', $yearFrom);
        }
        if ($yearTo !== null) {
            $query->where('ed.c_year', '<=', $yearTo);
        }

        // 過濾：地址
        if ($addrId !== null) {
            $query->where('ed.c_entry_addr_id', $addrId);
        }

        // 執行查詢並分頁
        $results = $query
            ->orderBy('bm.c_index_year')
            ->orderBy('bm.c_personid')
            ->paginate(50)
            ->appends($request->all());

        return view('search-by.entry.results', compact('results'));
    }
}
