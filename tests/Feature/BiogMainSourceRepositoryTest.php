<?php

namespace Tests\Feature;

use App\Models\User;
use App\Repositories\BiogMainRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BIOG_SOURCE_DATA 的 repository 層寫入行為（稽核欄、operations／原始快照）。
 *
 * 原名 BasicInformationSourcesControllerTest——那個 controller 已於 Blade 下架計畫環節 2
 * 刪除，但本檔留下的四個測試**不打任何路由**，是直接 new BiogMainRepository() 呼叫
 * sourceStoreById()／sourceUpdateById()／sourceDestroyById()，與 controller 無關，故更名保留。
 */
class BiogMainSourceRepositoryTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required for this test.');
        }

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        Config::set('prometheus.enabled', false);
        Config::set('prometheus.storage_adapter', 'memory');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
            $table->tinyInteger('c_main_source')->default(0);
            $table->tinyInteger('c_self_bio')->default(0);
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->integer('c_personid')->nullable();
            $table->tinyInteger('op_type');
            $table->string('resource');
            $table->string('resource_id')->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->timestamps();
            $table->tinyInteger('crowdsourcing_status')->default(0);
            $table->tinyInteger('rate')->default(0);
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('occurred_at');
            $table->dateTime('created_at');
            $table->string('table_name', 64);
            $table->string('operation', 16);
            $table->string('actor_type', 32);
            $table->string('actor_id', 128);
            $table->char('operation_id', 26);
            $table->json('row_pk');
            $table->string('row_pk_text', 512);
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
        });

        Auth::guard()->setUser($this->makeUser(9, 'Creator User'));
    }

    protected function tearDown(): void {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('operations');
        Schema::dropIfExists('BIOG_SOURCE_DATA');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    protected function makeUser(int $id, string $name): User {
        $user = new User();
        $user->id = $id;
        $user->name = $name;
        $user->avatar = 'avatar0.png';
        $user->is_admin = User::ROLE_SUPER_ADMIN;
        $user->is_active = User::STATUS_ACTIVE;

        return $user;
    }

    #[Test]
    public function testSourceStoreWritesAuditFieldsAndOperations(): void {
        $repository = new BiogMainRepository();
        $request = new Request([
            'c_textid' => 500,
            'c_pages' => 'p10',
            'c_notes' => 'first note',
            'c_main_source' => 1,
            'c_self_bio' => 0,
        ]);

        $result = $repository->sourceStoreById($request, 321);

        $this->assertSame('Creator User', $result['c_created_by']);
        $this->assertNotNull($result['c_created_date']);

        $row = DB::table('BIOG_SOURCE_DATA')->first();
        $this->assertSame('Creator User', $row->c_created_by);
        $this->assertNotNull($row->c_created_date);
        $this->assertNull($row->c_modified_by);
        $this->assertNull($row->c_modified_date);

        $operation = DB::table('operations')->first();
        $this->assertSame('BIOG_SOURCE_DATA', $operation->resource);
        $this->assertSame('c_personid=321&c_textid=500&c_pages=p10', $operation->resource_id);
        $this->assertEquals(1, $operation->op_type);
        $this->assertNull($operation->resource_original);

        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('Creator User', $payload['c_created_by']);
        $this->assertSame(321, $payload['c_personid']);
    }

    #[Test]
    public function testSourceStoreAndUpdateIgnoreProposalMetaFields(): void {
        $repository = new BiogMainRepository();

        $storeRequest = new Request([
            'c_textid' => 777,
            'c_pages' => 'pp-1',
            'c_notes' => 'first',
            'c_main_source' => 1,
            'c_self_bio' => 0,
            'action' => 'save',
            '__proposal_comment' => 'should not touch SQL',
        ]);

        $repository->sourceStoreById($storeRequest, 2468);
        $storedRow = DB::table('BIOG_SOURCE_DATA')->first();
        $this->assertSame('first', $storedRow->c_notes);

        Auth::guard()->setUser($this->makeUser(12, 'Updater User'));
        $updateRequest = new Request([
            'c_textid' => 777,
            'c_pages' => 'pp-1',
            'c_notes' => 'second',
            'c_main_source' => 0,
            'c_self_bio' => 1,
            'action' => 'save',
            '__proposal_comment' => 'still should not touch SQL',
        ]);

        $repository->sourceUpdateById($updateRequest, 2468, '2468-777-pp(minus)1');
        $updatedRow = DB::table('BIOG_SOURCE_DATA')->first();
        $this->assertSame('second', $updatedRow->c_notes);
    }

    #[Test]
    public function testSourceUpdatePreservesCreationAndSetsModification(): void {
        $repository = new BiogMainRepository();
        $initialRequest = new Request([
            'c_textid' => 501,
            'c_pages' => 'p20',
            'c_notes' => 'initial note',
            'c_main_source' => 0,
            'c_self_bio' => 1,
        ]);
        $repository->sourceStoreById($initialRequest, 654);

        $original = DB::table('BIOG_SOURCE_DATA')->first();

        Auth::guard()->setUser($this->makeUser(10, 'Editor User'));

        $updateRequest = new Request([
            'c_textid' => 501,
            'c_pages' => 'p20',
            'c_notes' => 'updated note',
            'c_main_source' => 1,
            'c_self_bio' => 0,
        ]);

        $repository->sourceUpdateById($updateRequest, 654, '654-501-p20');

        $row = DB::table('BIOG_SOURCE_DATA')->first();
        $this->assertSame('Creator User', $row->c_created_by);
        $this->assertSame($original->c_created_date, $row->c_created_date);
        $this->assertSame('Editor User', $row->c_modified_by);
        $this->assertNotNull($row->c_modified_date);

        $operation = DB::table('operations')->orderByDesc('id')->first();
        $this->assertSame('BIOG_SOURCE_DATA', $operation->resource);
        $this->assertSame('c_personid=654&c_textid=501&c_pages=p20', $operation->resource_id);
        $this->assertEquals(3, $operation->op_type);
        $this->assertNotNull($operation->resource_original);

        $originalPayload = json_decode($operation->resource_original, true);
        $this->assertSame(654, $originalPayload['c_personid']);
        $this->assertSame('initial note', $originalPayload['c_notes']);
        $this->assertSame(0, $originalPayload['c_main_source']);
        $this->assertSame(1, $originalPayload['c_self_bio']);
        $this->assertSame('Creator User', $originalPayload['c_created_by']);
        $this->assertNotNull($originalPayload['c_created_date']);

        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('Editor User', $payload['c_modified_by']);
        $this->assertSame('updated note', $payload['c_notes']);
    }

    #[Test]
    public function testSourceDeleteRemovesRowAndStoresOriginal(): void {
        $repository = new BiogMainRepository();
        $request = new Request([
            'c_textid' => 501,
            'c_pages' => 'p20',
            'c_notes' => 'to delete',
            'c_main_source' => 1,
            'c_self_bio' => 0,
        ]);

        $repository->sourceStoreById($request, 654);

        Auth::guard()->setUser($this->makeUser(11, 'Remover User'));

        $repository->sourceDeleteById(654, '654-501-p20');

        $this->assertSame(0, DB::table('BIOG_SOURCE_DATA')->count());

        $operation = DB::table('operations')->orderByDesc('id')->first();
        $this->assertSame('BIOG_SOURCE_DATA', $operation->resource);
        $this->assertSame(4, $operation->op_type);
        $this->assertSame('c_personid=654&c_textid=501&c_pages=p20', $operation->resource_id);
        $this->assertNull($operation->resource_original);

        $payload = json_decode($operation->resource_data, true);
        $this->assertSame(654, $payload['c_personid']);
        $this->assertSame('to delete', $payload['c_notes']);
        $this->assertSame('Creator User', $payload['c_created_by']);
        $this->assertNotNull($payload['c_created_date']);
    }

    // ─── updateQuery controller-level feature tests ───

    /**
     * 建立 users 表供 HTTP feature test 使用
     */
    private function createUsersTable(): void {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('confirmation_token')->nullable();
                $table->smallInteger('is_active')->default(0);
                $table->smallInteger('is_admin')->default(0);
                $table->rememberToken();
                $table->timestamps();
            });
        }
    }

    private function createWriterUser(): User {
        $this->createUsersTable();

        return User::forceCreate([
            'name' => 'Writer User',
            'email' => 'writer@example.com',
            'password' => bcrypt('password'),
            'confirmation_token' => 'token',
            'is_active' => 1,
            'is_admin' => User::ROLE_EXPERT,
        ]);
    }

    private function seedSourceRow(int $personid, int $textid, ?string $pages, string $notes = ''): void {
        DB::table('BIOG_SOURCE_DATA')->insert([
            'c_personid' => $personid,
            'c_textid' => $textid,
            'c_pages' => $pages,
            'c_notes' => $notes,
            'c_main_source' => 0,
            'c_self_bio' => 0,
            'c_created_by' => 'Seeder',
            'c_created_date' => now(),
        ]);
    }
}
