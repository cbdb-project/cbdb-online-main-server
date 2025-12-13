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
            ->mapWithKeys(function ($item) {
                $typeNames = [
                    1 => '新增',
                    2 => '修改',
                    3 => '刪除',
                    4 => '提案',
                ];

                return [$typeNames[$item->op_type] ?? '未知' => $item->count];
            });

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
