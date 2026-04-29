<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 回歸測試：保護兩個對 AI 切分錯誤的容錯機制。
 *
 * 1. 地址匹配優先用 addr_str.full_text，再退到 name + parent。
 *    處理 AI 把「江南東路」誤切成 parent=「江南」、name=「東路」的情況。
 * 2. title_str / addr_str 邊界滑窗：當其中一邊命中字典、另一邊找不到時，
 *    沿原始字串切點滑窗找一個「兩邊都精確命中」的更佳切法。
 *    處理「荊湖北路轉運司判官」AI 切成 title=「判官」、addr=「荊湖北路轉運司」的情況。
 */
class PostingAutofillBoundaryTest extends TestCase {
    use RefreshDatabase;

    protected function setUp(): void {
        parent::setUp();

        config(['services.gemini.api_key' => 'test-api-key']);
        config(['services.gemini.api_endpoint' => 'https://example.com/api']);
        config(['services.gemini.model' => 'test-model']);

        DB::table('DYNASTIES')->insert([
            ['c_dy' => 15, 'c_dynasty_chn' => '宋', 'c_dynasty' => 'Song', 'c_start' => 960, 'c_end' => 1279, 'c_sort' => 15],
        ]);

        DB::table('OFFICE_CODES')->insert([
            ['c_office_id' => 2683, 'c_dy' => 15, 'c_office_chn' => '判官', 'c_office_chn_alt' => null],
            ['c_office_id' => 1003, 'c_dy' => 15, 'c_office_chn' => '轉運司判官', 'c_office_chn_alt' => '轉運判官'],
        ]);

        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 12824, 'c_name' => 'Jiangnan Donglu', 'c_name_chn' => '江南東路', 'c_firstyear' => 1000, 'c_lastyear' => 1200],
            ['c_addr_id' => 20001, 'c_name' => 'Jinghu Beilu', 'c_name_chn' => '荊湖北路', 'c_firstyear' => 1000, 'c_lastyear' => 1200],
            // 同名「永康」雙記錄，用於 parent 歧義消解測試
            ['c_addr_id' => 30001, 'c_name' => 'Yongkang (Wuzhou)', 'c_name_chn' => '永康', 'c_firstyear' => 1000, 'c_lastyear' => 1200],
            ['c_addr_id' => 30002, 'c_name' => 'Yongkang (Yongjiajun)', 'c_name_chn' => '永康', 'c_firstyear' => 1000, 'c_lastyear' => 1200],
            ['c_addr_id' => 30100, 'c_name' => 'Wuzhou', 'c_name_chn' => '婺州', 'c_firstyear' => 1000, 'c_lastyear' => 1200],
            ['c_addr_id' => 30200, 'c_name' => 'Yongjiajun', 'c_name_chn' => '永嘉軍', 'c_firstyear' => 1000, 'c_lastyear' => 1200],
        ]);

        DB::table('ADDR_BELONGS_DATA')->insert([
            ['c_addr_id' => 30001, 'c_belongs_to' => 30100, 'c_firstyear' => 1000, 'c_lastyear' => 1200],
            ['c_addr_id' => 30002, 'c_belongs_to' => 30200, 'c_firstyear' => 1000, 'c_lastyear' => 1200],
        ]);
    }

    /**
     * 建立宋代測試人物以驅動朝代過濾。
     */
    protected function makeSongPerson(int $personId = 88888): void {
        DB::table('BIOG_MAIN')->insert([
            ['c_personid' => $personId, 'c_dy' => 15],
        ]);
    }

    protected function makeUser(): User {
        return User::factory()->create([
            'is_active' => 1,
            'is_admin' => 1,
        ]);
    }

    protected function fakeAiResponse(array $posting): void {
        Http::fake([
            'https://example.com/api' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode(['postings' => [array_merge([
                            'title_str' => null,
                            'addr_str' => null,
                            'c_firstyear' => null,
                            'c_fy_nh_code' => null,
                            'c_fy_nh_year' => null,
                            'c_fy_range' => null,
                            'c_fy_intercalary' => null,
                            'c_fy_month' => null,
                            'c_fy_day' => null,
                            'c_fy_day_gz' => null,
                            'c_lastyear' => null,
                            'c_ly_nh_code' => null,
                            'c_ly_nh_year' => null,
                            'c_ly_range' => null,
                            'c_ly_intercalary' => null,
                            'c_ly_month' => null,
                            'c_ly_day' => null,
                            'c_ly_day_gz' => null,
                            'c_appt_code' => null,
                            'c_assume_office_code' => null,
                        ], $posting)]]),
                    ],
                ]],
            ], 200),
        ]);
    }

    /**
     * 案例 A：AI 把「江南東路」結構錯誤地切成 parent=「江南」、name=「東路」，
     * 但 full_text 仍是正確的「江南東路」。改造後應優先用 full_text 直接命中字典。
     */
    public function test_full_text_takes_priority_when_ai_misparses_parent_name() {
        $this->makeSongPerson();
        $this->fakeAiResponse([
            'title_str' => '轉運判官',
            'addr_str' => [
                'full_text' => '江南東路',
                'parent' => '江南',
                'name' => '東路',
                'admin_type' => '路',
            ],
        ]);

        $response = $this->actingAs($this->makeUser())->postJson('/api/ai/posting/extract', [
            'source_text' => '江南東路轉運判官',
            'person_id' => 88888,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $matched = $response->json('data.matched_fields');
        $this->assertArrayHasKey('c_addr', $matched, '地址應落在 matched（綠色），而非因為 name=「東路」匹配失敗而退到 suggested');
        $this->assertSame([12824], $matched['c_addr']['value']);
        $this->assertSame(['江南東路'], $matched['c_addr']['text']);

        // c_office_id 走 c_office_chn_alt（轉運判官 → 1003）會被精確命中
        $this->assertArrayHasKey('c_office_id', $matched);
        $this->assertSame(1003, $matched['c_office_id']['value']);
    }

    /**
     * 案例 B：AI 將「荊湖北路轉運司判官」切成 title=「判官」、addr=「荊湖北路轉運司」，
     * title 命中但 addr 落空。邊界滑窗應修正為 title=「轉運司判官」、addr=「荊湖北路」。
     */
    public function test_boundary_refiner_recovers_when_addr_eats_office_suffix() {
        $this->makeSongPerson();
        $this->fakeAiResponse([
            'title_str' => '判官',
            'addr_str' => [
                'full_text' => '荊湖北路轉運司',
                'parent' => '荊湖北路',
                'name' => '轉運司',
                'admin_type' => null,
            ],
        ]);

        $response = $this->actingAs($this->makeUser())->postJson('/api/ai/posting/extract', [
            'source_text' => '荊湖北路轉運司判官',
            'person_id' => 88888,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $matched = $response->json('data.matched_fields');

        // 邊界滑窗修正後：title 應升級到「轉運司判官」（id=1003），不再是「判官」（id=2683）
        $this->assertArrayHasKey('c_office_id', $matched);
        $this->assertSame(1003, $matched['c_office_id']['value'], '應修正為轉運司判官（1003），而非保留 AI 原本切到的判官（2683）');

        // 地址應修正為「荊湖北路」（id=20001）
        $this->assertArrayHasKey('c_addr', $matched);
        $this->assertSame([20001], $matched['c_addr']['value']);
        $this->assertSame(['荊湖北路'], $matched['c_addr']['text']);

        // 應留下 refined_from_boundary 標記，方便日後審計
        $this->assertTrue($matched['c_office_id']['refined_from_boundary'] ?? false);
        $this->assertTrue($matched['c_addr']['refined_from_boundary'] ?? false);
    }

    /**
     * 案例 C：full_text == name 但 parent 不為 null（最常見的 AI 輸出之一）。
     * 字典中有兩筆「永康」分屬不同上層，必須靠 parent 消歧。
     * 改造後若把 parent 強制設為 null（早期實作），會退化成第一筆命中或 fuzzy 結果。
     */
    public function test_full_text_path_preserves_parent_for_ambiguous_name() {
        $this->makeSongPerson();
        $this->fakeAiResponse([
            'title_str' => '判官',
            'addr_str' => [
                'full_text' => '永康',
                'parent' => '永嘉軍',
                'name' => '永康',
                'admin_type' => null,
            ],
        ]);

        $response = $this->actingAs($this->makeUser())->postJson('/api/ai/posting/extract', [
            'source_text' => '永嘉軍永康判官',
            'person_id' => 88888,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $matched = $response->json('data.matched_fields');
        $this->assertArrayHasKey('c_addr', $matched, '同名地址應透過 parent 消歧後落入 matched');
        $this->assertSame([30002], $matched['c_addr']['value'], 'parent=永嘉軍 應命中 30002，而非婺州下的 30001');
    }

    /**
     * 案例 D：邊界滑窗 ranking metric。
     * 構造一條兩個切法都「兩邊 exact 命中字典」的字串：
     *   combined = "邶州刺史"
     *     - (邶)(州刺史)：addr=「邶」(id=40001)、title=「州刺史」(id=40020)
     *     - (邶州)(刺史)：addr=「邶州」(id=40002)、title=「刺史」(id=40021)
     * 改造前 metric 為常數，會取 iteration 中最先出現的切法（addr-first 即最短地址）。
     * 改造後應依「較長地址」為主排序，挑出 (邶州)(刺史)，避免地址被短切到不夠具體。
     */
    public function test_boundary_refiner_prefers_longer_address_among_multiple_valid_splits() {
        $this->makeSongPerson();
        DB::table('ADDR_CODES')->insert([
            ['c_addr_id' => 40001, 'c_name' => 'Bei (short)', 'c_name_chn' => '邶', 'c_firstyear' => 1000, 'c_lastyear' => 1200],
            ['c_addr_id' => 40002, 'c_name' => 'Beizhou', 'c_name_chn' => '邶州', 'c_firstyear' => 1000, 'c_lastyear' => 1200],
        ]);
        DB::table('OFFICE_CODES')->insert([
            ['c_office_id' => 40020, 'c_dy' => 15, 'c_office_chn' => '州刺史', 'c_office_chn_alt' => null],
            ['c_office_id' => 40021, 'c_dy' => 15, 'c_office_chn' => '刺史', 'c_office_chn_alt' => null],
        ]);

        // AI 把 title 命中為「刺史」，但 addr.full_text="邶州刺史" 不在字典 → 觸發 refiner
        $this->fakeAiResponse([
            'title_str' => '刺史',
            'addr_str' => [
                'full_text' => '邶州刺史',
                'parent' => null,
                'name' => '邶州刺史',
                'admin_type' => null,
            ],
        ]);

        $response = $this->actingAs($this->makeUser())->postJson('/api/ai/posting/extract', [
            'source_text' => '邶州刺史',
            'person_id' => 88888,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $matched = $response->json('data.matched_fields');

        $this->assertSame([40002], $matched['c_addr']['value'], 'ranking metric 應挑較長地址 邶州（40002），而非短地址 邶（40001）');
        $this->assertSame(40021, $matched['c_office_id']['value'], '較長地址對應的官名應為「刺史」（40021）');
        $this->assertTrue($matched['c_addr']['refined_from_boundary'] ?? false);
    }

    /**
     * 案例 E：sourceText 中 addr 出現多次，refiner 應收斂到「離 title 最近」那一處，
     * 而不是 mb_strpos 找到的第一個。
     */
    public function test_boundary_refiner_picks_closest_addr_occurrence_in_long_source() {
        $this->makeSongPerson();
        // sourceText: 「荊湖北路糧運記事 ... 荊湖北路轉運司判官」
        // 第一次出現的「荊湖北路」距離「判官」較遠，第二次貼著「轉運司判官」。
        $sourceText = '荊湖北路糧運記事，後遷荊湖北路轉運司判官';

        $this->fakeAiResponse([
            'title_str' => '判官',
            'addr_str' => [
                'full_text' => '荊湖北路轉運司',
                'parent' => '荊湖北路',
                'name' => '轉運司',
                'admin_type' => null,
            ],
        ]);

        $response = $this->actingAs($this->makeUser())->postJson('/api/ai/posting/extract', [
            'source_text' => $sourceText,
            'person_id' => 88888,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $matched = $response->json('data.matched_fields');

        $this->assertSame(1003, $matched['c_office_id']['value'], 'refiner 應仍能修正為轉運司判官（1003）');
        $this->assertSame([20001], $matched['c_addr']['value']);
    }

    /**
     * 案例 F：sourceText 過長且 addr 與 title 距離過大時，refiner 應放棄，
     * 不會在大段落上做 O(n) 滑窗導致 DB 查詢爆量。
     */
    public function test_boundary_refiner_aborts_when_span_exceeds_cap() {
        $this->makeSongPerson();
        // addr 只有官署前綴、office 可命中，觸發單邊命中的 refiner；
        // 「荊湖北路轉運司」在開頭，「判官」相距 50+ 字，union span > 25 char cap。
        $filler = str_repeat('某', 50);
        $sourceText = '荊湖北路轉運司'.$filler.'判官';

        $this->fakeAiResponse([
            'title_str' => '判官',
            'addr_str' => [
                'full_text' => '荊湖北路轉運司',
                'parent' => '荊湖北路',
                'name' => '轉運司',
                'admin_type' => null,
            ],
        ]);

        $response = $this->actingAs($this->makeUser())->postJson('/api/ai/posting/extract', [
            'source_text' => $sourceText,
            'person_id' => 88888,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $matched = $response->json('data.matched_fields');
        $suggested = $response->json('data.suggested_fields');

        // refiner 跳過，AI 原本的 office 命中（判官，2683）保留
        $this->assertSame(2683, $matched['c_office_id']['value']);
        $this->assertArrayHasKey('c_addr', $suggested);
        $this->assertArrayNotHasKey('value', $suggested['c_addr']);
        // refiner 沒跑，所以不會留下 refined_from_boundary 標記
        $this->assertArrayNotHasKey('refined_from_boundary', $matched['c_office_id']);
    }

    /**
     * 邊界滑窗不得在 AI 已經切對時越權改動結果。
     */
    public function test_boundary_refiner_skipped_when_both_sides_already_matched() {
        $this->makeSongPerson();
        $this->fakeAiResponse([
            'title_str' => '轉運判官',
            'addr_str' => [
                'full_text' => '江南東路',
                'parent' => null,
                'name' => '江南東路',
                'admin_type' => '路',
            ],
        ]);

        $response = $this->actingAs($this->makeUser())->postJson('/api/ai/posting/extract', [
            'source_text' => '江南東路轉運判官',
            'person_id' => 88888,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $matched = $response->json('data.matched_fields');

        $this->assertSame([12824], $matched['c_addr']['value']);
        $this->assertSame(1003, $matched['c_office_id']['value']);
        // 兩邊原本就成功，不應觸發滑窗改寫
        $this->assertArrayNotHasKey('refined_from_boundary', $matched['c_office_id']);
        $this->assertArrayNotHasKey('refined_from_boundary', $matched['c_addr']);
    }
}
