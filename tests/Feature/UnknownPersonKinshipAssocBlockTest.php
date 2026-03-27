<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\BiogMainRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 「未詳」人物（person ID = 0）不能新增或修改親屬、社會關係記錄
 *
 * @see https://github.com/cbdb-project/cbdb-online-main-server/issues/930
 */
class UnknownPersonKinshipAssocBlockTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->boolean('is_active')->default(0);
            $table->boolean('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function makeAdmin(): User {
        return User::factory()->create([
            'name' => 'admin',
            'email' => 'admin@example.com',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => 1,
        ]);
    }

    private function makeActiveUser(): User {
        return User::factory()->create([
            'name' => 'activeuser',
            'email' => 'active@example.com',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => 0,
        ]);
    }

    // =====================================================================
    // 親屬 (Kinship) 測試
    // =====================================================================

    #[Test]
    public function kinship_store_blocks_unknown_person_direct(): void {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(
            route('basicinformation.kinship.store', ['basicinformation' => 0]),
            [
                'c_kin_id' => 1,
                'c_kin_code' => 1,
                'c_source' => 0,
                'action' => 'save',
            ]
        );

        $response->assertRedirect();
        $this->assertStringContainsString('未詳', session('flash_notification.0.message') ?? '');
    }

    #[Test]
    public function kinship_store_blocks_unknown_person_proposal(): void {
        $user = $this->makeActiveUser();

        $response = $this->actingAs($user)->post(
            route('basicinformation.kinship.store', ['basicinformation' => 0]),
            [
                'c_kin_id' => 1,
                'c_kin_code' => 1,
                'c_source' => 0,
                'action' => 'proposal',
            ]
        );

        $response->assertRedirect();
        $this->assertStringContainsString('未詳', session('flash_notification.0.message') ?? '');
    }

    #[Test]
    public function kinship_update_query_blocks_unknown_person_direct(): void {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->patch(
            route('basicinformation.kinship.update.query', ['id' => 0])
            . '?c_personid=0&c_kin_id=1&c_kin_code=1',
            [
                'c_kin_id' => 1,
                'c_kin_code' => 1,
                'c_source' => 0,
                'action' => 'save',
            ]
        );

        $response->assertRedirect();
        $this->assertStringContainsString('未詳', session('flash_notification.0.message') ?? '');
    }

    #[Test]
    public function kinship_update_query_blocks_unknown_person_proposal(): void {
        $user = $this->makeActiveUser();

        $response = $this->actingAs($user)->patch(
            route('basicinformation.kinship.update.query', ['id' => 0])
            . '?c_personid=0&c_kin_id=1&c_kin_code=1',
            [
                'c_kin_id' => 1,
                'c_kin_code' => 1,
                'c_source' => 0,
                'action' => 'proposal',
            ]
        );

        $response->assertRedirect();
        $this->assertStringContainsString('未詳', session('flash_notification.0.message') ?? '');
    }

    #[Test]
    public function kinship_store_allows_non_unknown_person(): void {
        // 非「未詳」人物不應被此驗證攔截（會因為 Repository 找不到記錄而失敗，但不是未詳錯誤）
        $admin = $this->makeAdmin();

        $this->mockKinshipRepository();

        $response = $this->actingAs($admin)->post(
            route('basicinformation.kinship.store', ['basicinformation' => 123]),
            [
                'c_kin_id' => 1,
                'c_kin_code' => 1,
                'c_source' => 0,
                'action' => 'save',
            ]
        );

        // 不應顯示「未詳」錯誤訊息
        $this->assertStringNotContainsString('未詳', session('flash_notification.0.message') ?? '');
    }

    // =====================================================================
    // 社會關係 (Assoc) 測試
    // =====================================================================

    #[Test]
    public function assoc_store_blocks_unknown_person_direct(): void {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->post(
            route('basicinformation.assoc.store', ['basicinformation' => 0]),
            [
                'c_assoc_code' => 1,
                'c_assoc_id' => 1,
                'c_inst_code' => '0',
                'action' => 'save',
            ]
        );

        $response->assertRedirect();
        $this->assertStringContainsString('未詳', session('flash_notification.0.message') ?? '');
    }

    #[Test]
    public function assoc_store_blocks_unknown_person_proposal(): void {
        $user = $this->makeActiveUser();

        $response = $this->actingAs($user)->post(
            route('basicinformation.assoc.store', ['basicinformation' => 0]),
            [
                'c_assoc_code' => 1,
                'c_assoc_id' => 1,
                'c_inst_code' => '0',
                'action' => 'proposal',
            ]
        );

        $response->assertRedirect();
        $this->assertStringContainsString('未詳', session('flash_notification.0.message') ?? '');
    }

    #[Test]
    public function assoc_update_query_blocks_unknown_person_direct(): void {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->patch(
            route('basicinformation.assoc.update.query', ['id' => 0])
            . '?c_personid=0&c_assoc_code=1&c_assoc_id=1',
            [
                'c_assoc_code' => 1,
                'c_assoc_id' => 1,
                'c_inst_code' => '0',
                'action' => 'save',
            ]
        );

        $response->assertRedirect();
        $this->assertStringContainsString('未詳', session('flash_notification.0.message') ?? '');
    }

    #[Test]
    public function assoc_update_query_blocks_unknown_person_proposal(): void {
        $user = $this->makeActiveUser();

        $response = $this->actingAs($user)->patch(
            route('basicinformation.assoc.update.query', ['id' => 0])
            . '?c_personid=0&c_assoc_code=1&c_assoc_id=1',
            [
                'c_assoc_code' => 1,
                'c_assoc_id' => 1,
                'c_inst_code' => '0',
                'action' => 'proposal',
            ]
        );

        $response->assertRedirect();
        $this->assertStringContainsString('未詳', session('flash_notification.0.message') ?? '');
    }

    #[Test]
    public function assoc_store_allows_non_unknown_person(): void {
        $admin = $this->makeAdmin();

        $this->mockAssocRepository();

        $response = $this->actingAs($admin)->post(
            route('basicinformation.assoc.store', ['basicinformation' => 123]),
            [
                'c_assoc_code' => 1,
                'c_assoc_id' => 1,
                'c_inst_code' => '0',
                'action' => 'save',
            ]
        );

        // 不應顯示「未詳」錯誤訊息
        $this->assertStringNotContainsString('未詳', session('flash_notification.0.message') ?? '');
    }

    private function mockKinshipRepository(): void {
        $this->app->instance(BiogMainRepository::class, \Mockery::mock(BiogMainRepository::class, function ($mock) {
            $mock->shouldReceive('kinshipStoreById')->andReturn([
                'c_personid' => 123,
                'c_kin_id' => 1,
                'c_kin_code' => 1,
            ]);
        }));
    }

    private function mockAssocRepository(): void {
        $this->app->instance(BiogMainRepository::class, \Mockery::mock(BiogMainRepository::class, function ($mock) {
            $mock->shouldReceive('assocStoreById')->andReturn([
                'c_personid' => 123,
                'c_assoc_code' => 1,
                'c_assoc_id' => 1,
                'c_kin_code' => 0,
                'c_kin_id' => 0,
                'c_assoc_kin_code' => 0,
                'c_assoc_kin_id' => 0,
                'c_text_title' => '',
                'c_assoc_first_year' => '',
            ]);
        }));
    }
}
