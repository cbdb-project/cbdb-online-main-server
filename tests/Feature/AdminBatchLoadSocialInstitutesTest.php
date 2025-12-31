<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminBatchLoadSocialInstitutesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->string('avatar')->nullable();
            $table->json('settings')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('SOCIAL_INSTITUTION_NAME_CODES', function (Blueprint $table) {
            $table->integer('c_inst_name_code')->primary();
            $table->string('c_inst_name_hz')->nullable();
            $table->string('c_inst_name_py')->nullable();
        });

        Schema::create('SOCIAL_INSTITUTION_CODES', function (Blueprint $table) {
            $table->integer('c_inst_code')->primary();
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_type_code')->nullable();
            $table->integer('c_inst_begin_dy')->nullable();
            $table->integer('c_inst_floruit_dy')->nullable();
            $table->integer('c_source')->nullable();
        });

        Schema::create('SOCIAL_INSTITUTION_ADDR', function (Blueprint $table) {
            $table->integer('c_inst_name_code');
            $table->integer('c_inst_code');
            $table->integer('c_inst_addr_type_code');
            $table->integer('c_inst_addr_id');
            $table->double('inst_xcoord');
            $table->double('inst_ycoord');
            $table->integer('c_source')->nullable();
        });

        Schema::create('SOCIAL_INSTITUTION_TYPES', function (Blueprint $table) {
            $table->integer('c_inst_type_code')->primary();
            $table->string('c_inst_type_hz')->nullable();
            $table->string('c_inst_type_py')->nullable();
        });

        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
        });

        Schema::create('ADDR_CODES', function (Blueprint $table) {
            $table->integer('c_addr_id')->primary();
        });

        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid')->default(0);
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->longText('resource_data');
            $table->longText('resource_original')->nullable();
            $table->timestamps();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('operations');
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('ADDR_CODES');
        Schema::dropIfExists('DYNASTIES');
        Schema::dropIfExists('SOCIAL_INSTITUTION_TYPES');
        Schema::dropIfExists('SOCIAL_INSTITUTION_ADDR');
        Schema::dropIfExists('SOCIAL_INSTITUTION_CODES');
        Schema::dropIfExists('SOCIAL_INSTITUTION_NAME_CODES');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    protected function makeUser(array $attributes = []): User {
        $user = new User([
            'name' => 'Batch Admin',
            'email' => uniqid('admin', true).'@example.com',
            'password' => bcrypt('secret'),
            'avatar' => 'avatar0.png',
            'confirmation_token' => Str::random(10),
        ]);

        foreach ($attributes as $key => $value) {
            $user->{$key} = $value;
        }

        if (!isset($attributes['is_active'])) {
            $user->is_active = 1;
        }

        if (!isset($attributes['is_admin'])) {
            $user->is_admin = 1;
        }

        $user->save();

        return $user;
    }

    #[Test]
    public function test_admin_can_view_form(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->get(route('admin.batch-load-social-institutes'));
        $response->assertStatus(200)->assertSee('批次匯入社會機構');
    }

    #[Test]
    public function test_admin_can_upload_new_institution(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('SOCIAL_INSTITUTION_TYPES')->insert([
            'c_inst_type_code' => 10,
            'c_inst_type_hz' => '書院',
            'c_inst_type_py' => 'shuyuan',
        ]);

        DB::table('DYNASTIES')->insert([
            'c_dy' => 40,
            'c_dynasty_chn' => '清',
        ]);

        DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 7793,
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 4763,
        ]);

        $response = $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "南浦書院\t書院\t清\t浦城\t7793\t4763",
        ]);

        $response->assertRedirect(route('admin.batch-load-social-institutes'));

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_NAME_CODES', [
            'c_inst_name_code' => 1,
            'c_inst_name_hz' => '南浦書院',
            'c_inst_name_py' => 'nan pu shu yuan',
        ]);

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', [
            'c_inst_code' => 1,
            'c_inst_name_code' => 1,
            'c_inst_type_code' => 10,
            'c_inst_begin_dy' => 40,
            'c_inst_floruit_dy' => 40,
            'c_source' => 4763,
        ]);

        $this->assertDatabaseHas('SOCIAL_INSTITUTION_ADDR', [
            'c_inst_name_code' => 1,
            'c_inst_code' => 1,
            'c_inst_addr_type_code' => 1,
            'c_inst_addr_id' => 7793,
            'inst_xcoord' => 0,
            'inst_ycoord' => 0,
            'c_source' => 4763,
        ]);

        $this->assertSame(3, DB::table('operations')->count());

        $followUp = $this->get(route('admin.batch-load-social-institutes'));
        $followUp->assertSee('南浦書院')
            ->assertSee('nan pu shu yuan')
            ->assertSee('書院 / 10')
            ->assertSee('清 / 40')
            ->assertSee('是');
    }

    #[Test]
    public function test_existing_name_reuses_code(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('SOCIAL_INSTITUTION_TYPES')->insert([
            'c_inst_type_code' => 10,
            'c_inst_type_hz' => '書院',
            'c_inst_type_py' => 'shuyuan',
        ]);

        DB::table('DYNASTIES')->insert([
            'c_dy' => 40,
            'c_dynasty_chn' => '清',
        ]);

        DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 7793,
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 4763,
        ]);

        DB::table('SOCIAL_INSTITUTION_NAME_CODES')->insert([
            'c_inst_name_code' => 99,
            'c_inst_name_hz' => '南浦書院',
            'c_inst_name_py' => 'nan pu shu yuan',
        ]);

        $response = $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "南浦書院\t書院\t清\t浦城\t7793\t4763",
        ]);

        $response->assertRedirect(route('admin.batch-load-social-institutes'));

        $this->assertSame(1, DB::table('SOCIAL_INSTITUTION_NAME_CODES')->count());
        $this->assertDatabaseHas('SOCIAL_INSTITUTION_CODES', [
            'c_inst_name_code' => 99,
        ]);

        $followUp = $this->get(route('admin.batch-load-social-institutes'));
        $followUp->assertSee('否');
    }

    #[Test]
    public function test_invalid_type_results_in_error(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('DYNASTIES')->insert([
            'c_dy' => 40,
            'c_dynasty_chn' => '清',
        ]);

        DB::table('ADDR_CODES')->insert([
            'c_addr_id' => 7793,
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 4763,
        ]);

        $response = $this->post(route('admin.batch-load-social-institutes.store'), [
            'entries' => "南浦書院\t未知類型\t清\t浦城\t7793\t4763",
        ]);

        $response->assertRedirect(route('admin.batch-load-social-institutes'));
        $response->assertSessionHas('batch_errors');

        $this->assertSame(0, DB::table('SOCIAL_INSTITUTION_CODES')->count());
    }
}
