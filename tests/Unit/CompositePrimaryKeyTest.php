<?php

namespace Tests\Unit;

use App\Support\CompositePrimaryKey;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * CompositePrimaryKey 工具類單元測試
 *
 * @see App\Support\CompositePrimaryKey
 * @see docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md
 */
class CompositePrimaryKeyTest extends TestCase {
    /** @test */
    public function it_can_get_schema_for_altname_data(): void {
        $schema = CompositePrimaryKey::getSchema('ALTNAME_DATA');

        $this->assertEquals([
            'c_personid',
            'c_sequence',
            'c_alt_name_chn',
            'c_alt_name_type_code',
        ], $schema);
    }

    /** @test */
    public function it_can_get_schema_case_insensitive(): void {
        $schema1 = CompositePrimaryKey::getSchema('ALTNAME_DATA');
        $schema2 = CompositePrimaryKey::getSchema('altname_data');

        $this->assertEquals($schema1, $schema2);
    }

    /** @test */
    public function it_returns_null_for_unknown_table(): void {
        $schema = CompositePrimaryKey::getSchema('UNKNOWN_TABLE');

        $this->assertNull($schema);
    }

    /** @test */
    public function it_can_extract_pk_from_request(): void {
        $request = new Request([
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
            'other_field' => 'ignored',
        ]);

        $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        $this->assertEquals([
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ], $pk);

        // 確認 other_field 被過濾掉
        $this->assertArrayNotHasKey('other_field', $pk);
    }

    /** @test */
    public function it_filters_out_empty_values_from_request(): void {
        $request = new Request([
            'c_personid' => 12345,
            'c_sequence' => '',
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => null,
        ]);

        $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // 空字串和 null 應該被過濾掉
        $this->assertEquals([
            'c_personid' => 12345,
            'c_alt_name_chn' => '張三',
        ], $pk);
    }

    /** @test */
    public function it_can_build_query_string_correctly(): void {
        // 直接測試 http_build_query 的行為
        $params = [
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張-三',
            'c_alt_name_type_code' => 10,
        ];

        $query = http_build_query($params);

        // URL 應該包含查詢參數
        $this->assertStringContainsString('c_personid=12345', $query);
        $this->assertStringContainsString('c_sequence=1', $query);
        $this->assertStringContainsString('c_alt_name_type_code=10', $query);
        // 中文字符會被 URL 編碼
        $this->assertStringContainsString('c_alt_name_chn=', $query);
    }

    /** @test */
    public function it_encodes_special_chars_in_query_params(): void {
        // 測試特殊字符（負號、斜線等）被正確編碼
        $params = [
            'c_personid' => 12345,
            'c_text_title' => '論語-註釋/卷一',
        ];

        $query = http_build_query($params);

        // 解碼後應該還原正確值
        parse_str($query, $decoded);
        $this->assertEquals(12345, $decoded['c_personid']);
        $this->assertEquals('論語-註釋/卷一', $decoded['c_text_title']);
    }

    /** @test */
    public function it_can_validate_complete_pk(): void {
        $pk = [
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ];

        $this->assertTrue(CompositePrimaryKey::validate($pk, 'ALTNAME_DATA'));
    }

    /** @test */
    public function it_can_validate_pk_with_optional_fields(): void {
        // c_sequence 是可選的
        $pk = [
            'c_personid' => 12345,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ];

        $this->assertTrue(CompositePrimaryKey::validate($pk, 'ALTNAME_DATA', ['c_sequence']));
    }

    /** @test */
    public function it_fails_validation_for_missing_required_field(): void {
        $pk = [
            'c_personid' => 12345,
            'c_sequence' => 1,
            // 缺少 c_alt_name_chn 和 c_alt_name_type_code
        ];

        $this->assertFalse(CompositePrimaryKey::validate($pk, 'ALTNAME_DATA'));
    }

    /** @test */
    public function it_fails_validation_for_unknown_table(): void {
        $pk = ['c_personid' => 12345];

        $this->assertFalse(CompositePrimaryKey::validate($pk, 'UNKNOWN_TABLE'));
    }

    /** @test */
    public function it_can_build_pk_from_record_object(): void {
        $record = (object) [
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
            'c_notes' => '備註',
        ];

        $pk = CompositePrimaryKey::fromRecord($record, 'ALTNAME_DATA');

        $this->assertEquals([
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ], $pk);

        // 確認非主鍵欄位被過濾掉
        $this->assertArrayNotHasKey('c_notes', $pk);
    }

    /** @test */
    public function it_can_build_pk_from_record_array(): void {
        $record = [
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ];

        $pk = CompositePrimaryKey::fromRecord($record, 'ALTNAME_DATA');

        $this->assertEquals($record, $pk);
    }

    /** @test */
    public function it_handles_special_characters_in_field_values(): void {
        // 使用 http_build_query 直接測試編碼邏輯
        $params = [
            'c_text_title' => '論語-註釋/卷一',
            'c_notes' => 'test&value=1',
        ];

        $query = http_build_query($params);

        // URL 編碼後應該可以安全傳遞
        parse_str($query, $decoded);

        $this->assertEquals('論語-註釋/卷一', $decoded['c_text_title']);
        $this->assertEquals('test&value=1', $decoded['c_notes']);
    }

    /** @test */
    public function it_has_correct_schema_for_assoc_data(): void {
        $schema = CompositePrimaryKey::getSchema('ASSOC_DATA');

        // ASSOC_DATA 有 9 個複合主鍵欄位
        $this->assertCount(9, $schema);
        $this->assertContains('c_personid', $schema);
        $this->assertContains('c_assoc_code', $schema);
        $this->assertContains('c_assoc_id', $schema);
        $this->assertContains('c_text_title', $schema);
        $this->assertContains('c_assoc_first_year', $schema);
    }

    /** @test */
    public function it_can_parse_legacy_pk_format(): void {
        // 測試舊格式解析（用於向後相容）
        $decoded = CompositePrimaryKey::parseLegacy(
            '12345-1-張三-10',
            'ALTNAME_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_sequence' => '1',
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => '10',
        ], $decoded);
    }

    /** @test */
    public function it_can_parse_legacy_pk_with_embedded_minus(): void {
        // 測試欄位值中包含負號的情況
        $decoded = CompositePrimaryKey::parseLegacy(
            '12345-1-張--三-10', // 張-三 在舊格式中被編碼為 張--三
            'ALTNAME_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_sequence' => '1',
            'c_alt_name_chn' => '張-三',
            'c_alt_name_type_code' => '10',
        ], $decoded);
    }

    /** @test */
    public function it_returns_null_for_invalid_legacy_pk(): void {
        // 測試欄位數量不足的情況
        $decoded = CompositePrimaryKey::parseLegacy(
            '12345-1',
            'ALTNAME_DATA'
        );

        $this->assertNull($decoded);
    }

    // === parseStoredResourceId 測試 ===

    /** @test */
    public function it_parses_simple_numeric_resource_id(): void {
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-100-2-1',
            'BIOG_ADDR_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_addr_id' => '100',
            'c_addr_type' => '2',
            'c_sequence' => '1',
        ], $result);
    }

    /** @test */
    public function it_parses_resource_id_with_encoded_slash(): void {
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-1-張(slash)三-10',
            'ALTNAME_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_sequence' => '1',
            'c_alt_name_chn' => '張/三',
            'c_alt_name_type_code' => '10',
        ], $result);
    }

    /** @test */
    public function it_parses_resource_id_with_minus_encoding(): void {
        // 新格式：(minus) 編碼
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-1-67890-0-0-0-0-論語(slash)卷一-(minus)9999',
            'ASSOC_DATA'
        );

        $this->assertNotNull($result);
        $this->assertEquals('12345', $result['c_personid']);
        $this->assertEquals('論語/卷一', $result['c_text_title']);
        $this->assertEquals('-9999', $result['c_assoc_first_year']);
    }

    /** @test */
    public function it_parses_assoc_resource_id_with_dash_in_text_title(): void {
        // c_text_title 包含 -，以 (minus) 編碼
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-1-67890-0-0-0-0-論語(minus)註釋-(minus)9999',
            'ASSOC_DATA'
        );

        $this->assertNotNull($result);
        $this->assertEquals('論語-註釋', $result['c_text_title']);
        $this->assertEquals('-9999', $result['c_assoc_first_year']);
    }

    /** @test */
    public function it_parses_source_data_with_dash_in_pages(): void {
        // c_pages 包含 -，以 (minus) 編碼
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-448-12(minus)15',
            'BIOG_SOURCE_DATA'
        );

        $this->assertNotNull($result);
        $this->assertEquals('12345', $result['c_personid']);
        $this->assertEquals('448', $result['c_textid']);
        $this->assertEquals('12-15', $result['c_pages']);
    }

    /** @test */
    public function it_parses_underscore_dot_separator_format(): void {
        // CodesController 使用的 _._ 分隔符格式
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345_._1_._張三_._10',
            'ALTNAME_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_sequence' => '1',
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => '10',
        ], $result);
    }

    /** @test */
    public function it_returns_null_for_unknown_table_in_parse(): void {
        $result = CompositePrimaryKey::parseStoredResourceId('12345', 'UNKNOWN_TABLE');

        $this->assertNull($result);
    }

    /** @test */
    public function it_returns_null_for_insufficient_parts(): void {
        // ALTNAME_DATA 需要 4 個欄位，只提供 2 個
        $result = CompositePrimaryKey::parseStoredResourceId('12345-1', 'ALTNAME_DATA');

        $this->assertNull($result);
    }

    /** @test */
    public function it_parses_posted_to_office_data(): void {
        $result = CompositePrimaryKey::parseStoredResourceId(
            '448-130',
            'POSTED_TO_OFFICE_DATA'
        );

        $this->assertEquals([
            'c_office_id' => '448',
            'c_posting_id' => '130',
        ], $result);
    }

    /** @test */
    public function it_parses_events_data_three_fields(): void {
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-0-50',
            'EVENTS_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_sequence' => '0',
            'c_event_code' => '50',
        ], $result);
    }

    /** @test */
    public function it_parses_entry_data_ten_fields(): void {
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-36-1-0-0-0-1000-0-0-0',
            'ENTRY_DATA'
        );

        $this->assertNotNull($result);
        $this->assertCount(10, $result);
        $this->assertEquals('12345', $result['c_personid']);
        $this->assertEquals('36', $result['c_entry_code']);
        $this->assertEquals('1000', $result['c_year']);
    }

    /** @test */
    public function it_parses_biog_text_data_alias(): void {
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-448-1',
            'BIOG_TEXT_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_textid' => '448',
            'c_role_id' => '1',
        ], $result);
    }

    /** @test */
    public function it_parses_old_format_with_double_dash(): void {
        // 舊格式：-- 代表欄位值中的 -
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-1-張--三-10',
            'ALTNAME_DATA'
        );

        $this->assertNotNull($result);
        $this->assertEquals('張-三', $result['c_alt_name_chn']);
    }

    // === EDIT_ROUTE_MAP 測試 ===

    /** @test */
    public function edit_route_map_covers_all_resource_tables(): void {
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
                CompositePrimaryKey::EDIT_ROUTE_MAP,
                "EDIT_ROUTE_MAP should contain '{$table}'"
            );
        }
    }

    // === buildResourceEditUrl 測試 ===

    /** @test */
    public function it_returns_null_for_unknown_resource_type(): void {
        $result = CompositePrimaryKey::buildResourceEditUrl('UNKNOWN_TABLE', '12345', 1);

        $this->assertNull($result);
    }

    /** @test */
    public function it_builds_edit_url_for_simple_resource(): void {
        $url = CompositePrimaryKey::buildResourceEditUrl(
            'POSTED_TO_OFFICE_DATA',
            '448-130',
            12345
        );

        $this->assertNotNull($url);
        $this->assertStringContainsString('/basicinformation/12345/offices/edit', $url);
        $this->assertStringContainsString('c_office_id=448', $url);
        $this->assertStringContainsString('c_posting_id=130', $url);
    }

    /** @test */
    public function it_builds_edit_url_for_addr_data_using_office_route(): void {
        // POSTED_TO_ADDR_DATA 共用 offices 路由
        $url = CompositePrimaryKey::buildResourceEditUrl(
            'POSTED_TO_ADDR_DATA',
            '448-130',
            12345
        );

        $this->assertNotNull($url);
        $this->assertStringContainsString('/basicinformation/12345/offices/edit', $url);
        $this->assertStringContainsString('c_office_id=448', $url);
        $this->assertStringContainsString('c_posting_id=130', $url);
    }
}
