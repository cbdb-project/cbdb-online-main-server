<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * #79：/api/v2/relationship/opposite-edges 對面互逆鏡像偵測端點。
 * 缺邊(count 0)/正常(1)/多條(>1)；僅 canWriteDirectly() 觸發；純讀取。
 */
class ApiV2OppositeEdgesTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name')->nullable();
            $t->string('email')->unique();
            $t->string('confirmation_token')->nullable();
            $t->integer('is_active')->default(0);
            $t->integer('is_admin')->default(0);
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamps();
        });

        Schema::create('KINSHIP_CODES', function (Blueprint $t) {
            $t->integer('c_kincode')->primary();
            $t->integer('c_kin_pair1')->nullable();
            $t->integer('c_kin_pair2')->nullable();
        });
        DB::table('KINSHIP_CODES')->insert([
            ['c_kincode' => 75, 'c_kin_pair1' => 76, 'c_kin_pair2' => null],
            ['c_kincode' => 76, 'c_kin_pair1' => 75, 'c_kin_pair2' => null],
            ['c_kincode' => 77, 'c_kin_pair1' => 75, 'c_kin_pair2' => null], // 與 76 同指向 75
        ]);
        Schema::create('ASSOC_CODES', function (Blueprint $t) {
            $t->integer('c_assoc_code')->primary();
            $t->integer('c_assoc_pair')->nullable();
            $t->integer('c_assoc_pair2')->nullable();
        });
        DB::table('ASSOC_CODES')->insert([
            ['c_assoc_code' => 100, 'c_assoc_pair' => 101, 'c_assoc_pair2' => 198],
        ]);

        Schema::create('KIN_DATA', function (Blueprint $t) {
            $t->integer('c_personid');
            $t->integer('c_kin_id')->default(0);
            $t->integer('c_kin_code')->default(0);
            $t->integer('c_source')->default(0);
            $t->text('c_notes')->nullable();
            $t->text('c_autogen_notes')->nullable();
            $t->string('c_created_by')->nullable();
            $t->string('c_created_date')->nullable();
            $t->primary(['c_personid', 'c_kin_id', 'c_kin_code']);
        });
        Schema::create('ASSOC_DATA', function (Blueprint $t) {
            $t->integer('c_personid');
            $t->integer('c_assoc_code')->default(0);
            $t->integer('c_assoc_id')->default(0);
            $t->string('c_text_title', 255)->default('');
            $t->integer('c_assoc_first_year')->default(-9999);
            $t->integer('c_source')->default(0);
            $t->string('c_created_by')->nullable();
            $t->string('c_created_date')->nullable();
        });
    }

    protected function tearDown(): void {
        foreach (['ASSOC_DATA', 'KIN_DATA', 'ASSOC_CODES', 'KINSHIP_CODES', 'personal_access_tokens', 'users'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function makeUser(int $role = User::ROLE_REGULAR, string $email = 'oe@example.com'): User {
        return User::create([
            'name' => 'tester', 'email' => $email, 'confirmation_token' => 't',
            'is_active' => User::STATUS_ACTIVE, 'is_admin' => $role,
        ]);
    }

    private function kinPayload(array $fwd = []): array {
        return ['resource' => 'kinship', 'person_id' => 1000, 'forward' => array_replace(
            ['opposite_id' => 2000, 'autogen_notes' => 'a', 'forward_code' => 75],
            $fwd
        )];
    }

    #[Test]
    public function testKinMissingEdge(): void {
        $this->actingAs($this->makeUser());
        $this->postJson('/api/v2/relationship/opposite-edges', $this->kinPayload())
            ->assertOk()->assertJson(['ok' => true, 'detection' => true, 'count' => 0, 'status' => 'missing']);
    }

    #[Test]
    public function testKinSingleEdge(): void {
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 76, 'c_autogen_notes' => 'a']);
        $this->actingAs($this->makeUser());
        $this->postJson('/api/v2/relationship/opposite-edges', $this->kinPayload())
            ->assertOk()->assertJson(['detection' => true, 'count' => 1, 'status' => 'single']);
    }

    #[Test]
    public function testKinMirrorWithNullAutogenNotMissing(): void {
        // 回歸（review SERIOUS）：對面鏡像 c_autogen_notes 為 NULL，前端送 ''（控制器補水）→ 應 'single' 非 'missing'。
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 76, 'c_autogen_notes' => null]);
        $this->actingAs($this->makeUser());
        $this->postJson('/api/v2/relationship/opposite-edges', $this->kinPayload(['autogen_notes' => '']))
            ->assertOk()->assertJson(['count' => 1, 'status' => 'single']);
    }

    #[Test]
    public function testKinMultipleEdges(): void {
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 76, 'c_autogen_notes' => 'a']);
        DB::table('KIN_DATA')->insert(['c_personid' => 2000, 'c_kin_id' => 1000, 'c_kin_code' => 77, 'c_autogen_notes' => 'a']);
        $this->actingAs($this->makeUser());
        $res = $this->postJson('/api/v2/relationship/opposite-edges', $this->kinPayload())->assertOk();
        $res->assertJson(['count' => 2, 'status' => 'multiple']);
        $this->assertCount(2, $res->json('edges'));
        $this->assertSame(2000, $res->json('edges.0.c_personid'));
        // 兩列確為不同碼 76/77（多碼真的各命中一列）。
        $codes = collect($res->json('edges'))->pluck('c_kin_code')->sort()->values()->all();
        $this->assertSame([76, 77], $codes);
    }

    #[Test]
    public function testAssocMultipleEdges(): void {
        // assoc 正向碼 100 的合法反向集 {101,198} 兩碼皆在對面 → multiple。
        DB::table('ASSOC_DATA')->insert(['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_text_title' => '史記', 'c_assoc_first_year' => 1080]);
        DB::table('ASSOC_DATA')->insert(['c_personid' => 2000, 'c_assoc_code' => 198, 'c_assoc_id' => 1000, 'c_text_title' => '史記', 'c_assoc_first_year' => 1080]);
        $this->actingAs($this->makeUser(email: 'oe-assoc-multi@example.com'));
        $payload = ['resource' => 'associations', 'person_id' => 1000, 'forward' => ['opposite_id' => 2000, 'text_title' => '史記', 'first_year' => 1080, 'forward_code' => 100]];
        $this->postJson('/api/v2/relationship/opposite-edges', $payload)->assertOk()->assertJson(['count' => 2, 'status' => 'multiple']);
    }

    #[Test]
    public function testAssocMissingThenSingle(): void {
        $this->actingAs($this->makeUser(email: 'oe-assoc@example.com'));
        $payload = ['resource' => 'associations', 'person_id' => 1000, 'forward' => ['opposite_id' => 2000, 'text_title' => '史記', 'first_year' => 1080, 'forward_code' => 100]];
        $this->postJson('/api/v2/relationship/opposite-edges', $payload)->assertOk()->assertJson(['count' => 0, 'status' => 'missing']);
        DB::table('ASSOC_DATA')->insert(['c_personid' => 2000, 'c_assoc_code' => 101, 'c_assoc_id' => 1000, 'c_text_title' => '史記', 'c_assoc_first_year' => 1080]);
        $this->postJson('/api/v2/relationship/opposite-edges', $payload)->assertOk()->assertJson(['count' => 1, 'status' => 'single']);
    }

    #[Test]
    public function testCrowdsourcingUserGetsNoDetection(): void {
        // 無直接寫入權限者（眾包）→ detection=false（不提示）。
        $this->actingAs($this->makeUser(User::ROLE_CROWDSOURCING, 'oe-crowd@example.com'));
        $this->postJson('/api/v2/relationship/opposite-edges', $this->kinPayload())
            ->assertOk()->assertJson(['ok' => true, 'detection' => false]);
    }

    #[Test]
    public function testUnauthenticatedRejected(): void {
        $this->postJson('/api/v2/relationship/opposite-edges', $this->kinPayload())->assertStatus(401);
    }

    #[Test]
    public function testUnsupportedResourceRejected(): void {
        $this->actingAs($this->makeUser(email: 'oe-bad@example.com'));
        $this->postJson('/api/v2/relationship/opposite-edges', ['resource' => 'altnames', 'person_id' => 1000, 'forward' => ['opposite_id' => 2, 'forward_code' => 1]])
            ->assertStatus(422);
    }

    #[Test]
    public function testIncompleteForwardRejected(): void {
        $this->actingAs($this->makeUser(email: 'oe-inc@example.com'));
        $this->postJson('/api/v2/relationship/opposite-edges', ['resource' => 'kinship', 'person_id' => 1000, 'forward' => ['opposite_id' => 2000]])
            ->assertStatus(422);
    }

    #[Test]
    public function testNonNumericCodeRejected(): void {
        // 非數字 forward_code 須 422（避免靜默轉 0 誤判「缺邊」）。
        $this->actingAs($this->makeUser(email: 'oe-bad-code@example.com'));
        $this->postJson('/api/v2/relationship/opposite-edges', $this->kinPayload(['forward_code' => 'abc']))
            ->assertStatus(422);
        // 非數字 opposite_id 亦 422。
        $this->postJson('/api/v2/relationship/opposite-edges', $this->kinPayload(['opposite_id' => '']))
            ->assertStatus(422);
    }
}
