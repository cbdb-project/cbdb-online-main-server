<?php

namespace App\Support;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * 側邊欄導覽「單一真實來源」（single source of truth）。
 *
 * Blade（layouts/partials/sidebar-nav）與 React（AppShell 側邊欄）皆從這裡
 * 取得相同的結構化導覽樹，避免兩套側邊欄漂移（見計畫 §五 / §五之二）。
 *
 * 設計：
 *  - 角色閘門在後端套用（依 User 的 is...()/can...() 方法），回傳「使用者可見」的樹；
 *    前端閘門僅 UX，後端路由仍須獨立授權。
 *  - active-state 不再靠中文標籤字串比對：每個節點帶 active.patterns（route 名稱
 *    glob，供 React 以目前路由 / Blade 以 request()->routeIs() 判定），同時保留
 *    active.pages（既有 $page_title 字串）以相容尚未遷移的 Blade 頁面。
 *  - 連結指向受 feature flag 控制（config/migration_flags.php）：flag='new' 且新
 *    路由存在時指向新頁，否則指向舊頁。flag 預設 'old'，只能由人 flip。
 *
 * 節點結構：
 *  [
 *    'key'      => string,                 // 穩定識別（React key / 測試）
 *    'label'    => string,                 // 翻譯 key（__()）
 *    'icon'     => string,                 // Font Awesome class
 *    'href'     => ?string,                // 連結；tree 父節點可為 null('#')
 *    'suffix'   => ?string,                // 次要說明（如 codes 的表名）
 *    'badge'    => ?array,                 // ['label'=>翻譯key,'variant'=>..., 'show'=>bool]
 *    'active'   => ['pages'=>string[], 'patterns'=>string[]],
 *    'children' => array,                  // 子節點（tree）
 *  ]
 */
class Navigation {
    /**
     * 取得指定使用者可見的導覽樹（已套用角色閘門、已解析 flag 連結）。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tree(?User $user): array {
        $nodes = self::definition($user);

        // 套用角色閘門：保留 gate 通過的節點，並遞迴過濾子節點。
        return self::filter($nodes, $user);
    }

    /**
     * 原始定義（含 gate 閉包），未過濾。
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function definition(?User $user): array {
        $isActive = $user && $user->isActive();
        $isSuperAdmin = $isActive && $user->isSuperAdmin();

        return [
            self::item(
                'dashboard',
                'nav.dashboard',
                'fas fa-tachometer-alt',
                self::url('dashboard', 'dashboard', 'app.dashboard'),
                ['pages' => ['系統總覽'], 'patterns' => ['dashboard', 'app.dashboard']]
            ),

            self::item(
                'person',
                'nav.person_editing',
                'fas fa-landmark',
                self::url('basicinformation.index', 'basicinformation.index', 'app.basicinformation.index'),
                ['pages' => ['Basicinformation'], 'patterns' => ['basicinformation.index', 'app.basicinformation.index']]
            ),

            self::item(
                'operations',
                'nav.recent_operations',
                'fas fa-clipboard-list',
                self::routeUrl('operations.index'),
                ['pages' => ['NewUpdate'], 'patterns' => []]
            ),

            self::item(
                'proposals',
                'nav.recent_proposals',
                'fas fa-clipboard-check',
                self::routeUrl('operations.index', ['proposals_only' => 1]),
                ['pages' => ['OperationsProposals'], 'patterns' => []],
                self::pendingProposalsBadge($user)
            ),

            // 全部表格（Codes）
            self::tree_('codes', 'nav.all_tables', 'fa fa-database', self::codesChildren(), self::url('codes', 'codes.index', 'app.codes.index')),

            // 檢視表（Views）。'地址層級檢視' 為舊 $viewPages 殘留（無對應子連結，
            // 目前無頁面設定此 $page_title），保留以維持選單展開的完全一致。
            self::tree_('views', 'nav.views', 'fa fa-th-list', self::viewsChildren(), self::routeUrl('view.index'), null, ['地址層級檢視']),

            // 專家工具（需活躍）
            self::tree_('expert', 'nav.expert_tools', 'fas fa-flask', [
                self::item(
                    'query-playground',
                    'nav.sql_query_playground',
                    'fas fa-terminal',
                    self::routeUrl('app.query-playground.index'),
                    ['pages' => ['Query Playground'], 'patterns' => ['app.query-playground.*']]
                ),
            ], self::routeUrl('app.query-playground.index'), fn () => $isActive),

            // 暫不公開工具（需 superadmin）
            self::tree_('not-public', 'nav.not_public_tools', 'fas fa-lock', [
                self::item(
                    'crowdsourcing',
                    'nav.crowdsourcing_records',
                    'fas fa-users-cog',
                    self::routeUrl('crowdsourcing.index'),
                    ['pages' => ['Crowdsourcing'], 'patterns' => ['crowdsourcing.*']]
                ),
                self::item(
                    'person-browser',
                    'nav.person_browser',
                    'fas fa-user-friends',
                    self::routeUrl('app.person-browser.index'),
                    ['pages' => ['人物瀏覽'], 'patterns' => ['app.person-browser.*']]
                ),
                self::item(
                    'search-by-entry',
                    'nav.search_by_entry',
                    'fas fa-search',
                    self::routeUrl('app.search-by.entry.index'),
                    ['pages' => ['按入仕查詢'], 'patterns' => ['app.search-by.entry.*']]
                ),
                self::item(
                    'maps',
                    'nav.historical_maps',
                    'fas fa-map',
                    self::routeUrl('app.maps.index'),
                    ['pages' => ['歷史地圖'], 'patterns' => ['app.maps.*']]
                ),
            ], null, fn () => $isSuperAdmin),

            // 管理工具（需 superadmin）
            self::tree_('admin', 'nav.admin_tools', 'fas fa-tools', [
                self::item(
                    'manage',
                    'nav.user_management',
                    'fas fa-user-cog',
                    self::routeUrl('manage.index'),
                    ['pages' => ['用戶管理'], 'patterns' => ['manage.index']]
                ),
                self::item(
                    'nl-query-logs',
                    'admin.nl_query_logs',
                    'fas fa-comments',
                    self::url('query-playground.nl-query-logs', 'query-playground.nl-query-logs', 'app.query-playground.nl-query-logs'),
                    ['pages' => ['NL Query Logs'], 'patterns' => ['app.query-playground.nl-query-logs']]
                ),
                self::item(
                    'ai-fill-logs',
                    'admin.ai_fill_logs',
                    'fas fa-robot',
                    self::url('admin.ai-fill-logs', 'admin.ai-fill-logs', 'app.admin.ai-fill-logs'),
                    ['pages' => ['AI 填充日誌'], 'patterns' => ['app.admin.ai-fill-logs']]
                ),
                self::item(
                    'audit-logs',
                    'admin.audit_logs',
                    'fas fa-clipboard-check',
                    self::url('admin.audit-logs', 'admin.audit-logs', 'app.admin.audit-logs'),
                    ['pages' => ['審計日誌'], 'patterns' => ['app.admin.audit-logs']]
                ),
                self::item(
                    'explain-sql',
                    'admin.sql_explain',
                    'fa fa-search',
                    self::url('admin.explain-sql', 'admin.explainsql', 'app.admin.explainsql'),
                    ['pages' => ['SQL 執行計畫'], 'patterns' => ['app.admin.explainsql']]
                ),
                self::item(
                    'batch-books',
                    'admin.batch_load_books',
                    'fa fa-upload',
                    self::routeUrl('admin.batch-load-book-titles'),
                    ['pages' => ['批次匯入書稿資料'], 'patterns' => []]
                ),
                self::item(
                    'batch-offices',
                    'admin.batch_load_offices',
                    'fa fa-briefcase',
                    self::routeUrl('admin.batch-load-offices'),
                    ['pages' => ['批次匯入官職'], 'patterns' => []]
                ),
                self::item(
                    'batch-social',
                    'admin.batch_load_social_institutes',
                    'fa fa-university',
                    self::routeUrl('admin.batch-load-social-institutes'),
                    ['pages' => ['批次匯入社會機構'], 'patterns' => []]
                ),
                self::item(
                    'wiki-maintenance',
                    'admin.wiki_maintenance',
                    'fab fa-wikipedia-w',
                    self::routeUrl('admin.wiki-maintenance'),
                    ['pages' => ['Wiki 對照資料維護'], 'patterns' => []]
                ),
                self::item(
                    'table-maintenance',
                    'admin.table_maintenance',
                    'fa fa-database',
                    self::routeUrl('admin.cbdb-table-maintenance'),
                    ['pages' => ['CBDB 內部表維護'], 'patterns' => []]
                ),
                self::item(
                    'unidirectional-repair',
                    'admin.unidirectional_repair',
                    'fas fa-exchange-alt',
                    self::routeUrl('admin.unidirectional-relationship-repair'),
                    ['pages' => ['單向關係修復'], 'patterns' => []]
                ),
                self::item(
                    'merge-preview',
                    'admin.merge_records',
                    'ion ion-shuffle',
                    self::routeUrl('merge-preview.index'),
                    ['pages' => ['MergePreview'], 'patterns' => []]
                ),
            ], self::routeUrl('manage.index'), fn () => $isSuperAdmin),
        ];
    }

    /**
     * 全部表格子選單（Codes）。連結為既有 /codes/* 路徑。
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function codesChildren(): array {
        return [
            self::item(
                'codes-home',
                'nav.all_tables_home',
                'fas fa-th-list',
                self::url('codes', 'codes.index', 'app.codes.index'),
                ['pages' => ['Codes', '全部表格'], 'patterns' => ['app.codes.index']]
            ),
            self::codeItem('addr-belongs', 'codes.addr_belongs_data', 'fas fa-sitemap', 'ADDR_BELONGS_DATA'),
            self::codeItem('addr-codes', 'codes.addr_codes', 'fas fa-map-marker-alt', 'ADDR_CODES'),
            self::codeItem('addresses', 'codes.addresses', 'fas fa-map', 'ADDRESSES'),
            self::codeItem('altname-codes', 'codes.altname_codes', 'fas fa-user-tag', 'ALTNAME_CODES'),
            self::codeItem('appointment-codes', 'codes.appointment_codes', 'fas fa-briefcase', 'APPOINTMENT_CODES'),
            self::codeItem('office-codes', 'codes.office_codes', 'fas fa-id-badge', 'OFFICE_CODES'),
            self::codeItem('social-institution-codes', 'codes.social_institution_codes', 'fas fa-university', 'SOCIAL_INSTITUTION_CODES'),
            self::codeItem('text-codes', 'codes.text_codes', 'fas fa-book', 'TEXT_CODES'),
            self::codeItem('text-instance-data', 'codes.text_instance_data', 'fas fa-book-open', 'TEXT_INSTANCE_DATA'),
        ];
    }

    /**
     * 檢視表子選單（Views）。
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function viewsChildren(): array {
        return [
            self::item(
                'views-overview-new',
                'nav.views_overview_new',
                'fas fa-layer-group',
                self::routeUrl('app.view.index'),
                ['pages' => [], 'patterns' => ['app.view.*']]
            ),
            self::item(
                'views-overview',
                'nav.views_overview',
                'fas fa-list-ul',
                self::routeUrl('view.index'),
                ['pages' => ['檢視表總覽'], 'patterns' => []]
            ),
            self::viewItem('altname-data', 'views.view_altname_data', 'fas fa-user-tag', '別名資料檢視'),
            self::viewItem('assoc-data', 'views.view_assoc_data', 'fas fa-project-diagram', '社會關係資料檢視'),
            self::viewItem('biog-addr-data', 'views.view_biog_addr_data', 'fas fa-map-marked-alt', '人物地址資料檢視'),
            self::viewItem('biog-inst-addr-data', 'views.view_biog_inst_addr_data', 'fas fa-network-wired', '人物/社會機構/地址資料檢視'),
            self::viewItem('biog-inst-data', 'views.view_biog_inst_data', 'fas fa-people-arrows', '人物社會機構資料檢視'),
            self::viewItem('biog-source-data', 'views.view_biog_source_data', 'fas fa-bookmark', '人物來源資料檢視'),
            self::viewItem('biog-text-data', 'views.view_biog_text_data', 'fas fa-book-reader', '人物著作資料檢視'),
            self::viewItem('entry-data', 'views.view_entry_data', 'fas fa-user-graduate', '人物入仕資料檢視'),
            self::viewItem('event-addr-data', 'views.view_event_addr_data', 'fas fa-map', '人物事件地址檢視'),
            self::viewItem('events-data', 'views.view_events_data', 'fas fa-history', '人物事件資料檢視'),
            self::viewItem('kin-addr-data', 'views.view_kin_addr_data', 'fas fa-users', '人物親屬資料檢視'),
            self::viewItem('people-data', 'views.view_people_data', 'fas fa-id-card', '人物基本資料檢視'),
            self::viewItem('people-addr-data', 'views.view_people_addr_data', 'fas fa-map-pin', '人物索引地址檢視'),
            self::viewItem('posessions-addr-data', 'views.view_possessions_addr_data', 'fas fa-coins', '人物財產地址檢視'),
            self::viewItem('posessions-data', 'views.view_possessions_data', 'fas fa-piggy-bank', '人物財產資料檢視'),
            self::viewItem('posting-addr-data', 'views.view_posting_addr_data', 'fas fa-map-signs', '任官地址資料檢視'),
            self::viewItem('posting-office-data', 'views.view_posting_office_data', 'fas fa-briefcase', '任官職務資料檢視'),
            self::viewItem('status-data', 'views.view_status_data', 'fas fa-id-card-alt', '人物身份資料檢視'),
        ];
    }

    /**
     * 葉節點 helper。
     *
     * @param array{pages: array<int,string>, patterns: array<int,string>} $active
     * @return array<string, mixed>
     */
    protected static function item(
        string $key,
        string $label,
        string $icon,
        ?string $href,
        array $active,
        ?array $badge = null,
        ?\Closure $gate = null
    ): array {
        return [
            'key' => $key,
            // label 在此即解析為當前語系的顯示字串（單一來源、Blade 與 React 共用，
            // locale 切換是伺服器往返、share() 會重算，故不需前端再翻譯）。
            'label' => __($label),
            'icon' => $icon,
            'href' => $href,
            'suffix' => null,
            'badge' => $badge,
            'active' => $active,
            'children' => [],
            'gate' => $gate,
        ];
    }

    /**
     * 父節點（tree）helper。
     *
     * @param array<int, array<string, mixed>> $children
     * @return array<string, mixed>
     */
    protected static function tree_(string $key, string $label, string $icon, array $children, ?string $href = null, ?\Closure $gate = null, array $activePages = []): array {
        return [
            'key' => $key,
            'label' => __($label),
            'icon' => $icon,
            'href' => $href,
            'suffix' => null,
            'badge' => null,
            // $activePages：父節點自身命中的 $page_title（用於相容舊 $viewPages 等
            // 已列入但無對應子連結的「殘留」字串，保持選單展開行為完全一致）。
            'active' => ['pages' => $activePages, 'patterns' => []],
            'children' => $children,
            'gate' => $gate,
        ];
    }

    /**
     * Codes 子表節點：href = /codes/<TABLE>，active 以該表名（$page_title）比對。
     *
     * @return array<string, mixed>
     */
    protected static function codeItem(string $key, string $label, string $icon, string $table): array {
        // href 依 codes flag 解析：flag=new 且 app.codes.show 存在時指向 React 單表頁。
        $href = (migration_flag_is_new('codes') && Route::has('app.codes.show'))
            ? self::routeUrl('app.codes.show', ['table_name' => $table])
            : '/codes/' . $table;
        $node = self::item($key, $label, $icon, $href, ['pages' => [$table], 'patterns' => []]);
        $node['suffix'] = '(' . $table . ')';

        return $node;
    }

    /**
     * View 子表節點：href = view.show(slug)，active 以中文 $page_title 比對。
     *
     * @return array<string, mixed>
     */
    protected static function viewItem(string $slug, string $label, string $icon, string $pageTitle): array {
        return self::item(
            $slug,
            $label,
            $icon,
            self::routeUrl('view.show', $slug),
            ['pages' => [$pageTitle], 'patterns' => []]
        );
    }

    /**
     * 依 feature flag 解析連結：flag='new' 且新路由存在時指向新頁，否則舊頁。
     * 目前所有 flag 預設 'old'；新頁路由就緒後僅需翻 flag。
     */
    protected static function url(string $flagKey, string $oldRoute, ?string $newRoute = null): ?string {
        if (migration_flag_is_new($flagKey) && $newRoute !== null && Route::has($newRoute)) {
            return self::routeUrl($newRoute);
        }

        return self::routeUrl($oldRoute);
    }

    /**
     * 安全解析具名路由為絕對 URL；路由不存在時回傳 null（避免 RouteNotFoundException）。
     *
     * @param mixed $params
     */
    protected static function routeUrl(string $name, $params = []): ?string {
        if (!Route::has($name)) {
            return null;
        }

        return route($name, $params);
    }

    /**
     * 待審提案 badge：僅在使用者可管理使用者時計算（沿用 sidebar-v3 邏輯）。
     *
     * @return array<string, mixed>|null
     */
    protected static function pendingProposalsBadge(?User $user): ?array {
        $hasPending = false;

        if ($user && $user->canManageUsers()) {
            try {
                if (Schema::hasTable('operations')) {
                    $hasPending = Operation::where('crowdsourcing_status', 0)
                        ->whereIn('op_type', [
                            Operation::TYPE_PROPOSAL_CREATE,
                            Operation::TYPE_PROPOSAL_UPDATE,
                        ])
                        ->where('resource_data', 'like', '%"__review_status":"pending"%')
                        ->exists();
                }
            } catch (\Throwable $e) {
                $hasPending = false;
            }
        }

        return [
            'label' => __('nav.pending_review'),
            'variant' => 'warning',
            'show' => $hasPending,
        ];
    }

    /**
     * 單一節點是否 active：$page_title 命中 active.pages，或目前路由命中 active.patterns。
     * 供 Blade sidebar partial 使用（React 端自行依目前路由判定）。
     *
     * @param array<string, mixed> $node
     */
    public static function nodeActive(array $node, string $activePage): bool {
        $active = $node['active'] ?? ['pages' => [], 'patterns' => []];

        if ($activePage !== '' && in_array($activePage, $active['pages'] ?? [], true)) {
            return true;
        }

        $patterns = $active['patterns'] ?? [];

        if (!empty($patterns) && request()->routeIs(...$patterns)) {
            return true;
        }

        return false;
    }

    /**
     * 父節點是否展開（自身 active 或任一子孫 active）。
     *
     * @param array<string, mixed> $node
     */
    public static function treeOpen(array $node, string $activePage): bool {
        if (self::nodeActive($node, $activePage)) {
            return true;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (self::treeOpen($child, $activePage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 套用角色閘門並移除內部 gate 欄位（遞迴）。
     *
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<string, mixed>>
     */
    protected static function filter(array $nodes, ?User $user): array {
        $result = [];

        foreach ($nodes as $node) {
            $gate = $node['gate'] ?? null;

            if ($gate instanceof \Closure && !$gate()) {
                continue;
            }

            unset($node['gate']);

            if (!empty($node['children'])) {
                $node['children'] = self::filter($node['children'], $user);
            }

            $result[] = $node;
        }

        return $result;
    }
}
