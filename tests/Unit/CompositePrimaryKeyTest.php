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
}
