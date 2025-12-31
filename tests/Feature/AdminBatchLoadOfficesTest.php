<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminBatchLoadOfficesTest extends TestCase {
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

        Schema::create('OFFICE_CODES', function (Blueprint $table) {
            $table->integer('c_office_id')->primary();
            $table->integer('c_dy')->nullable();
            $table->string('c_office_pinyin')->nullable();
            $table->string('c_office_trans')->nullable();
            $table->string('c_office_chn')->nullable();
            $table->integer('c_source')->nullable();
        });

        Schema::create('OFFICE_CODE_TYPE_REL', function (Blueprint $table) {
            $table->integer('c_office_id');
            $table->string('c_office_tree_id');
        });

        Schema::create('OFFICE_TYPE_TREE', function (Blueprint $table) {
            $table->string('c_office_type_node_id')->primary();
            $table->string('c_office_type_desc_chn')->nullable();
        });

        Schema::create('DYNASTIES', function (Blueprint $table) {
            $table->integer('c_dy')->primary();
            $table->string('c_dynasty_chn')->nullable();
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
        Schema::dropIfExists('DYNASTIES');
        Schema::dropIfExists('OFFICE_CODE_TYPE_REL');
        Schema::dropIfExists('OFFICE_CODES');
        Schema::dropIfExists('OFFICE_TYPE_TREE');
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

        $response = $this->get(route('admin.batch-load-offices'));
        $response->assertStatus(200)->assertSee('批次匯入官職');
    }

    #[Test]
    public function test_admin_can_upload_office(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('DYNASTIES')->insert([
            'c_dy' => 20,
            'c_dynasty_chn' => '清',
        ]);

        DB::table('OFFICE_TYPE_TREE')->insert([
            'c_office_type_node_id' => '200501',
            'c_office_type_desc_chn' => '宗人府',
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 4763,
        ]);

        $response = $this->post(route('admin.batch-load-offices.store'), [
            'entries' => "宗人府供事\tClerk in the Imperial Clan Court\t清\t200501\t宗人府\t4763",
        ]);

        $response->assertRedirect(route('admin.batch-load-offices'));

        $record = DB::table('OFFICE_CODES')->first();
        $this->assertNotNull($record);
        $this->assertSame(1, (int) $record->c_office_id);
        $this->assertSame('宗人府供事', $record->c_office_chn);
        $this->assertSame('Clerk in the Imperial Clan Court', $record->c_office_trans);
        $this->assertSame('zong ren fu gong shi', $record->c_office_pinyin);
        $this->assertSame(4763, (int) $record->c_source);

        $this->assertDatabaseHas('OFFICE_CODE_TYPE_REL', [
            'c_office_id' => 1,
            'c_office_tree_id' => '200501',
        ]);

        $this->assertSame(2, DB::table('operations')->count());

        $followUp = $this->get(route('admin.batch-load-offices'));
        $followUp->assertSee('宗人府供事')
            ->assertSee('Clerk in the Imperial Clan Court')
            ->assertSee('zong ren fu gong shi')
            ->assertSee('清 / 20')
            ->assertSee('200501')
            ->assertSee('4763');
    }

    #[Test]
    public function test_unknown_type_is_rejected(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('DYNASTIES')->insert([
            'c_dy' => 20,
            'c_dynasty_chn' => '清',
        ]);

        DB::table('TEXT_CODES')->insert([
            'c_textid' => 4763,
        ]);

        $response = $this->post(route('admin.batch-load-offices.store'), [
            'entries' => "宗人府供事\tClerk in the Imperial Clan Court\t清\t999999\t宗人府\t4763",
        ]);

        $response->assertRedirect(route('admin.batch-load-offices'));
        $response->assertSessionHas('batch_errors');

        $this->assertSame(0, DB::table('OFFICE_CODES')->count());
    }
}
