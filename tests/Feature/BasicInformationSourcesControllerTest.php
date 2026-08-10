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

class BasicInformationSourcesControllerTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $this->useLegacyPersonForms(); // 本類測 legacy Blade CRUD 行為，撥回 flag=old 越過下架閘門

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
    public function testEditViewDisplaysCreationAndModificationInfo(): void {
        $row = (object) [
            'c_personid' => 777,
            'c_textid' => 888,
            'c_pages' => 'A-1',
            'c_notes' => 'view-note',
            'c_main_source' => 1,
            'c_self_bio' => 0,
            'c_created_by' => 'Creator User',
            'c_created_date' => '2024-02-01 12:00:00',
            'c_modified_by' => 'Editor User',
            'c_modified_date' => '2024-02-03 08:30:00',
        ];

        $html = view('biogmains.sources.edit', [
            'id' => 777,
            'row' => $row,
            'res' => ['text_str' => 'Dummy Text'],
            'page_title' => 'Basicinformation',
            'page_description' => '基本信息表 出處',
            'page_url' => '/basicinformation/777/sources',
            'archer' => "<li><a href='#'>Sources</a></li>",
        ])->render();

        $this->assertStringContainsString('Creator User/2024-02-01 12:00:00', $html);
        $this->assertStringContainsString('Editor User/2024-02-03 08:30:00', $html);
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

    #[Test]
    public function updateQuery_changing_c_pages_succeeds(): void {
        $this->actingAs($this->createWriterUser());

        $this->seedSourceRow(100, 200, 'old-page');

        // 查詢參數帶原始 PK，表單 body 帶新的 c_pages
        $response = $this->patch(
            route('basicinformation.sources.update.query', ['id' => 100])
            .'?'.http_build_query(['c_personid' => 100, 'c_textid' => 200, 'c_pages' => 'old-page']),
            [
                'c_textid' => 200,
                'c_pages' => 'new-page',
                'c_notes' => '',
                'c_main_source' => 0,
                'c_self_bio' => 0,
                'action' => 'save',
            ]
        );

        $response->assertRedirect();

        // 舊記錄已被更新為新的 c_pages
        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', [
            'c_personid' => 100,
            'c_textid' => 200,
            'c_pages' => 'old-page',
        ]);
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 100,
            'c_textid' => 200,
            'c_pages' => 'new-page',
        ]);
    }

    #[Test]
    public function updateQuery_changing_c_textid_succeeds(): void {
        $this->actingAs($this->createWriterUser());

        $this->seedSourceRow(100, 300, 'page1');

        // 查詢參數帶原始 c_textid=300，表單 body 帶新的 c_textid=400
        $response = $this->patch(
            route('basicinformation.sources.update.query', ['id' => 100])
            .'?'.http_build_query(['c_personid' => 100, 'c_textid' => 300, 'c_pages' => 'page1']),
            [
                'c_textid' => 400,
                'c_pages' => 'page1',
                'c_notes' => '',
                'c_main_source' => 0,
                'c_self_bio' => 0,
                'action' => 'save',
            ]
        );

        $response->assertRedirect();

        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', [
            'c_personid' => 100,
            'c_textid' => 300,
        ]);
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 100,
            'c_textid' => 400,
            'c_pages' => 'page1',
        ]);
    }

    #[Test]
    public function updateQuery_personid_mismatch_returns_400(): void {
        $this->actingAs($this->createWriterUser());

        $this->seedSourceRow(100, 200, 'p1');

        // 路徑 id=100，但查詢參數 c_personid=999
        $response = $this->patch(
            route('basicinformation.sources.update.query', ['id' => 100])
            .'?'.http_build_query(['c_personid' => 999, 'c_textid' => 200, 'c_pages' => 'p1']),
            [
                'c_textid' => 200,
                'c_pages' => 'p1',
                'c_notes' => '',
                'c_main_source' => 0,
                'c_self_bio' => 0,
                'action' => 'save',
            ]
        );

        $response->assertStatus(400);
    }

    #[Test]
    public function updateQuery_with_null_pages_query_hits_null_row(): void {
        $this->actingAs($this->createWriterUser());

        $this->seedSourceRow(100, 200, null, 'null-page-row');
        $this->seedSourceRow(100, 200, '', 'empty-page-row');

        $response = $this->patch(
            route('basicinformation.sources.update.query', ['id' => 100])
            .'?'.http_build_query(['c_personid' => 100, 'c_textid' => 200, 'c_pages' => 'NULL']),
            [
                'c_textid' => 200,
                'c_pages' => 'new-page',
                'c_notes' => 'updated-from-null',
                'c_main_source' => 0,
                'c_self_bio' => 0,
                'action' => 'save',
            ]
        );

        $response->assertRedirect();

        $this->assertDatabaseMissing('BIOG_SOURCE_DATA', [
            'c_personid' => 100,
            'c_textid' => 200,
            'c_pages' => null,
        ]);
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 100,
            'c_textid' => 200,
            'c_pages' => '',
            'c_notes' => 'empty-page-row',
        ]);
        $this->assertDatabaseHas('BIOG_SOURCE_DATA', [
            'c_personid' => 100,
            'c_textid' => 200,
            'c_pages' => 'new-page',
            'c_notes' => 'updated-from-null',
        ]);
    }
}
