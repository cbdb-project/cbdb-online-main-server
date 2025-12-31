<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminBatchLoadBookTitlesTest extends TestCase {
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

        Schema::create('TEXT_CODES', function (Blueprint $table) {
            $table->integer('c_textid')->primary();
            $table->string('c_title_chn')->nullable();
            $table->string('c_title')->nullable();
            $table->string('c_text_type_id')->nullable();
            $table->string('c_text_dy')->nullable();
            $table->string('c_source')->nullable();
            $table->longText('c_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_dy')->nullable();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid');
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
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('TEXT_CODES');
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
    public function test_non_admin_cannot_access_page(): void {
        $user = $this->makeUser(['is_admin' => 0]);
        $this->actingAs($user);

        $response = $this->get(route('admin.batch-load-book-titles'));
        $response->assertStatus(403);
    }

    #[Test]
    public function test_admin_can_view_form(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->get(route('admin.batch-load-book-titles'));
        $response->assertStatus(200)->assertSee('批次匯入書稿資料');
    }

    #[Test]
    public function test_admin_can_upload_batch_entries(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 12345,
            'c_dy' => '88',
        ]);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "12345\t測試稿: 卷一\t54321",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));

        $record = DB::table('TEXT_CODES')->where('c_textid', 1)->first();
        $this->assertNotNull($record);
        $this->assertSame('測試稿: 卷一', $record->c_title_chn);
        $this->assertSame('ce shi gao', $record->c_title);
        $this->assertSame('01', $record->c_text_type_id);
        $this->assertSame('Batch Admin', $record->c_created_by);
        $this->assertSame('88', $record->c_text_dy);
        $this->assertSame('54321', $record->c_source);
        $this->assertMatchesRegularExpression('/^\[[0-9]{14}\]$/', $record->c_notes);
        $this->assertNull($record->c_modified_by);
        $this->assertNull($record->c_modified_date);

        $operation = DB::table('operations')->where('resource', 'TEXT_CODES')->first();
        $this->assertNotNull($operation);
        $this->assertSame((string) $record->c_textid, $operation->resource_id);
        $encoded = json_decode($operation->resource_data, true);
        $this->assertSame('ce shi gao', $encoded['c_title']);
        $this->assertSame('54321', $encoded['c_source']);
        $this->assertSame($record->c_notes, $encoded['c_notes']);

        $followUp = $this->get(route('admin.batch-load-book-titles'));
        $followUp->assertSee('本次批次編號');
        $followUp->assertSee('書名拼音');
        $followUp->assertSee('批次編號');
    }

    #[Test]
    public function test_invalid_lines_are_reported(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "abc\t\n",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));

        $followUp = $this->get(route('admin.batch-load-book-titles'));
        $followUp->assertSee('匯入失敗');
        $followUp->assertSee('未找到三欄資料');
        $this->assertSame(0, DB::table('TEXT_CODES')->count());
    }

    #[Test]
    public function test_blank_source_is_rejected(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "12345\t測試稿\t",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));
        $response->assertSessionHas('batch_errors');
        $errors = $response->getSession()->get('batch_errors', []);
        $this->assertNotEmpty($errors);

        $followUp = $this->get(route('admin.batch-load-book-titles'));
        $followUp->assertSee('匯入失敗');
        $this->assertSame(0, DB::table('TEXT_CODES')->count());
    }
}
