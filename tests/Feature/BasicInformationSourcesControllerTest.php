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

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required for this test.');
        }

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('BIOG_SOURCE_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->string('c_pages');
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

        Auth::guard()->setUser($this->makeUser(9, 'Creator User'));
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
        $this->assertSame('321-500-p10', $operation->resource_id);
        $this->assertEquals(1, $operation->op_type);
        $this->assertNull($operation->resource_original);

        $payload = json_decode($operation->resource_data, true);
        $this->assertSame('Creator User', $payload['c_created_by']);
        $this->assertSame(321, $payload['c_personid']);
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
        $this->assertSame('654-501-p20', $operation->resource_id);
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
        $this->assertSame('654', $operation->resource_id);
        $this->assertNull($operation->resource_original);

        $payload = json_decode($operation->resource_data, true);
        $this->assertSame(654, $payload['c_personid']);
        $this->assertSame('to delete', $payload['c_notes']);
        $this->assertSame('Creator User', $payload['c_created_by']);
        $this->assertNotNull($payload['c_created_date']);
    }
}
