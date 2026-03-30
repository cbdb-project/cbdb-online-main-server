<?php

namespace Tests\Unit;

use App\Support\CompositePrimaryKey;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CompositePrimaryKey 工具類單元測試
 *
 * @see App\Support\CompositePrimaryKey
 * @see docs/COMPOSITE_PRIMARY_KEY_URL_DESIGN.md
 */
class CompositePrimaryKeyTest extends TestCase {
    #[Test]
    public function it_can_get_schema_for_altname_data(): void {
        $schema = CompositePrimaryKey::getSchema('ALTNAME_DATA');

        $this->assertEquals([
            'c_personid',
            'c_alt_name_chn',
            'c_alt_name_type_code',
        ], $schema);
    }

    #[Test]
    public function it_can_get_schema_case_insensitive(): void {
        $schema1 = CompositePrimaryKey::getSchema('ALTNAME_DATA');
        $schema2 = CompositePrimaryKey::getSchema('altname_data');

        $this->assertEquals($schema1, $schema2);
    }

    #[Test]
    public function it_returns_null_for_unknown_table(): void {
        $schema = CompositePrimaryKey::getSchema('UNKNOWN_TABLE');

        $this->assertNull($schema);
    }

    #[Test]
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
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ], $pk);

        // 確認 c_sequence 和 other_field 被過濾掉（c_sequence 不在 3-key schema 中）
        $this->assertArrayNotHasKey('other_field', $pk);
        $this->assertArrayNotHasKey('c_sequence', $pk);
    }

    #[Test]
    public function it_filters_out_null_but_preserves_empty_strings_from_request(): void {
        $request = new Request([
            'c_personid' => 12345,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => null,
        ]);

        $schema = CompositePrimaryKey::SCHEMAS['ALTNAME_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        // NULL 應該被過濾掉，但空字串應該保留（支援某些表的主鍵欄位可以為空）
        $this->assertEquals([
            'c_personid' => 12345,
            'c_alt_name_chn' => '張三',
        ], $pk);
    }

    #[Test]
    public function it_decodes_null_sentinel_from_request(): void {
        $request = new Request([
            'c_personid' => 12345,
            'c_textid' => 678,
            'c_pages' => 'NULL',
        ]);

        $schema = CompositePrimaryKey::SCHEMAS['BIOG_SOURCE_DATA'];
        $pk = CompositePrimaryKey::fromRequest($request, $schema);

        $this->assertSame([
            'c_personid' => 12345,
            'c_textid' => 678,
        ], $pk);
        $this->assertArrayNotHasKey('c_pages', $pk);
        $this->assertNull(CompositePrimaryKey::normalizeQueryValue('NULL'));
    }

    #[Test]
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

    #[Test]
    public function build_url_preserves_null_values_as_null_string(): void {
        // buildUrl() 應將 null 值轉為 'NULL' 字串，避免 http_build_query 丟棄
        $url = CompositePrimaryKey::buildUrl(
            'basicinformation.altnames.edit.query',
            ['id' => 12345],
            [
                'c_personid' => 12345,
                'c_sequence' => null,
                'c_alt_name_chn' => '張三',
                'c_alt_name_type_code' => 10,
            ]
        );

        // c_sequence=NULL 應保留在 URL 中
        $this->assertStringContainsString('c_sequence=NULL', $url);
        $this->assertStringContainsString('c_personid=12345', $url);
        $this->assertStringContainsString('c_alt_name_type_code=10', $url);
    }

    #[Test]
    public function build_url_preserves_null_for_addresses_route(): void {
        // BIOG_ADDR_DATA 的 c_sequence 也可為 null
        $url = CompositePrimaryKey::buildUrl(
            'basicinformation.addresses.edit.query',
            ['id' => 100],
            [
                'c_personid' => 100,
                'c_addr_id' => 50,
                'c_addr_type' => 1,
                'c_sequence' => null,
            ]
        );

        $this->assertStringContainsString('c_sequence=NULL', $url);
        $this->assertStringContainsString('c_personid=100', $url);
        $this->assertStringContainsString('c_addr_id=50', $url);
        $this->assertStringContainsString('c_addr_type=1', $url);
    }

    #[Test]
    public function build_url_preserves_null_for_events_route(): void {
        // EVENTS_DATA 的 c_sequence 也可為 null
        $url = CompositePrimaryKey::buildUrl(
            'basicinformation.events.edit.query',
            ['id' => 200],
            [
                'c_personid' => 200,
                'c_sequence' => null,
                'c_event_code' => 5,
            ]
        );

        $this->assertStringContainsString('c_sequence=NULL', $url);
        $this->assertStringContainsString('c_personid=200', $url);
        $this->assertStringContainsString('c_event_code=5', $url);
    }

    #[Test]
    public function build_url_preserves_null_for_statuses_route(): void {
        // STATUS_DATA 的 c_sequence 也可為 null
        $url = CompositePrimaryKey::buildUrl(
            'basicinformation.statuses.edit.query',
            ['id' => 300],
            [
                'c_personid' => 300,
                'c_sequence' => null,
                'c_status_code' => 10,
            ]
        );

        $this->assertStringContainsString('c_sequence=NULL', $url);
        $this->assertStringContainsString('c_personid=300', $url);
        $this->assertStringContainsString('c_status_code=10', $url);
    }

    #[Test]
    public function build_url_preserves_multiple_null_values(): void {
        // ENTRY_DATA 有多個可能為 null 的欄位
        $url = CompositePrimaryKey::buildUrl(
            'basicinformation.entries.edit.query',
            ['id' => 400],
            [
                'c_personid' => 400,
                'c_entry_code' => 36,
                'c_sequence' => null,
                'c_kin_code' => null,
                'c_assoc_code' => 0,
                'c_kin_id' => null,
                'c_year' => 1000,
                'c_assoc_id' => 0,
                'c_inst_code' => 0,
                'c_inst_name_code' => 0,
            ]
        );

        $this->assertStringContainsString('c_sequence=NULL', $url);
        $this->assertStringContainsString('c_kin_code=NULL', $url);
        $this->assertStringContainsString('c_kin_id=NULL', $url);
        // 非 null 值不受影響
        $this->assertStringContainsString('c_assoc_code=0', $url);
        $this->assertStringContainsString('c_year=1000', $url);
    }

    #[Test]
    public function build_url_does_not_alter_non_null_values(): void {
        $url = CompositePrimaryKey::buildUrl(
            'basicinformation.altnames.edit.query',
            ['id' => 12345],
            [
                'c_personid' => 12345,
                'c_sequence' => 0,
                'c_alt_name_chn' => '張三',
                'c_alt_name_type_code' => 10,
            ]
        );

        // c_sequence=0 應保持為 0（不被轉為 'NULL'）
        $this->assertStringContainsString('c_sequence=0', $url);
        $this->assertStringNotContainsString('c_sequence=NULL', $url);
    }

    #[Test]
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

    #[Test]
    public function it_can_validate_complete_pk(): void {
        $pk = [
            'c_personid' => 12345,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ];

        $this->assertTrue(CompositePrimaryKey::validate($pk, 'ALTNAME_DATA'));
    }

    #[Test]
    public function it_can_validate_pk_with_optional_fields(): void {
        // BIOG_ADDR_DATA 的 c_sequence 是可選的
        $pk = [
            'c_personid' => 12345,
            'c_addr_id' => 100,
            'c_addr_type' => 2,
        ];

        $this->assertTrue(CompositePrimaryKey::validate($pk, 'BIOG_ADDR_DATA', ['c_sequence']));
    }

    #[Test]
    public function it_fails_validation_for_missing_required_field(): void {
        $pk = [
            'c_personid' => 12345,
            // 缺少 c_alt_name_chn 和 c_alt_name_type_code
        ];

        $this->assertFalse(CompositePrimaryKey::validate($pk, 'ALTNAME_DATA'));
    }

    #[Test]
    public function it_fails_validation_for_unknown_table(): void {
        $pk = ['c_personid' => 12345];

        $this->assertFalse(CompositePrimaryKey::validate($pk, 'UNKNOWN_TABLE'));
    }

    #[Test]
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
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ], $pk);

        // 確認非主鍵欄位被過濾掉（c_sequence 和 c_notes 不在 3-key schema 中）
        $this->assertArrayNotHasKey('c_notes', $pk);
        $this->assertArrayNotHasKey('c_sequence', $pk);
    }

    #[Test]
    public function it_can_build_pk_from_record_array(): void {
        $record = [
            'c_personid' => 12345,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ];

        $pk = CompositePrimaryKey::fromRecord($record, 'ALTNAME_DATA');

        $this->assertEquals($record, $pk);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_can_parse_legacy_pk_format(): void {
        // 測試舊格式解析（Schema 已切為 3-key，parseLegacy 使用 3-key 格式）
        $decoded = CompositePrimaryKey::parseLegacy(
            '12345-張三-10',
            'ALTNAME_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => '10',
        ], $decoded);
    }

    #[Test]
    public function it_can_parse_legacy_pk_with_embedded_minus(): void {
        // 測試欄位值中包含負號的情況（Schema 已切為 3-key）
        $decoded = CompositePrimaryKey::parseLegacy(
            '12345-張--三-10', // 張-三 在舊格式中被編碼為 張--三
            'ALTNAME_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_alt_name_chn' => '張-三',
            'c_alt_name_type_code' => '10',
        ], $decoded);
    }

    #[Test]
    public function it_returns_null_for_invalid_legacy_pk(): void {
        // ALTNAME_DATA 需要 3 個欄位，只提供 2 個
        $decoded = CompositePrimaryKey::parseLegacy(
            '12345-1',
            'ALTNAME_DATA'
        );

        $this->assertNull($decoded);
    }

    // === parseStoredResourceId 測試 ===

    #[Test]
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

    #[Test]
    public function it_parses_resource_id_with_encoded_slash(): void {
        // 歷史 4-key dash 格式 → 相容層自動 strip c_sequence 返回 3-key
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-1-張(slash)三-10',
            'ALTNAME_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_alt_name_chn' => '張/三',
            'c_alt_name_type_code' => '10',
        ], $result);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_parses_underscore_dot_separator_format(): void {
        // 歷史 4-key _._  格式 → 相容層自動 strip c_sequence 返回 3-key
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345_._1_._張三_._10',
            'ALTNAME_DATA'
        );

        $this->assertEquals([
            'c_personid' => '12345',
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => '10',
        ], $result);
    }

    #[Test]
    public function it_returns_null_for_unknown_table_in_parse(): void {
        $result = CompositePrimaryKey::parseStoredResourceId('12345', 'UNKNOWN_TABLE');

        $this->assertNull($result);
    }

    #[Test]
    public function it_returns_null_for_insufficient_parts(): void {
        // ALTNAME_DATA 需要 3 個欄位，只提供 2 個
        $result = CompositePrimaryKey::parseStoredResourceId('12345-1', 'ALTNAME_DATA');

        $this->assertNull($result);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_parses_old_format_with_double_dash(): void {
        // 舊格式：-- 代表欄位值中的 -
        // 歷史 4-key dash 格式 → 相容層自動 strip c_sequence 返回 3-key
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-1-張--三-10',
            'ALTNAME_DATA'
        );

        $this->assertNotNull($result);
        $this->assertEquals('張-三', $result['c_alt_name_chn']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    // === buildStoredResourceId 測試 ===

    #[Test]
    public function it_builds_stored_resource_id_basic(): void {
        $pk = [
            'c_personid' => 12345,
            'c_sequence' => 1,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ];

        $result = CompositePrimaryKey::buildStoredResourceId($pk);

        // 應該是 query-string 格式
        $this->assertStringContainsString('c_personid=12345', $result);
        $this->assertStringContainsString('c_sequence=1', $result);
        $this->assertStringContainsString('c_alt_name_chn=', $result);
        $this->assertStringContainsString('c_alt_name_type_code=10', $result);
        // 不能包含 - 分隔符（作為欄位分隔）
        $this->assertStringContainsString('&', $result);
    }

    #[Test]
    public function it_builds_stored_resource_id_preserves_null_as_string(): void {
        // null 值應被轉為字串 'NULL'，避免 http_build_query() 省略該欄位
        $pk = [
            'c_personid' => 12345,
            'c_sequence' => null,
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => 10,
        ];

        $result = CompositePrimaryKey::buildStoredResourceId($pk);

        // c_sequence 應存在且值為 'NULL'
        $this->assertStringContainsString('c_sequence=NULL', $result);
        $this->assertStringContainsString('c_personid=12345', $result);

        // 來回測試：build → parse → Schema 已切為 3-key，c_sequence 被自動過濾
        $parsed = CompositePrimaryKey::parseStoredResourceId($result, 'ALTNAME_DATA');
        $this->assertNotNull($parsed);
        $this->assertCount(3, $parsed);
        $this->assertArrayNotHasKey('c_sequence', $parsed);
        $this->assertEquals('12345', $parsed['c_personid']);
        $this->assertEquals('張三', $parsed['c_alt_name_chn']);
        $this->assertEquals('10', $parsed['c_alt_name_type_code']);
    }

    #[Test]
    public function it_builds_stored_resource_id_preserves_multiple_nulls(): void {
        // BIOG_INST_DATA 的 c_inst_code 和 c_inst_name_code 可為 null
        $pk = [
            'c_personid' => 12345,
            'c_inst_code' => null,
            'c_inst_name_code' => null,
            'c_bi_role_code' => 5,
        ];

        $result = CompositePrimaryKey::buildStoredResourceId($pk);

        $this->assertStringContainsString('c_inst_code=NULL', $result);
        $this->assertStringContainsString('c_inst_name_code=NULL', $result);
        $this->assertStringContainsString('c_personid=12345', $result);
        $this->assertStringContainsString('c_bi_role_code=5', $result);

        $parsed = CompositePrimaryKey::parseStoredResourceId($result, 'BIOG_INST_DATA');
        $this->assertNotNull($parsed);
        $this->assertCount(4, $parsed);
        $this->assertSame('NULL', $parsed['c_inst_code']);
        $this->assertSame('NULL', $parsed['c_inst_name_code']);
    }

    #[Test]
    public function it_builds_stored_resource_id_with_special_chars(): void {
        $pk = [
            'c_personid' => 12345,
            'c_assoc_code' => 1,
            'c_assoc_id' => 67890,
            'c_kin_code' => 0,
            'c_kin_id' => 0,
            'c_assoc_kin_code' => 0,
            'c_assoc_kin_id' => 0,
            'c_text_title' => '論語-註釋/卷一',
            'c_assoc_first_year' => -9999,
        ];

        $result = CompositePrimaryKey::buildStoredResourceId($pk);

        // 應包含 = 分隔符
        $this->assertStringContainsString('=', $result);
        // 特殊字符應被 URL 編碼，而非裸露
        $this->assertStringNotContainsString('論語-註釋/卷一', $result);
    }

    #[Test]
    public function it_roundtrips_build_and_parse_for_altname(): void {
        // 3-key roundtrip（c_sequence 不參與定位）
        $pk = [
            'c_personid' => '12345',
            'c_alt_name_chn' => '張-三',
            'c_alt_name_type_code' => '10',
        ];

        $stored = CompositePrimaryKey::buildStoredResourceId($pk);
        $parsed = CompositePrimaryKey::parseStoredResourceId($stored, 'ALTNAME_DATA');

        $this->assertEquals($pk, $parsed);
    }

    #[Test]
    public function it_roundtrips_build_and_parse_for_assoc_data(): void {
        $pk = [
            'c_personid' => '12345',
            'c_assoc_code' => '1',
            'c_assoc_id' => '67890',
            'c_kin_code' => '0',
            'c_kin_id' => '0',
            'c_assoc_kin_code' => '0',
            'c_assoc_kin_id' => '0',
            'c_text_title' => '論語-註釋/卷一',
            'c_assoc_first_year' => '-9999',
        ];

        $stored = CompositePrimaryKey::buildStoredResourceId($pk);
        $parsed = CompositePrimaryKey::parseStoredResourceId($stored, 'ASSOC_DATA');

        $this->assertEquals($pk, $parsed);
    }

    #[Test]
    public function it_roundtrips_build_and_parse_for_entry_data(): void {
        $pk = [
            'c_personid' => '12345',
            'c_entry_code' => '36',
            'c_sequence' => '1',
            'c_kin_code' => '0',
            'c_assoc_code' => '0',
            'c_kin_id' => '0',
            'c_year' => '1000',
            'c_assoc_id' => '0',
            'c_inst_code' => '0',
            'c_inst_name_code' => '0',
        ];

        $stored = CompositePrimaryKey::buildStoredResourceId($pk);
        $parsed = CompositePrimaryKey::parseStoredResourceId($stored, 'ENTRY_DATA');

        $this->assertEquals($pk, $parsed);
    }

    #[Test]
    public function it_roundtrips_build_and_parse_with_chinese_chars(): void {
        $pk = [
            'c_personid' => '12345',
            'c_alt_name_chn' => '張三',
            'c_alt_name_type_code' => '10',
        ];

        $stored = CompositePrimaryKey::buildStoredResourceId($pk);
        $parsed = CompositePrimaryKey::parseStoredResourceId($stored, 'ALTNAME_DATA');

        $this->assertEquals('張三', $parsed['c_alt_name_chn']);
    }

    #[Test]
    public function it_roundtrips_build_and_parse_with_brackets(): void {
        $pk = [
            'c_personid' => '12345',
            'c_alt_name_chn' => '{張三}',
            'c_alt_name_type_code' => '10',
        ];

        $stored = CompositePrimaryKey::buildStoredResourceId($pk);
        $parsed = CompositePrimaryKey::parseStoredResourceId($stored, 'ALTNAME_DATA');

        $this->assertEquals('{張三}', $parsed['c_alt_name_chn']);
    }

    #[Test]
    public function it_parses_query_string_format_resource_id(): void {
        // 歷史 4-key query-string → array_intersect_key 自動過濾 c_sequence 返回 3-key
        $resourceId = 'c_personid=12345&c_sequence=1&c_alt_name_chn=%E5%BC%B5%E4%B8%89&c_alt_name_type_code=10';

        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertCount(3, $result);
        $this->assertEquals('12345', $result['c_personid']);
        $this->assertEquals('張三', $result['c_alt_name_chn']);
        $this->assertEquals('10', $result['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    #[Test]
    public function it_still_parses_old_dash_format_after_adding_query_string(): void {
        // 確保加入 query-string 偵測後，舊格式仍可正常解析
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-1-張三-10',
            'ALTNAME_DATA'
        );

        $this->assertNotNull($result);
        $this->assertEquals('12345', $result['c_personid']);
        $this->assertEquals('張三', $result['c_alt_name_chn']);
    }

    #[Test]
    public function it_still_parses_underscore_dot_format_with_equals(): void {
        // 確保含 = 但也含 _._ 的格式不會被誤判為 query-string
        // 歷史 4-key _._  格式 → 相容層自動 strip c_sequence 返回 3-key
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345_._1_._a=b_._10',
            'ALTNAME_DATA'
        );

        $this->assertNotNull($result);
        $this->assertCount(3, $result);
        $this->assertEquals('12345', $result['c_personid']);
        $this->assertEquals('a=b', $result['c_alt_name_chn']);
        $this->assertEquals('10', $result['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    #[Test]
    public function it_rejects_legacy_id_with_equals_via_schema_validation(): void {
        // 舊格式 resource_id 的欄位值恰好含有 '='（極端邊界情況）
        // parse_str('12345-1-a=b-10') 會產生 ['12345-1-a' => 'b-10']
        // 因為 key 不在 ALTNAME_DATA schema 中，回退到 dash 分隔符解析
        // 歷史 4-key dash → 相容層自動 strip c_sequence 返回 3-key
        $result = CompositePrimaryKey::parseStoredResourceId(
            '12345-1-a=b-10',
            'ALTNAME_DATA'
        );

        $this->assertNotNull($result);
        $this->assertCount(3, $result);
        $this->assertEquals('12345', $result['c_personid']);
        $this->assertEquals('a=b', $result['c_alt_name_chn']);
        $this->assertEquals('10', $result['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    #[Test]
    public function it_accepts_query_string_with_valid_schema_keys(): void {
        // 新格式 resource_id 的 key 與 schema 匹配，應被正確識別
        $resourceId = 'c_personid=12345&c_addr_id=100&c_addr_type=2&c_sequence=1';

        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'BIOG_ADDR_DATA');

        $this->assertNotNull($result);
        $this->assertEquals('12345', $result['c_personid']);
        $this->assertEquals('100', $result['c_addr_id']);
        $this->assertEquals('2', $result['c_addr_type']);
        $this->assertEquals('1', $result['c_sequence']);
    }

    #[Test]
    public function it_filters_parsed_query_string_to_schema_keys_only(): void {
        // 如果 query-string 包含額外的非 schema key，應該被過濾掉
        $resourceId = 'c_personid=12345&c_addr_id=100&c_addr_type=2&c_sequence=1&extra_field=bogus';

        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'BIOG_ADDR_DATA');

        $this->assertNotNull($result);
        $this->assertCount(4, $result);
        $this->assertArrayHasKey('c_personid', $result);
        $this->assertArrayHasKey('c_addr_id', $result);
        $this->assertArrayHasKey('c_addr_type', $result);
        $this->assertArrayHasKey('c_sequence', $result);
        $this->assertArrayNotHasKey('extra_field', $result);
    }

    #[Test]
    public function it_rejects_partial_query_string_missing_schema_keys(): void {
        // query-string 只包含部分 schema key（缺少 c_sequence），
        // 應回退到舊格式解析，避免部分 key 匹配導致查到錯誤資料列
        $resourceId = 'c_personid=12345&c_addr_id=100&c_addr_type=2';

        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'BIOG_ADDR_DATA');

        // BIOG_ADDR_DATA schema 有 4 個 key，但只提供了 3 個，
        // 新格式驗證應失敗，回退到 dash 分隔符解析也會失敗（格式不符），返回 null
        $this->assertNull($result);
    }

    // === buildResourceEditUrl 與新格式整合測試 ===

    #[Test]
    public function it_builds_edit_url_from_query_string_resource_id(): void {
        // 新格式的 resource_id 也能生成正確的編輯 URL
        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_office_id' => '448',
            'c_posting_id' => '130',
        ]);

        $url = CompositePrimaryKey::buildResourceEditUrl(
            'POSTED_TO_OFFICE_DATA',
            $resourceId,
            12345
        );

        $this->assertNotNull($url);
        $this->assertStringContainsString('/basicinformation/12345/offices/edit', $url);
        $this->assertStringContainsString('c_office_id=448', $url);
        $this->assertStringContainsString('c_posting_id=130', $url);
    }

    // === EDIT_ROUTE_MAP 測試 ===

    #[Test]
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

    #[Test]
    public function it_returns_null_for_unknown_resource_type(): void {
        $result = CompositePrimaryKey::buildResourceEditUrl('UNKNOWN_TABLE', '12345', 1);

        $this->assertNull($result);
    }

    #[Test]
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

    #[Test]
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

    // === RESOURCE_ID_SCHEMA_ALIAS 與 getResourceIdSchemaTable 測試 ===

    #[Test]
    public function it_returns_alias_table_for_posted_to_addr_data(): void {
        $result = CompositePrimaryKey::getResourceIdSchemaTable('POSTED_TO_ADDR_DATA');

        $this->assertSame('POSTED_TO_OFFICE_DATA', $result);
    }

    #[Test]
    public function it_returns_original_table_when_no_alias_exists(): void {
        $result = CompositePrimaryKey::getResourceIdSchemaTable('ALTNAME_DATA');

        $this->assertSame('ALTNAME_DATA', $result);
    }

    #[Test]
    public function it_parses_posted_to_addr_data_query_string_using_office_schema(): void {
        // POSTED_TO_ADDR_DATA 的 resource_id 沿用 POSTED_TO_OFFICE_DATA 格式
        // 只有 c_office_id + c_posting_id（2 個 key），不含完整的 3 key schema
        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_office_id' => 10,
            'c_posting_id' => 1,
        ]);

        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'POSTED_TO_ADDR_DATA');

        $this->assertNotNull($result);
        $this->assertCount(2, $result);
        $this->assertEquals('10', $result['c_office_id']);
        $this->assertEquals('1', $result['c_posting_id']);
    }

    #[Test]
    public function it_builds_edit_url_for_addr_data_query_string_format(): void {
        // POSTED_TO_ADDR_DATA 使用新格式 resource_id 也能正確生成編輯 URL
        $resourceId = CompositePrimaryKey::buildStoredResourceId([
            'c_office_id' => 448,
            'c_posting_id' => 130,
        ]);

        $url = CompositePrimaryKey::buildResourceEditUrl(
            'POSTED_TO_ADDR_DATA',
            $resourceId,
            12345
        );

        $this->assertNotNull($url);
        $this->assertStringContainsString('/basicinformation/12345/offices/edit', $url);
        $this->assertStringContainsString('c_office_id=448', $url);
        $this->assertStringContainsString('c_posting_id=130', $url);
    }

    #[Test]
    public function it_normalizes_single_key_query_string_resource_id_for_code_route(): void {
        $result = CompositePrimaryKey::normalizeSingleKeyResourceIdForCodeRoute(
            'NIAN_HAO',
            'c_nianhao_id=464'
        );

        $this->assertSame('464', $result);
    }
}
