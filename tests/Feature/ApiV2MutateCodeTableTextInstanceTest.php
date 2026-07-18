<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * code 表 mutation（resource=text_instance_data → TEXT_INSTANCE_DATA）回歸測試。
 *
 * 場景：方志 instance 的出版年被早年匯入誤塞進 c_publisher（格式 "-YYYY"），
 * c_pub_year 卻是 0。本批訂正把年份搬回 c_pub_year（去負號）並清空 c_publisher，
 * 使其與已填好的方志樣板（如 9329 光緖蘭谿縣志：c_pub_year=1888、c_publisher=null）一致。
 *
 * 驗證 config 驅動的 ConfigCodeTableMutationHandler：
 * - allowed_fields 已納入 c_pub_year（整數）與 c_publisher（可清空）
 * - direct 立即落地；proposal 只進 operations 佇列、不動原列
 */
class ApiV2MutateCodeTableTextInstanceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('app.env', 'testing');
        $this->app['env'] = 'testing';
        config()->set('prometheus.enabled', false);
        config()->set('prometheus.storage_adapter', 'memory');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('confirmation_token')->nullable();
            $table->integer('is_active')->default(0);
            $table->integer('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->integer('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });
        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->string('operation_id', 64);
            $table->text('row_pk');
            $table->string('row_pk_text', 512)->nullable();
            $table->longText('old_data')->nullable();
            $table->longText('new_data')->nullable();
        });
        Schema::create('TEXT_INSTANCE_DATA', function (Blueprint $table) {
            $table->integer('c_textid');
            $table->smallInteger('c_text_edition_id');
            $table->smallInteger('c_text_instance_id');
            $table->string('c_instance_title_chn')->nullable();
            $table->string('c_instance_title')->nullable();
            $table->smallInteger('c_pub_year')->nullable();
            $table->string('c_publisher')->nullable();
            $table->string('c_pub_notes')->nullable();
            $table->string('c_created_by')->nullable();
            $table->dateTime('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->dateTime('c_modified_date')->nullable();
            $table->primary(['c_textid', 'c_text_edition_id', 'c_text_instance_id']);
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('TEXT_INSTANCE_DATA');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(string $email = 'ti@example.com'): User {
        return User::create([
            'name' => 'Instance Tester',
            'email' => $email,
            'confirmation_token' => 'tok',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);
    }

    /** 種一列 4452 保靖縣志：publisher 誤存 "-1872"、pub_year=0。 */
    protected function seedBaojing(): void {
        DB::table('TEXT_INSTANCE_DATA')->insert([
            'c_textid' => 4452,
            'c_text_edition_id' => 1,
            'c_text_instance_id' => 1,
            'c_instance_title_chn' => '保靖縣志',
            'c_pub_year' => 0,
            'c_publisher' => '-1872',
            'c_pub_notes' => '-1',
        ]);
    }

    #[Test]
    public function testDirectMovesPublisherYearIntoPubYearAndClearsPublisher(): void {
        $this->actingAs($this->makeUser('ti-direct@example.com'));
        $this->seedBaojing();

        $res = $this->postJson('/api/v2/mutate', [
            'resource' => 'text_instance_data',
            'person_id' => 0,
            'operation' => 'update',
            'target' => ['pk' => ['c_textid' => 4452, 'c_text_edition_id' => 1, 'c_text_instance_id' => 1]],
            'changes' => ['c_pub_year' => 1872, 'c_publisher' => null],
            'meta' => ['comment' => '方志 publisher 誤填年份訂正：-1872 → c_pub_year 1872、清空 publisher'],
        ]);

        $res->assertOk()->assertJson(['ok' => true]);

        $row = DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 4452)->first();
        $this->assertSame(1872, (int) $row->c_pub_year);
        $this->assertNull($row->c_publisher);
        $this->assertSame('保靖縣志', $row->c_instance_title_chn); // 未動的欄位不受影響
        $this->assertSame(1, DB::table('audit_log')->where('table_name', 'TEXT_INSTANCE_DATA')->where('operation', 'UPDATE')->count());
    }

    #[Test]
    public function testProposalQueuesWithoutTouchingRow(): void {
        $this->actingAs($this->makeUser('ti-proposal@example.com'));
        $this->seedBaojing();

        $this->postJson('/api/v2/mutate', [
            'resource' => 'text_instance_data',
            'mode' => 'proposal',
            'person_id' => 0,
            'operation' => 'update',
            'target' => ['pk' => ['c_textid' => 4452, 'c_text_edition_id' => 1, 'c_text_instance_id' => 1]],
            'changes' => ['c_pub_year' => 1872, 'c_publisher' => null],
        ])->assertOk()->assertJson(['ok' => true, 'mode' => 'proposal']);

        // 原列不變、審核佇列有一筆 pending。
        $row = DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 4452)->first();
        $this->assertSame(0, (int) $row->c_pub_year);
        $this->assertSame('-1872', $row->c_publisher);
        $this->assertSame(1, DB::table('operations')->where('resource', 'TEXT_INSTANCE_DATA')->count());
    }

    #[Test]
    public function testDisallowedFieldStillRejected(): void {
        // 只放行 c_pub_year/c_publisher/c_instance_title；其餘欄位仍須 422。
        $this->actingAs($this->makeUser('ti-bad@example.com'));
        $this->seedBaojing();

        $this->postJson('/api/v2/mutate', [
            'resource' => 'text_instance_data',
            'person_id' => 0,
            'operation' => 'update',
            'target' => ['pk' => ['c_textid' => 4452, 'c_text_edition_id' => 1, 'c_text_instance_id' => 1]],
            'changes' => ['c_pub_notes' => 'x'],
        ])->assertStatus(422);

        $this->assertSame('-1', DB::table('TEXT_INSTANCE_DATA')->where('c_textid', 4452)->value('c_pub_notes'));
    }
}
