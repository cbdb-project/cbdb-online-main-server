<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CbdbApiPersonTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        \DB::connection()->getPdo()->sqliteCreateFunction('ISNULL', function ($value) {
            return $value === null ? 1 : 0;
        }, 1);

        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
            $table->string('c_name_rm')->nullable();
            $table->smallInteger('c_female')->nullable();
            $table->integer('c_dy')->nullable();
            $table->integer('c_index_addr_id')->nullable();
            $table->integer('c_birthyear')->nullable();
            $table->integer('c_by_nh_code')->nullable();
            $table->integer('c_by_nh_year')->nullable();
            $table->integer('c_deathyear')->nullable();
            $table->integer('c_dy_nh_code')->nullable();
            $table->integer('c_dy_nh_year')->nullable();
            $table->integer('c_death_age')->nullable();
            $table->integer('c_death_age_approx')->nullable();
            $table->integer('c_choronym_code')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->integer('c_index_year')->nullable();
        });

        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty')->nullable();
            $table->string('c_dynasty_chn')->nullable();
        });

        Schema::create('NIAN_HAO', function (Blueprint $table) {
            $table->integer('c_nianhao_id')->primary();
            $table->integer('c_dy')->nullable();
            $table->string('c_dynasty_chn')->nullable();
            $table->string('c_nianhao_chn')->nullable();
        });

        Schema::create('YEAR_RANGE_CODES', function (Blueprint $table) {
            $table->integer('c_range_code')->primary();
            $table->string('c_range_chn')->nullable();
        });

        Schema::create('CHORONYM_CODES', function (Blueprint $table) {
            $table->integer('c_choronym_code')->primary();
            $table->string('c_choronym_chn')->nullable();
        });

        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title_chn')->nullable();
            $table->integer('c_text_year')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('ALTNAME_CODES', function (Blueprint $table) {
            $table->integer('c_name_type_code')->primary();
            $table->string('c_name_type_desc_chn')->nullable();
        });

        Schema::create('ALTNAME_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_sequence')->default(1);
            $table->integer('c_alt_name_type_code');
            $table->string('c_alt_name')->nullable();
            $table->string('c_alt_name_chn')->nullable();
        });

        Schema::create('MERGED_PERSON_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_merged_from_personid');
            $table->text('c_notes')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
        });

        Schema::create('BIOG_ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_type')->primary();
            $table->string('c_addr_desc_chn')->nullable();
        });

        Schema::create('BIOG_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_addr_id');
            $table->integer('c_addr_type')->nullable();
            $table->integer('c_sequence')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_lastyear')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('ADDRESSES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
            $table->string('c_name_chn')->nullable();
            $table->string('belongs1_Name')->nullable();
            $table->integer('belongs1_Id')->nullable();
            $table->string('belongs2_Name')->nullable();
            $table->integer('belongs2_Id')->nullable();
            $table->string('belongs3_Name')->nullable();
            $table->integer('belongs3_Id')->nullable();
            $table->string('belongs4_Name')->nullable();
            $table->integer('belongs4_Id')->nullable();
            $table->string('belongs5_Name')->nullable();
            $table->integer('belongs5_Id')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_lastyear')->nullable();
        });

        Schema::create('ENTRY_TYPES', function (Blueprint $table) {
            $table->integer('c_entry_type')->primary();
            $table->string('c_entry_type_desc_chn')->nullable();
        });

        Schema::create('ENTRY_CODES', function (Blueprint $table) {
            $table->integer('c_entry_code')->primary();
            $table->string('c_entry_desc_chn')->nullable();
        });

        Schema::create('ENTRY_CODE_TYPE_REL', function (Blueprint $table) {
            $table->integer('c_entry_code');
            $table->integer('c_entry_type');
        });

        Schema::create('ENTRY_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_entry_code');
            $table->integer('c_year')->nullable();
            $table->integer('c_age')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->string('c_office_chn')->nullable();
            $table->string('c_office_trans')->nullable();
        });

        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_posting_id');
            $table->integer('c_sequence')->nullable();
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_lastyear')->nullable();
            $table->integer('c_fy_nh_code')->nullable();
            $table->integer('c_fy_nh_year')->nullable();
            $table->integer('c_fy_range')->nullable();
            $table->integer('c_ly_nh_code')->nullable();
            $table->integer('c_ly_nh_year')->nullable();
            $table->integer('c_ly_range')->nullable();
            $table->integer('c_appt_type_code')->nullable();
            $table->integer('c_assume_office_code')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_addr_id')->nullable();
        });

        Schema::create('APPOINTMENT_CODES', function (Blueprint $table) {
            $table->integer('c_appt_code')->primary();
            $table->string('c_appt_desc_chn')->nullable();
        });

        Schema::create('ASSUME_OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_assume_office_code')->primary();
            $table->string('c_assume_office_desc_chn')->nullable();
        });

        Schema::create('STATUS_CODES', function (Blueprint $table) {
            $table->integer('c_status_code')->primary();
            $table->string('c_status_desc_chn')->nullable();
        });

        Schema::create('STATUS_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_status_code');
            $table->integer('c_firstyear')->nullable();
            $table->integer('c_lastyear')->nullable();
        });

        Schema::create('KINSHIP_CODES', function (Blueprint $table) {
            $table->integer('c_kincode')->primary();
            $table->string('c_kinrel')->nullable();
            $table->string('c_kinrel_chn')->nullable();
        });

        Schema::create('KIN_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_kin_id');
            $table->integer('c_kin_code')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('ASSOC_CODES', function (Blueprint $table) {
            $table->integer('c_assoc_code')->primary();
            $table->string('c_assoc_desc_chn')->nullable();
        });

        Schema::create('ASSOC_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_assoc_id')->nullable();
            $table->integer('c_assoc_code')->nullable();
            $table->integer('c_assoc_first_year')->nullable();
            $table->string('c_text_title')->nullable();
            $table->integer('c_kin_id')->nullable();
            $table->integer('c_kin_code')->nullable();
            $table->integer('c_assoc_kin_id')->nullable();
            $table->integer('c_assoc_kin_code')->nullable();
            $table->integer('c_source')->nullable();
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('TEXT_ROLE_CODES', function (Blueprint $table) {
            $table->integer('c_role_id')->primary();
            $table->string('c_role_desc_chn')->nullable();
        });

        Schema::create('BIOG_TEXT_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->integer('c_role_id')->nullable();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('BIOG_TEXT_DATA');
        Schema::dropIfExists('TEXT_ROLE_CODES');
        Schema::dropIfExists('ASSOC_DATA');
        Schema::dropIfExists('ASSOC_CODES');
        Schema::dropIfExists('KIN_DATA');
        Schema::dropIfExists('KINSHIP_CODES');
        Schema::dropIfExists('STATUS_DATA');
        Schema::dropIfExists('STATUS_CODES');
        Schema::dropIfExists('ASSUME_OFFICE_CODES');
        Schema::dropIfExists('APPOINTMENT_CODES');
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('OFFICE_CODES');
        Schema::dropIfExists('ENTRY_DATA');
        Schema::dropIfExists('ENTRY_CODE_TYPE_REL');
        Schema::dropIfExists('ENTRY_CODES');
        Schema::dropIfExists('ENTRY_TYPES');
        Schema::dropIfExists('ADDRESSES');
        Schema::dropIfExists('BIOG_ADDR_DATA');
        Schema::dropIfExists('BIOG_ADDR_CODES');
        Schema::dropIfExists('ADDR_CODES');
        Schema::dropIfExists('BIOG_SOURCE_DATA');
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('CHORONYM_CODES');
        Schema::dropIfExists('YEAR_RANGE_CODES');
        Schema::dropIfExists('NIAN_HAO');
        Schema::dropIfExists('ALTNAME_DATA');
        Schema::dropIfExists('ALTNAME_CODES');
        Schema::dropIfExists('MERGED_PERSON_DATA');
        Schema::dropIfExists('DYNASTIES');
        Schema::dropIfExists('BIOG_MAIN');

        parent::tearDown();
    }

    #[Test]
    public function test_it_returns_person_profile(): void {
        $this->seedPersonFixture();

        $response = $this->getJson('/cbdbapi/person.php?id=1001&o=json');

        $response->assertStatus(200)
            ->assertJsonFragment(['DataSource' => 'CBDB'])
            ->assertJsonFragment(['Version' => '20131220']);

        $data = $response->json();
        $this->assertSame('1001', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.BasicInfo.PersonId'));
        $this->assertSame('張三', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.BasicInfo.ChName'));
        $this->assertSame('唐', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.BasicInfo.Dynasty'));

        $aliases = data_get($data, 'Package.PersonAuthority.PersonInfo.Person.PersonAliases.Alias', []);
        $addresses = data_get($data, 'Package.PersonAuthority.PersonInfo.Person.PersonAddresses.Address', []);
        $postings = data_get($data, 'Package.PersonAuthority.PersonInfo.Person.PersonPostings.Posting', []);

        $this->assertSame(1, is_array($aliases) ? count($aliases) : 0);
        $this->assertSame(1, is_array($addresses) ? count($addresses) : 0);
        $this->assertSame(1, is_array($postings) ? count($postings) : 0);

        $this->assertSame('', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.PersonSourcesAs'));
        $this->assertSame('', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.PersonEntryInfo'));
        $this->assertSame('', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.PersonSocialStatus'));
        $this->assertSame('', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.PersonKinshipInfo'));
        $this->assertSame('', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.PersonSocialAssociation'));
        $this->assertSame('', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.PersonTexts'));

        if (is_array($postings) && isset($postings[0])) {
            $this->assertArrayHasKey('FirstYearNiaohaoYear', $postings[0]);
            $this->assertSame('', $postings[0]['FirstYearNiaohaoYear']);
        }
    }

    #[Test]
    public function test_it_renders_html_page(): void {
        $this->seedPersonFixture();

        $response = $this->get('/cbdbapi/person.php?id=1001');

        $response->assertStatus(200);
        $response->assertSee('person-content');
    }

    #[Test]
    public function test_validation_error_when_id_missing(): void {
        $response = $this->getJson('/cbdbapi/person.php?o=json');

        $response->assertStatus(422);
    }

    #[Test]
    public function test_not_found_returns_404(): void {
        $response = $this->getJson('/cbdbapi/person.php?id=999999&o=json');

        $response->assertStatus(404)
            ->assertJson([
                'error' => [
                    'message' => 'Person not found.',
                ],
            ]);
    }

    #[Test]
    public function test_not_found_returns_merge_hint_when_person_was_merged(): void {
        $this->seedPersonFixture();

        \DB::table('MERGED_PERSON_DATA')->insert([
            'c_personid' => 1001,
            'c_merged_from_personid' => 2000,
            'c_notes' => 'Duplicate record merged',
            'c_created_by' => 'tester',
            'c_created_date' => '20240101',
        ]);

        $response = $this->getJson('/cbdbapi/person.php?id=2000&o=json');

        $response->assertStatus(404)
            ->assertJsonFragment(['merged_to_person_id' => 1001])
            ->assertJsonFragment(['reason' => 'Duplicate record merged']);
    }

    #[Test]
    public function test_it_returns_person_profile_by_name(): void {
        $this->seedPersonFixture();

        $response = $this->getJson('/cbdbapi/person.php?name=張三&o=json');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('1001', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.BasicInfo.PersonId'));
    }

    #[Test]
    public function test_it_returns_person_profile_by_alt_name(): void {
        $this->seedPersonFixture();

        $response = $this->getJson('/cbdbapi/person.php?name=子敬&o=json');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('1001', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.BasicInfo.PersonId'));
    }

    #[Test]
    public function test_html_request_with_name_shows_results(): void {
        $this->seedPersonFixture();

        $response = $this->get('/cbdbapi/person.php?name=張三');

        $response->assertStatus(200);
        $response->assertSee('person-search-result');
        $response->assertSee('張三');
    }

    #[Test]
    public function test_name_lookup_not_found_returns_404(): void {
        $this->seedPersonFixture();

        $response = $this->getJson('/cbdbapi/person.php?name=不存在的人&o=json');

        $response->assertStatus(404);
    }

    #[Test]
    public function test_html_request_with_name_not_found_shows_message(): void {
        $this->seedPersonFixture();

        $response = $this->get('/cbdbapi/person.php?name=不存在的人');

        $response->assertStatus(404);
        $response->assertSee('找不到符合', false);
    }

    #[Test]
    public function test_id_with_leading_zeros_json(): void {
        $this->seedPersonFixture();

        // Test with leading zeros (Wikidata format)
        $response = $this->getJson('/cbdbapi/person.php?id=0001001&o=json');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('1001', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.BasicInfo.PersonId'));
        $this->assertSame('張三', data_get($data, 'Package.PersonAuthority.PersonInfo.Person.BasicInfo.ChName'));
    }

    #[Test]
    public function test_id_with_leading_zeros_html(): void {
        $this->seedPersonFixture();

        // Test with leading zeros in HTML mode
        $response = $this->get('/cbdbapi/person.php?id=0001001');

        $response->assertStatus(200);
        $response->assertSee('person-content');
    }

    #[Test]
    public function test_id_exceeding_7_digits_returns_validation_error(): void {
        // Test ID with more than 7 digits
        $response = $this->getJson('/cbdbapi/person.php?id=12345678&o=json');

        $response->assertStatus(422)
            ->assertJson([
                'error' => [
                    'code' => 422,
                    'message' => 'Validation failed.',
                ],
            ]);
    }

    #[Test]
    public function test_invalid_id_format_returns_validation_error(): void {
        // Test non-numeric ID
        $response = $this->getJson('/cbdbapi/person.php?id=abc123&o=json');

        $response->assertStatus(422)
            ->assertJson([
                'error' => [
                    'code' => 422,
                    'message' => 'Validation failed.',
                ],
            ]);
    }

    protected function seedPersonFixture(): void {
        \DB::table('BIOG_MAIN')->insert([
            'c_personid' => 1001,
            'c_name' => 'Zhang San',
            'c_name_chn' => '張三',
            'c_name_rm' => 'Zhang San',
            'c_female' => 0,
            'c_dy' => 200,
            'c_index_addr_id' => 500,
            'c_birthyear' => 712,
            'c_deathyear' => 775,
            'c_index_year' => 750,
            'c_source' => 3001,
        ]);

        \DB::table('DYNASTIES')->insert([
            'c_dy' => 200,
            'c_dynasty' => 'Tang',
            'c_dynasty_chn' => '唐',
        ]);

        \DB::table('TEXT_CODES')->insert([
            'c_textid' => 3001,
            'c_title_chn' => '舊唐書',
        ]);

        \DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => 1001,
            'c_textid' => 3001,
            'c_pages' => '12',
            'c_notes' => 'source note',
        ]);

        \DB::table('ALTNAME_CODES')->insert([
            'c_name_type_code' => 10,
            'c_name_type_desc_chn' => '字',
        ]);

        \DB::table('ALTNAME_DATA')->insert([
            'c_personid' => 1001,
            'c_sequence' => 1,
            'c_alt_name_type_code' => 10,
            'c_alt_name' => 'Zi Jing',
            'c_alt_name_chn' => '子敬',
        ]);

        \DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 500,
            'c_name' => 'Luoyang',
            'c_name_chn' => '洛陽',
        ]);

        \DB::table('BIOG_ADDR_CODES')->insert([
            'c_addr_type' => 1,
            'c_addr_desc_chn' => '家居',
        ]);

        \DB::table('BIOG_ADDR_DATA')->insert([
            'c_personid' => 1001,
            'c_addr_id' => 500,
            'c_addr_type' => 1,
            'c_sequence' => 1,
            'c_firstyear' => 730,
            'c_lastyear' => 750,
        ]);

        \DB::table('ADDRESSES')->insert([
            'c_addr_id' => 500,
            'c_name_chn' => '洛陽縣',
            'belongs1_Name' => '洛陽府',
        ]);

        \DB::table('OFFICE_CODES')->insert([
            'c_office_id' => 900,
            'c_office_chn' => '侍中',
            'c_office_trans' => 'Attendant',
        ]);

        \DB::table('POSTED_TO_OFFICE_DATA')->insert([
            'c_personid' => 1001,
            'c_office_id' => 900,
            'c_posting_id' => 1,
            'c_sequence' => 1,
            'c_firstyear' => 740,
            'c_lastyear' => 745,
        ]);

        \DB::table('POSTED_TO_ADDR_DATA')->insert([
            'c_personid' => 1001,
            'c_posting_id' => 1,
            'c_office_id' => 900,
            'c_addr_id' => 500,
        ]);
    }
}
