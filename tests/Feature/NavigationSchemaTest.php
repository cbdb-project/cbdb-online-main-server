<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 導覽單一來源（App\Support\Navigation）測試：角色閘門、flag 連結解析、active 判定。
 */
class NavigationSchemaTest extends TestCase {
    use RefreshDatabase;

    /** 收集樹中所有節點 key（含子孫）。 */
    private function collectKeys(array $nodes): array {
        $keys = [];
        foreach ($nodes as $node) {
            $keys[] = $node['key'];
            if (!empty($node['children'])) {
                $keys = array_merge($keys, $this->collectKeys($node['children']));
            }
        }

        return $keys;
    }

    public function test_guest_sees_only_ungated_top_level_items(): void {
        $tree = Navigation::tree(null);
        $topKeys = array_column($tree, 'key');

        // 一般項目可見
        $this->assertContains('dashboard', $topKeys);
        $this->assertContains('codes', $topKeys);
        $this->assertContains('views', $topKeys);

        // 受閘門保護的不可見
        $this->assertNotContains('expert', $topKeys);
        $this->assertNotContains('not-public', $topKeys);
        $this->assertNotContains('admin', $topKeys);
    }

    public function test_active_non_admin_sees_expert_but_not_admin(): void {
        $user = User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_REGULAR]);
        $tree = Navigation::tree($user);
        $topKeys = array_column($tree, 'key');

        $this->assertContains('expert', $topKeys);
        $this->assertNotContains('not-public', $topKeys);
        $this->assertNotContains('admin', $topKeys);

        // 外部資料庫引用瀏覽器已移入專家工具，活躍一般用戶可見。
        $this->assertContains('wiki-maintenance', $this->collectKeys($tree));
    }

    public function test_super_admin_sees_all_sections(): void {
        $user = User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_SUPER_ADMIN]);
        $allKeys = $this->collectKeys(Navigation::tree($user));

        foreach (['expert', 'not-public', 'admin', 'audit-logs', 'manage', 'merge-preview'] as $key) {
            $this->assertContains($key, $allKeys, "superadmin 應可見 $key");
        }
    }

    public function test_gate_field_is_stripped_from_output(): void {
        $user = User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_SUPER_ADMIN]);
        foreach (Navigation::tree($user) as $node) {
            $this->assertArrayNotHasKey('gate', $node, '輸出不應包含內部 gate 閉包');
        }
    }

    public function test_flag_resolves_href_to_old_route_by_default(): void {
        // 預設 flag = old：dashboard 連結應指向舊 dashboard 路由。
        config(['migration_flags.pages.dashboard' => 'old']);
        $tree = Navigation::tree(null);
        $dashboard = collect($tree)->firstWhere('key', 'dashboard');

        $this->assertSame(route('dashboard'), $dashboard['href']);
    }

    public function test_view_subtree_is_flag_aware(): void {
        // 檢視表（views）父節點與每個子檢視 href 應隨 view flag 在舊 Blade / 新 React 間切換，
        // 與 codes 子樹對齊（show 與 appShow 共用同一 key 解析）。
        config(['migration_flags.pages.view' => 'old']);
        $old = Navigation::tree(null);
        $this->assertSame(route('view.index'), $this->findHref($old, 'views'));
        $this->assertSame(route('view.show', 'altname-data'), $this->findHref($old, 'altname-data'));

        config(['migration_flags.pages.view' => 'new']);
        $new = Navigation::tree(null);
        $this->assertSame(route('app.view.index'), $this->findHref($new, 'views'));
        $this->assertSame(route('app.view.show', 'altname-data'), $this->findHref($new, 'altname-data'));
    }

    public function test_admin_tree_parent_is_flag_aware(): void {
        // 管理工具樹的父節點（header 連結）應隨 manage flag 切換，與其子項 manage 一致。
        $admin = User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_SUPER_ADMIN]);

        config(['migration_flags.pages.manage' => 'old']);
        $this->assertSame(route('manage.index'), $this->findHref(Navigation::tree($admin), 'admin'));

        config(['migration_flags.pages.manage' => 'new']);
        $this->assertSame(route('app.manage.index'), $this->findHref(Navigation::tree($admin), 'admin'));
    }

    /** 遞迴尋找指定 key 節點的 href。 */
    private function findHref(array $nodes, string $key): ?string {
        foreach ($nodes as $node) {
            if (($node['key'] ?? null) === $key) {
                return $node['href'] ?? null;
            }
            if (!empty($node['children'])) {
                $h = $this->findHref($node['children'], $key);
                if ($h !== null) {
                    return $h;
                }
            }
        }

        return null;
    }

    public function test_node_active_matches_page_title_and_route_pattern(): void {
        $node = [
            'active' => ['pages' => ['系統總覽'], 'patterns' => []],
            'children' => [],
        ];

        $this->assertTrue(Navigation::nodeActive($node, '系統總覽'));
        $this->assertFalse(Navigation::nodeActive($node, '其他頁'));
    }

    /** 收集樹中所有節點的 active.pages（含子孫）。 */
    private function collectActivePages(array $nodes): array {
        $pages = [];
        foreach ($nodes as $node) {
            $pages = array_merge($pages, $node['active']['pages'] ?? []);
            if (!empty($node['children'])) {
                $pages = array_merge($pages, $this->collectActivePages($node['children']));
            }
        }

        return $pages;
    }

    public function test_active_pages_union_covers_legacy_sidebar_open_set(): void {
        // 舊 sidebar-v3 用來判定選單展開/高亮的全部 $page_title 字串集合。
        // 此測試保證重構後沒有遺漏任何一個（parity）。
        $legacy = [
            // 頂層
            '系統總覽', 'Basicinformation', 'NewUpdate', 'OperationsProposals',
            // codes
            'Codes', '全部表格', 'ADDRESSES', 'ALTNAME_CODES', 'APPOINTMENT_CODES',
            'TEXT_CODES', 'ADDR_CODES', 'OFFICE_CODES', 'SOCIAL_INSTITUTION_CODES',
            'ADDR_BELONGS_DATA', 'TEXT_INSTANCE_DATA',
            // views
            '檢視表總覽', '地址層級檢視', '別名資料檢視', '社會關係資料檢視', '人物地址資料檢視',
            '人物/社會機構/地址資料檢視', '人物社會機構資料檢視', '人物來源資料檢視', '人物著作資料檢視',
            '人物入仕資料檢視', '人物事件地址檢視', '人物事件資料檢視', '人物親屬資料檢視', '人物基本資料檢視',
            '人物索引地址檢視', '人物財產地址檢視', '人物財產資料檢視', '任官地址資料檢視', '任官職務資料檢視',
            '人物身份資料檢視',
            // expert / not-public
            'Query Playground', 'Crowdsourcing', '人物瀏覽', '按入仕查詢', '歷史地圖',
            // admin
            '用戶管理', 'NL Query Logs', 'AI 填充日誌', '審計日誌', 'SQL 執行計畫',
            '批次匯入書稿資料', '批次匯入官職', '批次匯入社會機構', 'Wiki 對照資料維護',
            'CBDB 內部表維護', '單向關係修復', 'MergePreview',
        ];

        $user = User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_SUPER_ADMIN]);
        $union = $this->collectActivePages(Navigation::tree($user));

        foreach ($legacy as $pageTitle) {
            $this->assertContains($pageTitle, $union, "舊 sidebar 的 \$page_title '$pageTitle' 必須仍在某節點 active.pages 中");
        }
    }

    public function test_tree_open_when_descendant_active(): void {
        $tree = Navigation::tree(null);
        $codes = collect($tree)->firstWhere('key', 'codes');

        // codes 子表 ADDRESSES 對應 $page_title 'ADDRESSES'
        $this->assertTrue(Navigation::treeOpen($codes, 'ADDRESSES'));
        $this->assertFalse(Navigation::treeOpen($codes, '不存在的頁'));
    }
}
