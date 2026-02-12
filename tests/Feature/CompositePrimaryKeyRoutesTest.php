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
            'basicinformation.offices.edit.query',
            ['id' => 1],
            $pk
        );

        // URL 應該是相對路徑（避免 HTTPS 混合內容問題）
        $this->assertStringStartsWith('/basicinformation/1/offices/edit', $url);
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
            'basicinformation.altnames.edit.query',
            ['id' => 12345],
            $pk
        );

        $this->assertStringStartsWith('/basicinformation/12345/altnames/edit', $url);
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
            'basicinformation.assoc.edit.query',
            ['id' => 12345],
            $pk
        );

        // 解析 URL 並驗證特殊字符可以正確還原
        $parsed = parse_url($url);
        parse_str($parsed['query'], $params);

        $this->assertEquals('論語-註釋/卷一', $params['c_text_title']);
    }

    /**
     * 測試所有需要複合主鍵的路由都已定義
     */
    #[Test]
    public function all_composite_pk_routes_are_defined(): void {
        $routes = [
            'basicinformation.altnames.edit.query',
            'basicinformation.altnames.update.query',
            'basicinformation.altnames.destroy.query',
            'basicinformation.addresses.edit.query',
            'basicinformation.addresses.update.query',
            'basicinformation.addresses.destroy.query',
            'basicinformation.texts.edit.query',
            'basicinformation.texts.update.query',
            'basicinformation.texts.destroy.query',
            'basicinformation.sources.edit.query',
            'basicinformation.sources.update.query',
            'basicinformation.sources.destroy.query',
            'basicinformation.assoc.edit.query',
            'basicinformation.assoc.update.query',
            'basicinformation.assoc.destroy.query',
            'basicinformation.kinship.edit.query',
            'basicinformation.kinship.update.query',
            'basicinformation.kinship.destroy.query',
            'basicinformation.statuses.edit.query',
            'basicinformation.statuses.update.query',
            'basicinformation.statuses.destroy.query',
            'basicinformation.entries.edit.query',
            'basicinformation.entries.update.query',
            'basicinformation.entries.destroy.query',
            'basicinformation.events.edit.query',
            'basicinformation.events.update.query',
            'basicinformation.events.destroy.query',
            'basicinformation.socialinst.edit.query',
            'basicinformation.socialinst.update.query',
            'basicinformation.socialinst.destroy.query',
            'basicinformation.offices.edit.query',
            'basicinformation.offices.update.query',
            'basicinformation.offices.destroy.query',
        ];

        foreach ($routes as $routeName) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($routeName),
                "Route '{$routeName}' should be defined"
            );
        }
    }

    /**
     * 測試新路由策略：保留 edit/update，移除舊 destroy resource 路由
     */
    #[Test]
    public function resource_routes_follow_new_destroy_policy(): void {
        $keptResourceRoutes = [
            'basicinformation.offices.edit',
            'basicinformation.offices.update',
            'basicinformation.altnames.edit',
            'basicinformation.altnames.update',
        ];

        foreach ($keptResourceRoutes as $routeName) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($routeName),
                "Route '{$routeName}' should be defined"
            );
        }

        $removedDestroyRoutes = [
            'basicinformation.offices.destroy',
            'basicinformation.altnames.destroy',
        ];

        foreach ($removedDestroyRoutes as $routeName) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Route::has($routeName),
                "Legacy route '{$routeName}' should be removed"
            );
        }
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
            'basicinformation.offices.edit.query',
            ['id' => 1],
            $pk,
            false
        );
        $this->assertStringStartsWith('/basicinformation', $relativeUrl);

        // 絕對 URL 應該包含 host
        $absoluteUrl = CompositePrimaryKey::buildUrl(
            'basicinformation.offices.edit.query',
            ['id' => 1],
            $pk,
            true
        );
        $this->assertStringContainsString('://', $absoluteUrl);
    }
}
