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

    /**
     * ── 2026-09-15（Blade 下架環節 4d）─────────────────────────────
     *
     * 這裡原本有三條 `*_is_flag_aware()`：它們把 flag 翻成 `old`，斷言側邊欄連結指回
     * legacy route。環節 4d 把 `Navigation::url()` 的 flag 參數整個拿掉（**一律指 React 版**），
     * 那三條的前提因此消失。
     *
     * 取而代之的是這一條：把**相反的事實**寫死——翻 flag 不再改變任何 href。
     * 三個代表各挑一種解析路徑：`dashboard`（`url()` 直呼）、`views`／`altname-data`
     *（`viewItem()`）、`admin`（樹狀父節點）。
     */
    public function test_flipping_flags_no_longer_changes_any_href(): void {
        $admin = User::factory()->create(['is_active' => User::STATUS_ACTIVE, 'is_admin' => User::ROLE_SUPER_ADMIN]);

        // 先取一份「沒動 flag」的基準。
        $before = [
            'dashboard' => $this->findHref(Navigation::tree(null), 'dashboard'),
            'views' => $this->findHref(Navigation::tree(null), 'views'),
            'altname-data' => $this->findHref(Navigation::tree(null), 'altname-data'),
            'admin' => $this->findHref(Navigation::tree($admin), 'admin'),
        ];

        // 這些 href 必須是 React 版（否則下面的比較就算相等也沒意義）。
        $this->assertSame(route('app.dashboard'), $before['dashboard']);
        $this->assertSame(route('app.view.index'), $before['views']);
        $this->assertSame(route('app.view.show', 'altname-data'), $before['altname-data']);
        $this->assertSame(route('app.manage.index'), $before['admin']);

        // 把相關 flag 全部翻成 old——環節 4d 之前這會讓上面四個 href 指回 legacy route。
        config([
            'migration_flags.pages.dashboard' => 'old',
            'migration_flags.pages.view' => 'old',
            'migration_flags.pages.manage' => 'old',
            'migration_flags.pages.codes' => 'old',
        ]);

        $this->assertSame($before['dashboard'], $this->findHref(Navigation::tree(null), 'dashboard'));
        $this->assertSame($before['views'], $this->findHref(Navigation::tree(null), 'views'));
        $this->assertSame($before['altname-data'], $this->findHref(Navigation::tree(null), 'altname-data'));
        $this->assertSame($before['admin'], $this->findHref(Navigation::tree($admin), 'admin'));
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

    /**
     * 🔴 **側邊欄的每一條 href 都必須指向 React 版。**
     *
     * ── 2026-09-15（Blade 下架環節 4d-1，review 實測後補）─────────────
     * `Navigation::url()` 的簽名從 `url($flagKey, $old, $new, $params)` 改成
     * `url($new, $old, $params)`——**新舊順序對調**。review 實測：把 19 個呼叫點全部寫反，
     * 全 suite **只紅 1 條**；只寫反其中四條（operations／codes／merge-preview／audit-logs），
     * 全 suite **完全綠**、assertion 數一模一樣。也就是說 19 條裡只有 3 條被守著。
     *
     * 這條測試用**表驅動**補上那個缺口：遞迴收集整棵樹的 href，斷言每一條都在 `/app/` 底下。
     * 比逐條列便宜，而且**對日後新增的節點自動生效**。
     *
     * ⚠️ `$allowlist` 目前是空的，而且**應該保持空的**。要加進去之前先想清楚：
     * 一條不在 `/app/` 底下的側邊欄連結，意味著它指向一個只剩 302／410 closure 的舊 URI。
     */
    public function test_every_sidebar_href_points_at_the_react_app(): void {
        $allowlist = [];

        $admin = User::factory()->create([
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);

        $hrefs = $this->collectHrefs(Navigation::tree($admin));
        $this->assertNotEmpty($hrefs, '側邊欄一條連結都沒有——這條測試會變成空轉');

        foreach ($hrefs as $key => $href) {
            if (in_array($href, $allowlist, true)) {
                continue;
            }
            $path = parse_url($href, PHP_URL_PATH) ?? $href;
            $this->assertStringStartsWith(
                '/app/',
                $path,
                "側邊欄節點 '{$key}' 指向 {$href}——那不是 React 版。"
                .'環節 4d-1 把 Navigation 的 flag 分支收斂成「一律指 /app」，'
                .'新舊參數順序寫反時這裡會紅。'
            );
        }
    }

    /**
     * 收集樹中所有節點的 href（含子孫），以 key 索引。
     *
     * @return array<string, string>
     */
    private function collectHrefs(array $nodes): array {
        $out = [];
        foreach ($nodes as $node) {
            if (!empty($node['href']) && $node['href'] !== '#') {
                $out[$node['key'] ?? count($out)] = $node['href'];
            }
            if (!empty($node['children'])) {
                $out = array_merge($out, $this->collectHrefs($node['children']));
            }
        }

        return $out;
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
