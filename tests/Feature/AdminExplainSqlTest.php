<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ── 2026-09-15（Blade 下架環節 4b-3）─────────────────────────────
 * 本檔全部改打 React 端（`/app/admin/explainsql`）。
 *
 * ⚠️ **POST 這一側不是單純換 URI**：legacy 的 `explain()` 與 React 的 `appExplain()` 是
 * **兩個方法**（共用 `runExplain()`），legacy 回 Blade 視圖、React 回同一個 Inertia 元件
 * 並把結果放進 `results`／`columns`／`error` props。所以 `assertSee('MySQL EXPLAIN')`
 * 這類掃畫面文案的斷言換成斷言 props——那些文案在 React 版是前端的翻譯鍵。
 */
class AdminExplainSqlTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('mcp.cbdb.allowed_tables', ['sample']);
        config()->set('mcp.cbdb.max_limit', 100);

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
    }

    protected function tearDown(): void {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(array $attributes = []): User {
        $user = new User([
            'name' => 'Tester',
            'email' => uniqid().'@example.com',
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
    public function test_guest_is_redirected_to_login(): void {
        $response = $this->get('/app/admin/explainsql');
        $response->assertRedirect(route('login'));
    }

    #[Test]
    public function test_non_admin_is_forbidden(): void {
        $user = $this->makeUser(['is_admin' => 0]);

        $this->actingAs($user);
        $response = $this->get('/app/admin/explainsql');
        $response->assertStatus(403);
    }

    #[Test]
    public function test_admin_can_view_form(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        // 原本 assertSee('SQL 語句')——Blade 的欄位標籤。React 版走翻譯鍵；
        // 伺服器端能負責的是「回的是那個 Inertia 頁、初始狀態乾淨、而且送出端點有傳下去」。
        $this->get('/app/admin/explainsql')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ExplainSql/Index')
                ->where('sql', '')
                ->where('results', null)
                ->where('error', null)
                ->where('explain_url', route('app.admin.explainsql.explain', [], false)));
    }

    #[Test]
    public function test_admin_can_run_explain(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::statement('CREATE TABLE sample (id INTEGER)');

        // 原本 assertSee 兩串畫面文案（區塊標題與筆數說明），它們在 React 版是翻譯鍵。
        // 這裡改成斷言**實際的 EXPLAIN 結果**有回來——比原本強：`assertSee('MySQL EXPLAIN')`
        // 只要標題印出來就綠，連結果是空的、或根本沒跑成功都看不出來。
        $props = [];

        $this->withSession(['locale' => 'zh-TW'])
            ->post('/app/admin/explainsql', ['sql' => 'SELECT * FROM sample'])
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $page->component('Admin/ExplainSql/Index');
                $props = $page->toArray()['props'];
            });

        $this->assertSame('SELECT * FROM sample', $props['sql']);
        $this->assertNull($props['error']);
        // 筆數不寫死：EXPLAIN 的輸出列數依 driver／版本而異（SQLite 這裡是 9 列，
        // MariaDB 是 1 列）。要守的是「真的跑出結果、而且欄位表也一起回來」。
        $this->assertNotEmpty($props['results'], 'EXPLAIN 應該回傳結果列');
        $this->assertNotEmpty($props['columns'], '少了 columns，前端畫不出表頭');
    }

    #[Test]
    public function test_explain_rejects_non_allowlisted_tables(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        DB::statement('CREATE TABLE sample (id INTEGER)');
        DB::statement('CREATE TABLE users2 (id INTEGER)');

        // 白名單拒絕：訊息本身是後端產生的（非翻譯鍵），所以照原樣比對，只是改讀 prop。
        $this->post('/app/admin/explainsql', ['sql' => 'SELECT * FROM users2'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('error', "Table 'users2' is not in allowlist")
                ->where('results', null));
    }

    #[Test]
    public function test_explain_rejects_non_select_queries(): void {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->post('/app/admin/explainsql', ['sql' => 'DELETE FROM sample'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('error', 'Only SELECT / WITH queries are allowed.')
                ->where('results', null));
    }
}
