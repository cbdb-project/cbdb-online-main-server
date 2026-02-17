<?php

namespace Tests\Unit;

use App\Support\CompositePrimaryKey;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ALTNAME_DATA 3-key 相容層測試 (#834 Phase 2)
 *
 * Schema 已切為 3-key (c_personid, c_alt_name_chn, c_alt_name_type_code)。
 * 確保 parseStoredResourceId() 對歷史 4-key resource_id 自動 strip c_sequence 返回 3-key，
 * 涵蓋 query-string、_._、dash 三種分隔符。
 */
class CompositePrimaryKeyAltnameCompatTest extends TestCase {
    // -------------------------------------------------------
    // query-string 格式
    // -------------------------------------------------------

    #[Test]
    public function test_parse_altname_3key_query_string(): void {
        $resourceId = 'c_personid=123&c_alt_name_chn=%E5%BC%B5%E4%B8%89&c_alt_name_type_code=10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertSame('123', $result['c_personid']);
        $this->assertSame('張三', $result['c_alt_name_chn']);
        $this->assertSame('10', $result['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    #[Test]
    public function test_parse_altname_4key_query_string_still_works(): void {
        // 歷史 4-key query-string → array_intersect_key 自動過濾 c_sequence 返回 3-key
        $resourceId = 'c_personid=123&c_sequence=1&c_alt_name_chn=%E5%BC%B5%E4%B8%89&c_alt_name_type_code=10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertCount(3, $result);
        $this->assertSame('123', $result['c_personid']);
        $this->assertSame('張三', $result['c_alt_name_chn']);
        $this->assertSame('10', $result['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    #[Test]
    public function test_parse_altname_4key_query_string_with_null_sentinel(): void {
        // 歷史 4-key query-string（含 NULL sentinel）→ c_sequence 被過濾
        $resourceId = 'c_personid=200&c_sequence=NULL&c_alt_name_chn=%E6%B8%AC%E8%A9%A6&c_alt_name_type_code=5';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertCount(3, $result);
        $this->assertArrayNotHasKey('c_sequence', $result);
        $this->assertSame('200', $result['c_personid']);
        $this->assertSame('測試', $result['c_alt_name_chn']);
        $this->assertSame('5', $result['c_alt_name_type_code']);
    }

    // -------------------------------------------------------
    // _._  格式
    // -------------------------------------------------------

    #[Test]
    public function test_parse_altname_3key_dot_format(): void {
        $resourceId = '123_._張三_._10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertSame('123', $result['c_personid']);
        $this->assertSame('張三', $result['c_alt_name_chn']);
        $this->assertSame('10', $result['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    #[Test]
    public function test_parse_altname_4key_dot_format_still_works(): void {
        // 歷史 4-key _._  格式 → Phase 2 相容層自動 strip c_sequence 返回 3-key
        $resourceId = '123_._1_._張三_._10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertCount(3, $result);
        $this->assertSame('123', $result['c_personid']);
        $this->assertSame('張三', $result['c_alt_name_chn']);
        $this->assertSame('10', $result['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    // -------------------------------------------------------
    // dash 格式
    // -------------------------------------------------------

    #[Test]
    public function test_parse_altname_3key_dash_format(): void {
        $resourceId = '123-張三-10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertSame('123', $result['c_personid']);
        $this->assertSame('張三', $result['c_alt_name_chn']);
        $this->assertSame('10', $result['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    #[Test]
    public function test_parse_altname_3key_dash_format_with_encoded_minus(): void {
        // c_alt_name_chn 含負號：「張-三」編碼為 張(minus)三
        $resourceId = '123-張(minus)三-10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertSame('123', $result['c_personid']);
        $this->assertSame('張-三', $result['c_alt_name_chn']);
        $this->assertSame('10', $result['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    #[Test]
    public function test_parse_altname_3key_dash_format_with_old_double_dash(): void {
        // 舊格式：「張-三」編碼為 張--三
        $resourceId = '123-張--三-10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertSame('123', $result['c_personid']);
        $this->assertSame('張-三', $result['c_alt_name_chn']);
        $this->assertSame('10', $result['c_alt_name_type_code']);
    }

    #[Test]
    public function test_parse_altname_4key_dash_format_still_works(): void {
        // 歷史 4-key dash 格式 → Phase 2 相容層自動 strip c_sequence 返回 3-key
        $resourceId = '123-1-張三-10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertCount(3, $result);
        $this->assertSame('123', $result['c_personid']);
        $this->assertSame('張三', $result['c_alt_name_chn']);
        $this->assertSame('10', $result['c_alt_name_type_code']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    // -------------------------------------------------------
    // 非 ALTNAME 表不受影響
    // -------------------------------------------------------

    #[Test]
    public function test_non_altname_table_not_affected_by_3key_fallback(): void {
        // BIOG_ADDR_DATA 需要 4-key，給 3 個 parts 應返回 null
        $resourceId = '123_._456_._10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'BIOG_ADDR_DATA');

        $this->assertNull($result);
    }

    #[Test]
    public function test_non_altname_table_3part_dash_returns_null(): void {
        // BIOG_ADDR_DATA 需要 4-key，給 3 個 dash parts 也應返回 null
        $resourceId = '123-456-10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'BIOG_ADDR_DATA');

        $this->assertNull($result);
    }

    // -------------------------------------------------------
    // 邊界情境
    // -------------------------------------------------------

    #[Test]
    public function test_parse_altname_3key_query_string_with_special_chars(): void {
        // c_alt_name_chn 含斜線：「張/三」
        $resourceId = 'c_personid=123&c_alt_name_chn=%E5%BC%B5%2F%E4%B8%89&c_alt_name_type_code=10';
        $result = CompositePrimaryKey::parseStoredResourceId($resourceId, 'ALTNAME_DATA');

        $this->assertNotNull($result);
        $this->assertSame('張/三', $result['c_alt_name_chn']);
        $this->assertArrayNotHasKey('c_sequence', $result);
    }

    #[Test]
    public function test_parse_altname_2parts_returns_null(): void {
        // 只有 2 個 parts，無論哪種格式都不應匹配
        $this->assertNull(CompositePrimaryKey::parseStoredResourceId('123-10', 'ALTNAME_DATA'));
        $this->assertNull(CompositePrimaryKey::parseStoredResourceId('123_._10', 'ALTNAME_DATA'));
    }
}
