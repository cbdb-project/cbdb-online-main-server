<?php

namespace Tests\Feature;

use App\Support\CompositePrimaryKey;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 複合主鍵查詢參數路由測試
 *
 * 測試新的查詢參數路由格式是否正確配置，
 * 以及 CompositePrimaryKey::buildUrl() 生成的 URL 是否符合預期。
 *
 * 注意：這些測試僅驗證路由配置和 URL 生成，不涉及實際的資料庫操作。
 * 資料庫相關的測試請參考 BasicInformationSourcesControllerTest 等。
 */
class CompositePrimaryKeyRoutesTest extends TestCase {
    /**
     * 測試 buildUrl 生成的 offices 編輯路由格式正確
     */
    #[Test]
    public function it_generates_correct_offices_edit_url(): void {
        $pk = [
            'c_office_id' => 123,
            'c_posting_id' => 456,
        ];

        $url = CompositePrimaryKey::buildUrl(
            'app.basicinformation.offices.editv2',
            ['id' => 1],
            $pk
        );

        // URL 應該是相對路徑（避免 HTTPS 混合內容問題）
        $this->assertStringStartsWith('/app/basicinformation/1/offices/edit-v2', $url);
        $this->assertStringContainsString('c_office_id=123', $url);
        $this->assertStringContainsString('c_posting_id=456', $url);
    }

    /**
     * 測試 buildUrl 生成的 altnames 編輯路由能正確編碼中文字符
     */
    #[Test]
    public function it_encodes_chinese_characters_in_altnames_url(): void {
        $pk = [
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ];

        $url = CompositePrimaryKey::buildUrl(
            'app.basicinformation.altnames.editv2',
            ['id' => 12345],
            $pk
        );

        $this->assertStringStartsWith('/app/basicinformation/12345/altnames/edit-v2', $url);
        // 中文應該被 URL 編碼
        $this->assertStringContainsString('c_alt_name_chn=', $url);

        // 解析 URL 並驗證參數可以正確還原
        $parsed = parse_url($url);
        parse_str($parsed['query'], $params);

        $this->assertEquals('12345', $params['c_personid']);
        $this->assertEquals('1', $params['c_sequence']);
        $this->assertEquals('張三', $params['c_alt_name_chn']);
        $this->assertEquals('10', $params['c_alt_name_type_code']);
    }

    /**
     * 測試 buildUrl 生成的 assoc 編輯路由能正確處理特殊字符
     */
    #[Test]
    public function it_encodes_special_characters_in_assoc_url(): void {
        $pk = [
            'c_personid' => 12345,
            'c_assoc_code' => 1,
            'c_assoc_id' => 67890,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '論語-註釋/卷一',  // 包含負號和斜線
            'c_assoc_first_year' => 1000,
        ];

        $url = CompositePrimaryKey::buildUrl(
            'app.basicinformation.assoc.editv2',
            ['id' => 12345],
            $pk
        );

        // 解析 URL 並驗證特殊字符可以正確還原
        $parsed = parse_url($url);
        parse_str($parsed['query'], $params);

        $this->assertEquals('論語-註釋/卷一', $params['c_text_title']);
    }

    /**
     * 所有複合主鍵子資源都有對應的 React edit-v2 路由。
     *
     * 原本斷言的是 legacy `basicinformation.{seg}.{edit,update,destroy}.query` 共 36 條，
     * 它們已於 Blade 下架計畫環節 2 全數刪除（見同檔下一個測試）。複合主鍵的 URL 組裝
     * （CompositePrimaryKey::buildUrl）仍在服役，只是目標換成 /app 的 edit-v2。
     */
    #[Test]
    public function all_composite_pk_editor_routes_are_defined(): void {
        $routes = [
            'app.basicinformation.altnames.editv2',
            'app.basicinformation.addresses.editv2',
            'app.basicinformation.texts.editv2',
            'app.basicinformation.sources.editv2',
            'app.basicinformation.assoc.editv2',
            'app.basicinformation.kinship.editv2',
            'app.basicinformation.statuses.editv2',
            'app.basicinformation.entries.editv2',
            'app.basicinformation.events.editv2',
            'app.basicinformation.socialinst.editv2',
            'app.basicinformation.offices.editv2',
            'app.basicinformation.possession.editv2',
        ];

        foreach ($routes as $routeName) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($routeName),
                "Route '{$routeName}' should be defined"
            );
        }
    }

    /**
     * CompositePrimaryKey::APP_EDIT_ROUTE_MAP 裡的每個路由名都必須真的存在。
     *
     * 這是 buildResourceEditUrl 的護欄：該 map 一旦指向不存在的路由名，operations 的
     * 「查閱／修改提案」連結就會在產 payload 時拋 RouteNotFoundException（整頁 500），
     * 而不是只壞掉一個連結。
     */
    #[Test]
    public function app_edit_route_map_points_only_at_existing_routes(): void {
        foreach (CompositePrimaryKey::APP_EDIT_ROUTE_MAP as $table => $routeName) {
            $this->assertIsString($routeName, "APP_EDIT_ROUTE_MAP['{$table}'] 應為單一路由名");
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($routeName),
                "APP_EDIT_ROUTE_MAP['{$table}'] 指向的路由 '{$routeName}' 不存在"
            );
        }
    }

    /**
     * `APP_EDIT_ROUTE_MAP` 必須涵蓋所有需要「編輯連結」的子資源表。
     *
     * 這是原 `edit_route_map_covers_all_resource_tables`（驗 legacy `EDIT_ROUTE_MAP`）的
     * 承接。同檔另一個測試驗的是反方向——「map 裡的路由名都存在」；**兩個方向都要有**：
     * 少了這一半，漏掉一張表時 `buildResourceEditUrl()` 只會安靜回 null，operations 的
     * 「查閱」連結整個消失，而不會有任何測試變紅。
     */
    #[Test]
    public function app_edit_route_map_covers_all_subresource_tables(): void {
        $requiredTables = [
            'ALTNAME_DATA',
            'BIOG_ADDR_DATA',
            'TEXT_DATA',
            'BIOG_TEXT_DATA',
            'BIOG_SOURCE_DATA',
            'POSTED_TO_OFFICE_DATA',
            'POSTED_TO_ADDR_DATA',
            'ASSOC_DATA',
            'KIN_DATA',
            'EVENTS_DATA',
            'STATUS_DATA',
            'ENTRY_DATA',
            'POSSESSION_DATA',
            'BIOG_INST_DATA',
        ];

        foreach ($requiredTables as $table) {
            $this->assertArrayHasKey(
                $table,
                CompositePrimaryKey::APP_EDIT_ROUTE_MAP,
                "APP_EDIT_ROUTE_MAP 缺少 '{$table}'——buildResourceEditUrl() 會安靜回 null，編輯連結整個消失"
            );
        }
    }

    /**
     * legacy 子資源路由已全數下架（Blade 下架計畫環節 2）。
     *
     * 這個測試是「不要偷偷加回來」的護欄：legacy 表單路由連同視圖、controller、
     * LegacyBladeFormGate 一併刪除，任何一條重新出現都代表下架沒有做乾淨。
     */
    #[Test]
    public function legacy_subresource_routes_are_all_retired(): void {
        $segments = ['altnames', 'addresses', 'texts', 'sources', 'assoc', 'kinship',
            'statuses', 'entries', 'events', 'socialinst', 'offices', 'possession'];

        foreach ($segments as $seg) {
            foreach (['edit.query', 'update.query', 'destroy.query', 'index', 'create', 'store', 'edit', 'update'] as $action) {
                $routeName = "basicinformation.{$seg}.{$action}";
                $this->assertFalse(
                    \Illuminate\Support\Facades\Route::has($routeName),
                    "Legacy route '{$routeName}' 應已下架"
                );
            }
        }

        // 人物層：顯示頁保留路由名（302 導向 /app），但 legacy 提案端點已下架。
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('basicinformation.index'));
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('basicinformation.show'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('basicinformation.proposal.store'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('basicinformation.proposal.update'));
    }

    /**
     * 測試 SCHEMAS 定義涵蓋所有需要的資料表
     */
    #[Test]
    public function schemas_cover_all_required_tables(): void {
        $requiredTables = [
            'ALTNAME_DATA',
            'BIOG_ADDR_DATA',
            'TEXT_DATA',
            'BIOG_SOURCE_DATA',
            'POSTED_TO_OFFICE_DATA',
            'ASSOC_DATA',
            'KIN_DATA',
            'EVENTS_DATA',
            'STATUS_DATA',
            'ENTRY_DATA',
            'BIOG_INST_DATA',
        ];

        foreach ($requiredTables as $table) {
            $schema = CompositePrimaryKey::getSchema($table);
            $this->assertNotNull(
                $schema,
                "Schema for '{$table}' should be defined in CompositePrimaryKey::SCHEMAS"
            );
            $this->assertIsArray($schema);
            $this->assertNotEmpty($schema);
        }
    }

    /**
     * 測試 POSTED_TO_OFFICE_DATA 的 schema 定義正確
     */
    #[Test]
    public function offices_schema_has_correct_fields(): void {
        $schema = CompositePrimaryKey::getSchema('POSTED_TO_OFFICE_DATA');

        $this->assertEquals(['c_office_id', 'c_posting_id'], $schema);
    }

    /**
     * 測試 buildUrl 的 absolute 參數
     */
    #[Test]
    public function buildUrl_respects_absolute_parameter(): void {
        $pk = ['c_office_id' => 1, 'c_posting_id' => 2];

        // 預設是相對 URL
        $relativeUrl = CompositePrimaryKey::buildUrl(
            'app.basicinformation.offices.editv2',
            ['id' => 1],
            $pk,
            false
        );
        $this->assertStringStartsWith('/app/basicinformation', $relativeUrl);

        // 絕對 URL 應該包含 host
        $absoluteUrl = CompositePrimaryKey::buildUrl(
            'app.basicinformation.offices.editv2',
            ['id' => 1],
            $pk,
            true
        );
        $this->assertStringContainsString('://', $absoluteUrl);
    }
}
