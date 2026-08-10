<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Legacy Blade 表單下架閘門（LegacyBladeFormGate）回歸測試。
 *
 * flag=new（測試環境預設）時：legacy 表單 GET 導向 /app 對應頁、寫入端點回 410；
 * flag=old 時原樣放行（完整回退，不需改碼）。部分 legacy 路由掛有 auth middleware
 * （先於路由別名 middleware 執行），故以登入使用者驗證閘門行為。
 */
class LegacyBladeFormGateTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function actingAsActiveUser(): User {
        $user = User::forceCreate([
            'name' => 'gate-tester',
            'email' => 'gate-tester@example.com',
            'confirmation_token' => 'token-123',
            'is_active' => 1,
            'is_admin' => 1,
        ]);
        $this->actingAs($user);

        return $user;
    }

    // ── flag=new：GET 導向 /app ─────────────────────────────

    #[Test]
    public function testLegacySubresourceCreateFormRedirectsToReactEditor(): void {
        $this->actingAsActiveUser();

        $this->get('/basicinformation/1000/altnames/create')
            ->assertRedirect('/app/basicinformation/1000/altnames/edit-v2');
    }

    #[Test]
    public function testLegacySubresourceEditQueryRedirectsToReactEditorPreservingPk(): void {
        $this->actingAsActiveUser();

        $response = $this->get('/basicinformation/1000/altnames/edit?c_alt_name_chn=%E5%AD%90%E7%BE%8E&c_alt_name_type_code=4');
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/app/basicinformation/1000/altnames/edit-v2?', $location);
        $this->assertStringContainsString('c_alt_name_type_code=4', $location);
    }

    #[Test]
    public function testLegacySubresourceIndexRedirectsToPersonTab(): void {
        $this->get('/basicinformation/1000/altnames')
            ->assertRedirect('/app/basicinformation/1000?tab=alt_names');
    }

    #[Test]
    public function testLegacyPersonIndexRedirectsToReactList(): void {
        $this->get('/basicinformation')
            ->assertRedirect('/app/basicinformation');
    }

    #[Test]
    public function testLegacyOfficesIndexRedirectsToPostingsTab(): void {
        $this->get('/basicinformation/1000/offices')
            ->assertRedirect('/app/basicinformation/1000?tab=postings');
    }

    // ── flag=new：寫入端點 410 ──────────────────────────────

    #[Test]
    public function testLegacySubresourceStoreReturnsGone(): void {
        $this->actingAsActiveUser();

        $this->post('/basicinformation/1000/altnames', ['c_alt_name_chn' => '子美'])
            ->assertStatus(410);
    }

    #[Test]
    public function testLegacyProposalStoreReturnsGone(): void {
        // 這正是 2026-08-05 髒提案的入口：無欄位白名單，稽核欄會原樣進 payload。
        $this->post('/basicinformation/1000/altnames/proposal', [
            'c_alt_name_chn' => '子美',
            'c_created_by' => 'someone',
        ])->assertStatus(410);
    }

    #[Test]
    public function testLegacySubresourceUpdateQueryReturnsGone(): void {
        $this->actingAsActiveUser();

        $this->put('/basicinformation/1000/altnames/update?c_alt_name_chn=%E5%AD%90%E7%BE%8E&c_alt_name_type_code=4', [
            'c_sequence' => 2,
        ])->assertStatus(410);
    }

    #[Test]
    public function testLegacyPersonUpdateReturnsGone(): void {
        $this->actingAsActiveUser();

        $this->put('/basicinformation/1000', ['c_name' => 'X'])->assertStatus(410);
    }

    // ── flag=old：完整回退、原樣放行 ────────────────────────

    #[Test]
    public function testFlagOldPassesThroughToLegacyController(): void {
        config(['migration_flags.pages.basicinformation.altname' => 'old']);

        // 未登入：proposalStore 自身 abort 403（而非 410）＝已越過閘門進到控制器。
        $this->post('/basicinformation/1000/altnames/proposal', ['c_alt_name_chn' => '子美'])
            ->assertStatus(403);

        // GET 不再被導向 /app（未登入時由 legacy 頁自行處理，僅斷言非 app 導向）。
        $response = $this->get('/basicinformation/1000/altnames/create');
        $location = (string) $response->headers->get('Location');
        $this->assertStringNotContainsString('/app/basicinformation', $location);
    }
}
