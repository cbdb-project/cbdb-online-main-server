<?php

namespace Tests\Unit;

use App\Services\ProposalRevisionService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProposalRevisionServiceTest extends TestCase {
    protected function service(): ProposalRevisionService {
        return new ProposalRevisionService();
    }

    protected function biogMainRow(array $overrides = []): array {
        return array_replace([
            'c_personid' => 138841,
            'c_name_chn' => '張忠',
            'c_surname_chn' => '張',
            'c_mingzi_chn' => '忠',
            'c_female' => 0,
            'c_index_year' => 1084,
            'c_created_by' => 'seed',
            'c_created_date' => '2020-01-01 00:00:00',
            'c_modified_by' => null,
            'c_modified_date' => null,
        ], $overrides);
    }

    #[Test]
    public function hash_is_prefixed_with_sha256_and_is_deterministic(): void {
        $row = $this->biogMainRow();
        $hash1 = $this->service()->hash('BIOG_MAIN', $row);
        $hash2 = $this->service()->hash('BIOG_MAIN', $row);

        $this->assertStringStartsWith('sha256:', $hash1);
        $this->assertSame($hash1, $hash2);
    }

    #[Test]
    public function hash_is_independent_of_field_order(): void {
        $row = $this->biogMainRow();
        $reordered = array_reverse($row, true);

        $this->assertSame(
            $this->service()->hash('BIOG_MAIN', $row),
            $this->service()->hash('BIOG_MAIN', $reordered)
        );
    }

    #[Test]
    public function hash_treats_type_differences_as_equivalent(): void {
        $intRow = $this->biogMainRow(['c_personid' => 138841, 'c_female' => 0]);
        $stringRow = $this->biogMainRow(['c_personid' => '138841', 'c_female' => '0']);

        $this->assertTrue($this->service()->matches('BIOG_MAIN', $intRow, $stringRow));
    }

    #[Test]
    public function hash_treats_null_and_empty_string_as_equivalent(): void {
        $nullRow = $this->biogMainRow(['c_index_year' => null]);
        $emptyRow = $this->biogMainRow(['c_index_year' => '']);

        $this->assertTrue($this->service()->matches('BIOG_MAIN', $nullRow, $emptyRow));
    }

    #[Test]
    public function hash_trims_insignificant_whitespace(): void {
        $plain = $this->biogMainRow(['c_name_chn' => '張忠']);
        $padded = $this->biogMainRow(['c_name_chn' => ' 張忠 ']);

        $this->assertTrue($this->service()->matches('BIOG_MAIN', $plain, $padded));
    }

    #[Test]
    public function biog_main_excludes_audit_fields_from_hash(): void {
        $before = $this->biogMainRow([
            'c_created_by' => 'seed',
            'c_created_date' => '2020-01-01 00:00:00',
            'c_modified_by' => null,
            'c_modified_date' => null,
        ]);
        $afterSave = $this->biogMainRow([
            'c_created_by' => 'someone-else',
            'c_created_date' => '2026-01-01 00:00:00',
            'c_modified_by' => 'someone',
            'c_modified_date' => '2026-01-01 00:00:00',
        ]);

        $this->assertTrue(
            $this->service()->matches('BIOG_MAIN', $before, $afterSave),
            '4 個 audit 欄位（c_created_by/c_created_date/c_modified_by/c_modified_date）變動皆不應影響 BIOG_MAIN 的 revision hash'
        );
    }

    #[Test]
    public function biog_main_detects_change_in_business_field(): void {
        $before = $this->biogMainRow(['c_surname_chn' => '張']);
        $after = $this->biogMainRow(['c_surname_chn' => '章']);

        $this->assertFalse($this->service()->matches('BIOG_MAIN', $before, $after));
    }

    #[Test]
    public function unregistered_resource_falls_back_to_no_exclusion(): void {
        $before = ['c_foo' => 'bar', 'c_modified_by' => 'a'];
        $after = ['c_foo' => 'bar', 'c_modified_by' => 'b'];

        $this->assertFalse(
            $this->service()->matches('SOME_UNKNOWN_TABLE', $before, $after),
            '未註冊資源預設不排除任何欄位，任何差異都應反映在 hash 上'
        );
    }

    #[Test]
    public function hash_throws_instead_of_silently_collapsing_on_invalid_utf8(): void {
        // json_encode() 對非法 UTF-8 會失敗；沒有 JSON_THROW_ON_ERROR 時會靜默回傳 false，
        // (string) false === '' 會讓所有編碼失敗的列都算出同一個空字串的 hash，
        // 兩筆內容不同但都壞掉的列會被誤判為「相同」——衝突偵測機制絕不能這樣退化。
        $this->expectException(\JsonException::class);
        $this->service()->hash('BIOG_MAIN', ["c_name_chn" => "\xB1\x31"]);
    }

    #[Test]
    public function canonicalize_recursively_normalizes_and_sorts_nested_arrays(): void {
        $row = ['c_json' => ['b' => 1, 'a' => null]];
        $canonical = $this->service()->canonicalize('BIOG_SOURCE_DATA', $row);

        $this->assertSame(['c_json' => ['a' => '', 'b' => '1']], $canonical);
    }
}
