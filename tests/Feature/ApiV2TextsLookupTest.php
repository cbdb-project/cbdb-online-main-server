<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApiV2TextsLookupTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        $this->createTextCodesTable();
        $this->seedTexts();
    }

    protected function tearDown(): void {
        Schema::dropIfExists('TEXT_CODES');
        parent::tearDown();
    }

    protected function createTextCodesTable(): void {
        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title_chn')->nullable();
            $table->string('c_title')->nullable();
            $table->string('c_title_trans')->nullable();
            $table->string('c_text_type_id')->nullable();
            $table->integer('c_text_year')->nullable();
            $table->integer('c_text_nh_code')->nullable();
            $table->integer('c_text_nh_year')->nullable();
            $table->integer('c_text_range_code')->nullable();
            $table->integer('c_bibl_cat_code')->nullable();
            $table->integer('c_extant')->nullable();
            $table->integer('c_text_country')->nullable();
            $table->integer('c_text_dy')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->string('c_url_api')->nullable();
            $table->string('c_url_api_coda')->nullable();
            $table->string('c_url_homepage')->nullable();
            $table->text('c_notes')->nullable();
            $table->string('c_title_alt_chn')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->dateTime('c_modified_date')->nullable();
        });
    }

    protected function seedTexts(): void {
        DB::table('TEXT_CODES')->insert([
            [
                'c_textid' => 7596,
                'c_title_chn' => '宋人傳記資料索引(電子版)',
                'c_title' => 'Song ren zhuan ji zi liao suo yin',
                'c_text_year' => 2000,
                'c_source' => 9999,
                'c_pages' => '3016',
                'c_url_api' => 'https://example.com/text/',
                'c_url_api_coda' => '?view=full',
                'c_url_homepage' => 'https://example.com/home',
                'c_notes' => '測試文獻',
                'c_title_alt_chn' => '宋人傳記索引',
            ],
            [
                'c_textid' => 66734,
                'c_title_chn' => '中國哲學書電子化計劃 (ctext)',
                'c_title' => 'ctext',
                'c_text_year' => 2010,
                'c_source' => null,
                'c_pages' => null,
                'c_url_api' => 'https://ctext.org/',
                'c_url_api_coda' => null,
                'c_url_homepage' => 'https://ctext.org/zhs',
                'c_notes' => null,
                'c_title_alt_chn' => null,
            ],
            [
                'c_textid' => 9999,
                'c_title_chn' => '來源文獻',
                'c_title' => 'Source Text',
                'c_text_year' => 1999,
                'c_source' => null,
                'c_pages' => null,
                'c_url_api' => 'https://example.com/source/',
                'c_url_api_coda' => null,
                'c_url_homepage' => null,
                'c_notes' => null,
                'c_title_alt_chn' => null,
            ],
        ]);
    }

    #[Test]
    public function it_returns_one_text_record_by_id(): void {
        $response = $this->getJson('/api/v2/texts/7596');

        $response->assertOk()->assertJson([
            'ok' => true,
            'data' => [
                'c_textid' => 7596,
                'c_title_chn' => '宋人傳記資料索引(電子版)',
                'c_url_api' => 'https://example.com/text/',
                'c_url_api_coda' => '?view=full',
                'c_url_homepage' => 'https://example.com/home',
                'c_source' => 9999,
                'c_source_title_chn' => '來源文獻',
            ],
        ]);
    }

    #[Test]
    public function it_returns_multiple_text_records_in_requested_order(): void {
        $response = $this->getJson('/api/v2/texts?ids=66734,7596,123456');

        $response->assertOk()
            ->assertJsonPath('meta.requested_ids.0', 66734)
            ->assertJsonPath('meta.requested_ids.1', 7596)
            ->assertJsonPath('meta.requested_ids.2', 123456)
            ->assertJsonPath('meta.found_count', 2)
            ->assertJsonPath('meta.missing_ids.0', 123456)
            ->assertJsonPath('data.0.c_textid', 66734)
            ->assertJsonPath('data.1.c_textid', 7596);
    }

    #[Test]
    public function it_requires_ids_for_batch_lookup(): void {
        $response = $this->getJson('/api/v2/texts');

        $response->assertStatus(422)
            ->assertJsonPath('errors.ids.0', 'required');
    }

    #[Test]
    public function it_returns_not_found_for_unknown_single_id(): void {
        $response = $this->getJson('/api/v2/texts/555555');

        $response->assertStatus(404)->assertJson([
            'ok' => false,
            'message' => 'TEXT_CODES 記錄不存在',
        ]);
    }
}
