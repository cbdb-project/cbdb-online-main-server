<?php

namespace App\Support;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * 側邊欄導覽「單一真實來源」（single source of truth）。
 *
 * React（AppShell 側邊欄）從這裡取得結構化導覽樹。
 *（原本 Blade 的 `layouts/sidebar-v3.blade.php` + `layouts/partials/sidebar-node.blade.php`
 *  也讀同一份，用來避免兩套側邊欄漂移；它們已於環節 5a 實體刪除，這裡現在是唯一消費端。）
 *
 * 設計：
 *  - 角色閘門在後端套用（依 User 的 is...()/can...() 方法），回傳「使用者可見」的樹；
 *    前端閘門僅 UX，後端路由仍須獨立授權。
 *  - active-state **不在這裡決定**：節點只描述「連到哪」，由 React 端
 *    （`components/shell/SidebarNode.tsx`）依 href 路徑 + 顯著 query 簽章判定。
 *    環節 4d-2 以前每個節點還帶一組 `active.pages`／`active.patterns`，那是給 Blade
 *    sidebar 用的（$page_title 字串比對／`request()->routeIs()`），隨 5a 一併移除。
 *  - 🔴 **連結一律指 React 版（環節 4d 起）**：本類原本依 feature flag
 *    （`config/migration_flags.php`）在新舊路由之間二選一。整個 Blade 下架完成之後
 *    ——legacy 頁面全數實體刪除（環節 2／4a／4b-4／4c）、封路機制與 `LEGACY_PAGE_RETIREMENT`
 *    移除（4b-4c）、flag 機制本身移除（4d）——**沒有任何回退鍵**，要回到 Blade 只能
 *    git revert 並重新部署。
 *    `url()`／`codeItem()`／`viewItem()` 仍保留「新路由不存在時退回舊 route name」那一層，
 *    理由見 `url()` 的 docblock（那些舊 route name 是 302 closure，比讓側邊欄項目消失好）。
 *
 * 節點結構：
 *  [
 *    'key'      => string,                 // 穩定識別（React key / 測試）
 *    'label'    => string,                 // 翻譯 key（__()）
 *    'icon'     => string,                 // Font Awesome class
 *    'href'     => ?string,                // 連結；tree 父節點可為 null('#')
 *    'suffix'   => ?string,                // 次要說明（如 codes 的表名）
 *    'badge'    => ?array,                 // ['label'=>翻譯key,'variant'=>..., 'show'=>bool]
 *    'children' => array,                  // 子節點（tree）
 *  ]
 */
class Navigation {
    /**
     * 取得指定使用者可見的導覽樹（已套用角色閘門；連結一律指 React 版）。
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
                self::url('app.dashboard', 'dashboard')
            ),

            self::item(
                'person',
                'nav.person_editing',
                'fas fa-landmark',
                // legacy 人物頁已於 Blade 下架計畫環節 2 刪除（舊 URI 僅剩 302），故直接指 React。
                route('app.basicinformation.index', [], false)
            ),

            self::item(
                'operations',
                'nav.recent_operations',
                'fas fa-clipboard-list',
                self::url('app.operations.index', 'operations.index')
            ),

            self::item(
                'proposals',
                'nav.recent_proposals',
                'fas fa-clipboard-check',
                self::url('app.operations.index', 'operations.index', ['proposals_only' => 1]),
                self::pendingProposalsBadge($user)
            ),

            // 全部表格（Codes）
            self::tree_('codes', 'nav.all_tables', 'fa fa-database', self::codesChildren(), self::url('app.codes.index', 'codes.index')),

            // 檢視表（Views）。'地址層級檢視' 為舊 $viewPages 殘留（無對應子連結，
            // 目前無頁面設定此 $page_title），保留以維持選單展開的完全一致。
            self::tree_('views', 'nav.views', 'fa fa-th-list', self::viewsChildren(), self::url('app.view.index', 'view.index'), null, ['地址層級檢視']),

            // 專家工具（需活躍）
            self::tree_('expert', 'nav.expert_tools', 'fas fa-flask', [
                self::item(
                    'query-playground',
                    'nav.sql_query_playground',
                    'fas fa-terminal',
                    self::routeUrl('app.query-playground.index')
                ),
                // 唯讀外部資料庫引用瀏覽器：自管理工具移入，權限已降為活躍帳號。
                // Blade 版已下架、無 flag 回退（/external-db-link 硬導向 React，同 Query Playground 模式）。
                self::item(
                    'wiki-maintenance',
                    'admin.wiki_maintenance',
                    'fab fa-wikipedia-w',
                    self::routeUrl('app.external-db-link')
                ),
            ], self::routeUrl('app.query-playground.index'), fn () => $isActive),

            // 暫不公開工具（需 superadmin）
            self::tree_('not-public', 'nav.not_public_tools', 'fas fa-lock', [
                self::item(
                    'crowdsourcing',
                    'nav.crowdsourcing_records',
                    'fas fa-users-cog',
                    self::url('app.crowdsourcing.index', 'crowdsourcing.index')
                ),
                self::item(
                    'person-browser',
                    'nav.person_browser',
                    'fas fa-user-friends',
                    self::routeUrl('app.person-browser.index')
                ),
                self::item(
                    'search-by-entry',
                    'nav.search_by_entry',
                    'fas fa-search',
                    self::routeUrl('app.search-by.entry.index')
                ),
                self::item(
                    'maps',
                    'nav.historical_maps',
                    'fas fa-map',
                    self::routeUrl('app.maps.index')
                ),
                // #83（§9）：單向關係修復頁降級至「暫不公開」。其缺邊補建／多條裁決已由人物編輯器行內流程取代
                // （#79/#80/#81），路由保留供 admin 直接使用，但不在常規管理工具曝光，標註「已由行內流程取代」。
                self::item(
                    'unidirectional-repair',
                    'admin.unidirectional_repair_superseded',
                    'fas fa-exchange-alt',
                    self::url('app.admin.unidirectional-relationship-repair', 'admin.unidirectional-relationship-repair')
                ),
            ], null, fn () => $isSuperAdmin),

            // 管理工具（需 superadmin）
            self::tree_('admin', 'nav.admin_tools', 'fas fa-tools', [
                self::item(
                    'manage',
                    'nav.user_management',
                    'fas fa-user-cog',
                    self::url('app.manage.index', 'manage.index')
                ),
                self::item(
                    'nl-query-logs',
                    'admin.nl_query_logs',
                    'fas fa-comments',
                    self::url('app.query-playground.nl-query-logs', 'query-playground.nl-query-logs')
                ),
                self::item(
                    'ai-fill-logs',
                    'admin.ai_fill_logs',
                    'fas fa-robot',
                    self::url('app.admin.ai-fill-logs', 'admin.ai-fill-logs')
                ),
                self::item(
                    'audit-logs',
                    'admin.audit_logs',
                    'fas fa-clipboard-check',
                    self::url('app.admin.audit-logs', 'admin.audit-logs')
                ),
                self::item(
                    'explain-sql',
                    'admin.sql_explain',
                    'fa fa-search',
                    self::url('app.admin.explainsql', 'admin.explainsql')
                ),
                self::item(
                    'batch-books',
                    'admin.batch_load_books',
                    'fa fa-upload',
                    self::url('app.admin.batch-load-book-titles', 'admin.batch-load-book-titles')
                ),
                self::item(
                    'batch-offices',
                    'admin.batch_load_offices',
                    'fa fa-briefcase',
                    self::url('app.admin.batch-load-offices', 'admin.batch-load-offices')
                ),
                self::item(
                    'batch-social',
                    'admin.batch_load_social_institutes',
                    'fa fa-university',
                    self::url('app.admin.batch-load-social-institutes', 'admin.batch-load-social-institutes')
                ),
                self::item(
                    'table-maintenance',
                    'admin.table_maintenance',
                    'fa fa-database',
                    self::url('app.admin.cbdb-table-maintenance', 'admin.cbdb-table-maintenance')
                ),
                self::item(
                    'merge-preview',
                    'admin.merge_records',
                    'fas fa-shuffle',
                    self::url('app.merge-preview.index', 'merge-preview.index')
                ),
            ], self::url('app.manage.index', 'manage.index'), fn () => $isSuperAdmin),
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
                self::url('app.codes.index', 'codes.index')
            ),
            self::codeItem('addr-belongs', 'codes.addr_belongs_data', 'fas fa-sitemap', 'ADDR_BELONGS_DATA'),
            self::codeItem('addr-codes', 'codes.addr_codes', 'fas fa-map-marker-alt', 'ADDR_CODES'),
            self::codeItem('addresses', 'codes.addresses', 'fas fa-map', 'ADDRESSES'),
            self::codeItem('altname-codes', 'codes.altname_codes', 'fas fa-user-tag', 'ALTNAME_CODES'),
            self::codeItem('appointment-codes', 'codes.appointment_codes', 'fas fa-briefcase', 'APPOINTMENT_CODES'),
            // 官職／社會機構已收斂為實體聚合：節點改指實體頁（/app/office、/app/social-institution），
            // 設定來自 config/entity_aggregates.php（§6.5 單一真源）；裸表已封寫、僅供讀取回退。
            self::entityNavItem('OFFICE_CODES', 'office-codes', 'codes.office_codes', 'fas fa-id-badge'),
            self::entityNavItem('SOCIAL_INSTITUTION_CODES', 'social-institution-codes', 'codes.social_institution_codes', 'fas fa-university'),
            self::entityNavItem('TEXT_CODES', 'text-codes', 'codes.text_codes', 'fas fa-book'),
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
            // 只保留 React 總覽入口（→ app.view.index）。
            // 📌 2026-09-15 更正：這裡原本寫「舊 Blade /view 已翻 flag=new，舊路由仍保留、
            //    可直接訪問作為回退」——**兩件事現在都不成立**：flag 機制於 4d-1 移除，
            //    而 `view.index`／`view.show` 早已是 302 導向 `/app/view*` 的 closure
            //    （`routes/web.php`），訪問它們只會被導回 React 版，不是回退路徑。
            self::item(
                'views-overview-new',
                'nav.views_overview',
                'fas fa-layer-group',
                self::routeUrl('app.view.index'),
            ),
            self::viewItem('altname-data', 'views.view_altname_data', 'fas fa-user-tag'),
            self::viewItem('assoc-data', 'views.view_assoc_data', 'fas fa-project-diagram'),
            self::viewItem('biog-addr-data', 'views.view_biog_addr_data', 'fas fa-map-marked-alt'),
            self::viewItem('biog-inst-addr-data', 'views.view_biog_inst_addr_data', 'fas fa-network-wired'),
            self::viewItem('biog-inst-data', 'views.view_biog_inst_data', 'fas fa-people-arrows'),
            self::viewItem('biog-source-data', 'views.view_biog_source_data', 'fas fa-bookmark'),
            self::viewItem('biog-text-data', 'views.view_biog_text_data', 'fas fa-book-reader'),
            self::viewItem('entry-data', 'views.view_entry_data', 'fas fa-user-graduate'),
            self::viewItem('event-addr-data', 'views.view_event_addr_data', 'fas fa-map'),
            self::viewItem('events-data', 'views.view_events_data', 'fas fa-history'),
            self::viewItem('kin-addr-data', 'views.view_kin_addr_data', 'fas fa-users'),
            self::viewItem('people-data', 'views.view_people_data', 'fas fa-id-card'),
            self::viewItem('people-addr-data', 'views.view_people_addr_data', 'fas fa-map-pin'),
            self::viewItem('posessions-addr-data', 'views.view_possessions_addr_data', 'fas fa-coins'),
            self::viewItem('posessions-data', 'views.view_possessions_data', 'fas fa-piggy-bank'),
            self::viewItem('posting-addr-data', 'views.view_posting_addr_data', 'fas fa-map-signs'),
            self::viewItem('posting-office-data', 'views.view_posting_office_data', 'fas fa-briefcase'),
            self::viewItem('status-data', 'views.view_status_data', 'fas fa-id-card-alt'),
        ];
    }

    /**
     * 葉節點 helper。
     *
     * @return array<string, mixed>
     */
    protected static function item(
        string $key,
        string $label,
        string $icon,
        ?string $href,
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
    protected static function tree_(string $key, string $label, string $icon, array $children, ?string $href = null, ?\Closure $gate = null): array {
        return [
            'key' => $key,
            'label' => __($label),
            'icon' => $icon,
            'href' => $href,
            'suffix' => null,
            'badge' => null,
            'children' => $children,
            'gate' => $gate,
        ];
    }

    /**
     * 實體聚合頁節點：依 config/entity_aggregates.php 的 nav 設定，把裸表節點改指
     * 上層實體入口（/app/*，§6.5 單一真源）。實體未在 config 註冊（回退）時
     * 退回裸表 codeItem。
     *
     * @return array<string, mixed>
     */
    protected static function entityNavItem(string $table, string $fallbackKey, string $fallbackLabel, string $fallbackIcon): array {
        // 走 EntityAggregateRegistry::entities() 而非直接 config()：config 的預設值只在 key
        // 不存在時生效，key 被設成 null／字串時直接 foreach 會 fatal——側欄是每一頁都渲染的，
        // 那等於整站 500。
        foreach (EntityAggregateRegistry::entities() as $entity) {
            $nav = $entity['nav'] ?? null;
            if (!$nav || strtoupper((string) ($nav['table'] ?? '')) !== strtoupper($table)) {
                continue;
            }
            // 路由不存在時回退裸表頁（防呆，正常部署不會發生）。
            $href = self::routeUrl($nav['route']) ?? '/codes/' . $table;
            $node = self::item($nav['key'], $nav['label'], $nav['icon'], $href);
            $node['suffix'] = '(' . $table . ')';

            return $node;
        }

        return self::codeItem($fallbackKey, $fallbackLabel, $fallbackIcon, $table);
    }

    /**
     * Codes 子表節點：href = React 版單表頁 `/app/codes/<TABLE>`。
     *
     * @return array<string, mixed>
     */
    protected static function codeItem(string $key, string $label, string $icon, string $table): array {
        // ── 2026-09-15（環節 4d）：原本依 codes flag 二選一，現在一律指 React 單表頁。
        // fallback 的 `/codes/{table}` 字串留著（那條路由仍在，是 302 closure），
        // 只在 `app.codes.show` 不存在時才會用到——理由同 `url()` 的 docblock。
        $href = self::routeUrl('app.codes.show', ['table_name' => $table]) ?? '/codes/' . $table;
        $node = self::item($key, $label, $icon, $href);
        $node['suffix'] = '(' . $table . ')';

        return $node;
    }

    /**
     * View 子表節點：href = React 版單檢視頁 `app.view.show(slug)`。
     *
     * ── 2026-09-15（環節 4d-2）：原本還有第四個參數 `$pageTitle`（中文標題，例如
     * '別名資料檢視'），唯一的用途是填進 `active.pages` 供 Blade sidebar 以 `$page_title`
     * 字串比對判定 active。那組欄位已隨 Blade sidebar 一併移除，參數因此一併刪除
     * ——留著會讓 18 個呼叫端持續傳一個沒人讀的字串。
     *
     * @return array<string, mixed>
     */
    protected static function viewItem(string $slug, string $label, string $icon): array {
        // ── 2026-09-15（環節 4d）：原本依 view flag 二選一，現在一律指 React 單檢視頁
        // （show 與 appShow 共用同一 key 解析 buildViewData）。
        $href = self::routeUrl('app.view.show', $slug) ?? self::routeUrl('view.show', $slug);

        return self::item(
            $slug,
            $label,
            $icon,
            $href
        );
    }

    /**
     * 解析導覽連結：**一律指 React 版**，該路由不存在時才退回舊 route name。
     *
     * ── 2026-09-15（Blade 下架環節 4d）─────────────────────────────
     * 原簽名是 `url($flagKey, $oldRoute, $newRoute, $params)`，依 migration flag 二選一。
     * 所有 legacy Blade 頁面都已實體刪除（環節 4a／4b-4／4c），flag 對渲染完全沒有作用，
     * 所以第一個參數整個拿掉，順序也調成「新在前」——讀起來就是這個方法現在做的事。
     *
     * ⚠️ **`$oldRoute` 的 fallback 刻意留著**：那些舊 route name 仍然存在（是 302／410 的
     * closure），而 `routeUrl()` 對不存在的路由回 `null`——留著這一層可以在「新路由被改名」
     * 時仍然產出一個會 302 到正確位置的連結，而不是讓側邊欄的項目整個消失。
     */
    protected static function url(string $newRoute, string $oldRoute, array $params = []): ?string {
        return self::routeUrl($newRoute, $params) ?? self::routeUrl($oldRoute, $params);
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
     * 待審提案 badge：僅在使用者可管理使用者時計算（沿用已刪除的 sidebar-v3.blade.php 的判定）。
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

    // ── 2026-09-15（Blade 下架環節 4d-2）─────────────────────────────
    //
    // 這裡原本有 `nodeActive()` 與 `treeOpen()`：依 `active.pages`（$page_title 字串）與
    // `active.patterns`（route 名稱 glob）判定側邊欄的 active／展開狀態。
    // 兩者**只服務 Blade** —— 唯一的呼叫端是 `layouts/sidebar-v3.blade.php` 與
    // `layouts/partials/sidebar-node.blade.php`，已於環節 5a 實體刪除。
    // React 端（`components/shell/SidebarNode.tsx`）以 **href 路徑 + 顯著 query 簽章**判定
    // active，從來不讀這兩個欄位（該檔註解早就寫明「active.patterns 僅供 Blade 使用」）。
    // ⇒ 方法與 `active` 這個節點欄位一併移除。

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
