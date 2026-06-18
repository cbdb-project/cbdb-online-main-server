<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller {
    public function index() {
        return view('dashboard.index', array_merge([
            'page_title' => __('nav.dashboard'),
            'page_title_key' => '系統總覽',
        ], $this->buildStats()));
    }

    /**
     * Inertia + React 版（統計卡片）。
     */
    public function appIndex() {
        return Inertia::render('Dashboard/Index', $this->buildStats());
    }

    /**
     * 計算儀表板統計（基礎計數 + 近期修改 + 操作類型）。Blade 與 Inertia 共用。
     *
     * @return array<string, mixed>
     */
    protected function buildStats(): array {
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
                    \App\Models\Operation::TYPE_CREATE => __('operations.op_create'),
                    \App\Models\Operation::TYPE_UPDATE_FULL => __('operations.op_update'),
                    \App\Models\Operation::TYPE_UPDATE => __('operations.op_update'),
                    \App\Models\Operation::TYPE_DELETE => __('operations.op_delete'),
                    \App\Models\Operation::TYPE_PROPOSAL_CREATE => __('operations.op_proposal_create'),
                    \App\Models\Operation::TYPE_PROPOSAL_UPDATE => __('operations.op_proposal_update'),
                ];

                $typeName = $typeNames[$item->op_type] ?? __('common.unknown');
                $carry[$typeName] = ($carry[$typeName] ?? 0) + $item->count;

                return $carry;
            }, []);

        return [
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
        ];
    }
}
