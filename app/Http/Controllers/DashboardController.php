<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller {
    public function index() {
        // 基础统计
        $totalPersons = DB::table('BIOG_MAIN')->count();
        $totalAltnames = DB::table('ALTNAME_DATA')->count();
        $totalOffices = DB::table('POSTED_TO_OFFICE_DATA')->count();
        $totalTexts = DB::table('BIOG_TEXT_DATA')->count();
        $totalUsers = DB::table('users')->count();
        $totalOperations = DB::table('operations')->count();

        // 近期修改统计（按提交人）
        $oneDayAgo = Carbon::now()->subDay();
        $oneWeekAgo = Carbon::now()->subWeek();
        $oneMonthAgo = Carbon::now()->subMonth();

        // 过去一天的修改统计
        $dailyStats = DB::table('operations')
            ->join('users', 'operations.user_id', '=', 'users.id')
            ->select('users.name as user_name', DB::raw('COUNT(*) as count'))
            ->where('operations.created_at', '>=', $oneDayAgo)
            ->groupBy('users.name')
            ->orderByDesc('count')
            ->get();

        // 过去一周的修改统计
        $weeklyStats = DB::table('operations')
            ->join('users', 'operations.user_id', '=', 'users.id')
            ->select('users.name as user_name', DB::raw('COUNT(*) as count'))
            ->where('operations.created_at', '>=', $oneWeekAgo)
            ->groupBy('users.name')
            ->orderByDesc('count')
            ->get();

        // 过去一个月的修改统计
        $monthlyStats = DB::table('operations')
            ->join('users', 'operations.user_id', '=', 'users.id')
            ->select('users.name as user_name', DB::raw('COUNT(*) as count'))
            ->where('operations.created_at', '>=', $oneMonthAgo)
            ->groupBy('users.name')
            ->orderByDesc('count')
            ->get();

        // 操作类型统计（过去一个月）
        $operationTypeStats = DB::table('operations')
            ->select('op_type', DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', $oneMonthAgo)
            ->groupBy('op_type')
            ->get()
            ->reduce(function ($carry, $item) {
                $typeNames = [
                    \App\Operation::TYPE_CREATE => '新增',
                    \App\Operation::TYPE_UPDATE_FULL => '修改', // full update
                    \App\Operation::TYPE_UPDATE => '修改', // partial update
                    \App\Operation::TYPE_DELETE => '刪除',
                    \App\Operation::TYPE_PROPOSAL_CREATE => '提案（新增）',
                    \App\Operation::TYPE_PROPOSAL_UPDATE => '提案（修改）',
                ];

                $typeName = $typeNames[$item->op_type] ?? '未知';
                $carry[$typeName] = ($carry[$typeName] ?? 0) + $item->count;

                return $carry;
            }, []);

        return view('dashboard.index', [
            'page_title' => '系統總覽',
            'breadcrumb_home' => 'Home',
            'totalPersons' => $totalPersons,
            'totalAltnames' => $totalAltnames,
            'totalOffices' => $totalOffices,
            'totalTexts' => $totalTexts,
            'totalUsers' => $totalUsers,
            'totalOperations' => $totalOperations,
            'dailyStats' => $dailyStats,
            'weeklyStats' => $weeklyStats,
            'monthlyStats' => $monthlyStats,
            'operationTypeStats' => $operationTypeStats,
        ]);
    }
}
