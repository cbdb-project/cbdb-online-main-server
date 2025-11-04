<?php

namespace Tests\Feature;

use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminBatchLoadBookTitlesTest extends TestCase
{
    protected function setUp(): void
    {
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
            $table->string('c_text_type_id')->nullable();
            $table->string('c_created_by')->nullable();
            $table->string('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->string('c_modified_date')->nullable();
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

    protected function tearDown(): void
    {
        Schema::dropIfExists('operations');
        Schema::dropIfExists('TEXT_CODES');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    protected function makeUser(array $attributes = []): User
    {
        $user = new User([
            'name' => 'Batch Admin',
            'email' => uniqid('admin', true).'@example.com',
            'password' => bcrypt('secret'),
            'avatar' => 'avatar5.png',
            'confirmation_token' => str_random(10),
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

    public function test_non_admin_cannot_access_page(): void
    {
        $user = $this->makeUser(['is_admin' => 0]);
        $this->actingAs($user);

        $response = $this->get(route('admin.batch-load-book-titles'));
        $response->assertStatus(403);
    }

    public function test_admin_can_view_form(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->get(route('admin.batch-load-book-titles'));
        $response->assertStatus(200)->assertSee('批次匯入書稿資料');
    }

    public function test_admin_can_upload_batch_entries(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "12345\t測試書名",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));

        $record = DB::table('TEXT_CODES')->where('c_title_chn', '測試書名')->first();
        $this->assertNotNull($record);
        $this->assertSame('01', $record->c_text_type_id);
        $this->assertSame('Batch Admin', $record->c_created_by);

        $operation = DB::table('operations')->where('resource', 'TEXT_CODES')->first();
        $this->assertNotNull($operation);
        $this->assertSame((string) $record->c_textid, $operation->resource_id);
    }

    public function test_invalid_lines_are_reported(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $response = $this->post(route('admin.batch-load-book-titles.store'), [
            'entries' => "abc\t\n",
        ]);

        $response->assertRedirect(route('admin.batch-load-book-titles'));

        $followUp = $this->get(route('admin.batch-load-book-titles'));
        $followUp->assertSee('匯入失敗');
        $this->assertSame(0, DB::table('TEXT_CODES')->count());
    }
}
