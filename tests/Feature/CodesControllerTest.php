<?php

namespace Tests\Feature;

use App\Http\Controllers\CodesController;
use App\Repositories\CodesRepository;
use App\Repositories\OperationRepository;
use App\Operation;
use App\User;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Tests\TestCase;

class CodesControllerTest extends TestCase
{
    protected $operationSpy;
    protected $originalDb;
    protected $fakeDb;

    protected function setUp(): void
    {
        parent::setUp();

        config(['codes.tables' => ['TEST_CODES', 'TEXT_CODES']]);
        config(['codes.connection' => null]);

        $compiledPath = base_path('tests/storage/views');
        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0777, true);
        }
        config(['view.compiled' => $compiledPath]);

        $this->originalDb = DB::getFacadeRoot();
        $this->fakeDb = new FakeDatabaseManager(
            [
                'TEST_CODES' => [],
                'TEXT_CODES' => [],
                'operations' => [],
            ],
            [
                'TEST_CODES' => ['code_id', 'code_sub', 'description'],
                'TEXT_CODES' => ['c_textid', 'c_title', 'c_title_chn', 'c_bibl_cat_code', 'c_created_by', 'c_created_date', 'c_modified_by', 'c_modified_date'],
                'operations' => ['id', 'user_id', 'resource', 'resource_id', 'op_type', 'resource_data', 'resource_original', 'created_at', 'updated_at'],
            ]
        );
        DB::swap($this->fakeDb);
        $this->app->instance('db', $this->fakeDb);

        $this->app->instance(CodesRepository::class, new class extends CodesRepository {
            public function allowedTables(): array
            {
                return ['TEST_CODES', 'TEXT_CODES'];
            }

            public function allowedTableMap(): array
            {
                return [
                    'TEST_CODES' => 'TEST_CODES',
                    'TEXT_CODES' => 'TEXT_CODES',
                ];
            }
        });

        $this->operationSpy = new class extends OperationRepository {
            public $calls = [];

            public function store($user_id, $c_personid, $op_type, $resource, $resource_id, $resource_data, $ori = '', $crowdsourcing_status = 0)
            {
                $this->calls[] = compact('user_id', 'c_personid', 'op_type', 'resource', 'resource_id', 'resource_data', 'ori', 'crowdsourcing_status');
            }

            public function hasPendingCreateProposal(string $resource, string $resourceId, ?int $excludeId = null): bool
            {
                $query = DB::table('operations')
                    ->where('resource', $resource)
                    ->where('op_type', Operation::TYPE_PROPOSAL_CREATE)
                    ->where('resource_id', $resourceId);

                if ($excludeId !== null) {
                    $query->where('id', '!=', $excludeId);
                }

                $rows = $query->get();
                foreach ($rows as $row) {
                    $payload = json_decode($row->resource_data ?? '', true);
                    $status = is_array($payload) ? ($payload['__review_status'] ?? null) : null;
                    if (in_array($status, ['pending', 'rejected'], true)) {
                        return true;
                    }
                }

                return false;
            }
        };
        $this->app->instance(OperationRepository::class, $this->operationSpy);
    }

    protected function tearDown(): void
    {
        DB::swap($this->originalDb);
        $this->app->instance('db', $this->originalDb);
        parent::tearDown();
    }

    public function testGuestCannotStoreRows()
    {
        $this->operationSpy->calls = [];
        $payload = [
            'code_id' => 'A1',
            'code_sub' => 'B1',
            'description' => 'guest attempt',
        ];

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES', $payload);

        $response->assertRedirect('/codes/TEST_CODES/create');
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testInactiveUserCannotStoreRows()
    {
        $this->operationSpy->calls = [];
        $inactiveUser = new User([
            'name' => 'inactive',
            'email' => 'inactive@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $inactiveUser->id = 1;
        $inactiveUser->is_active = 0;
        $this->actingAs($inactiveUser);

        $payload = [
            'code_id' => 'A2',
            'code_sub' => 'B2',
            'description' => 'inactive attempt',
        ];

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES', $payload);

        $response->assertRedirect('/codes/TEST_CODES/create');
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testActiveUserStoreRequiresPrimaryKeys()
    {
        $this->operationSpy->calls = [];
        $user = new User([
            'name' => 'active',
            'email' => 'active@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 11;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->from('/codes/TEST_CODES/create')->post('/codes/TEST_CODES', [
            'code_id' => 'A2',
            'description' => 'missing sub key',
        ]);

        $response->assertRedirect('/codes/TEST_CODES/create');
        $response->assertSessionHasErrors(['missing_keys']);
        $this->assertEmpty($this->operationSpy->calls);
        $this->assertEmpty($this->fakeDb->tables['TEST_CODES']);
    }

    public function testActiveUserStoreLogsOperation()
    {
        $this->operationSpy->calls = [];
        $activeUser = new User([
            'name' => 'active',
            'email' => 'active@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $activeUser->id = 2;
        $activeUser->is_active = 1;
        $this->actingAs($activeUser);

        $expectedInsert = [
            'code_id' => 'A3',
            'code_sub' => 'B3',
            'description' => 'active stored',
        ];
        $response = $this->post('/codes/TEST_CODES', $expectedInsert);

        $response->assertRedirect(route('codes.edit', [
            'table_name' => 'TEST_CODES',
            'id' => 'A3_._B3',
        ]));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(1, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('A3_._B3', $call['resource_id']);
        $this->assertSame($expectedInsert['description'], $call['resource_data']['description']);
    }

    public function testStoreFillsCreateAuditFieldsWhenAvailable()
    {
        Carbon::setTestNow(Carbon::create(2025, 1, 15, 9, 30));

        $this->operationSpy->calls = [];
        $user = new User([
            'name' => 'audit-user',
            'email' => 'audit@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 12;
        $user->is_active = 1;
        $this->actingAs($user);

        $payload = [
            'c_textid' => 'T100',
            'c_title' => 'Sample',
            'c_title_chn' => '範例',
        ];

        $response = $this->post('/codes/TEXT_CODES', $payload);

        $response->assertRedirect(route('codes.edit', [
            'table_name' => 'TEXT_CODES',
            'id' => 'T100',
        ]));

        $this->assertCount(1, $this->fakeDb->tables['TEXT_CODES']);
        $row = $this->fakeDb->tables['TEXT_CODES'][0];
        $this->assertSame('audit-user', $row['c_created_by']);
        $this->assertSame(Carbon::now()->format('Ymd'), $row['c_created_date']);
        $this->assertSame('Sample', $row['c_title']);
        $this->assertSame('範例', $row['c_title_chn']);

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame('audit-user', $call['resource_data']['c_created_by']);
        $this->assertSame(Carbon::now()->format('Ymd'), $call['resource_data']['c_created_date']);

        Carbon::setTestNow();
    }

    public function testCreateViewPlacesPrimaryKeyFirstWithDefaultValue()
    {
        $this->fakeDb->tables['TEXT_CODES'][] = [
            'c_textid' => 41,
            'c_title' => 'Existing',
            'c_title_chn' => '既有',
            'c_bibl_cat_code' => null,
            'c_created_by' => 'origin',
            'c_created_date' => '20200101',
            'c_modified_by' => 'origin',
            'c_modified_date' => '20200102',
        ];

        $user = new User([
            'name' => 'viewer',
            'email' => 'viewer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 13;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->get('/codes/TEXT_CODES/create');

        $response->assertStatus(200);
        $content = $response->getContent();

        preg_match_all('/name="([^"]+)" class="form-control"/', $content, $matches);
        $this->assertNotEmpty($matches[1]);
        $this->assertSame('c_textid', $matches[1][0]);

        $firstInputMarkupStart = strpos($content, $matches[0][0]);
        $this->assertNotFalse($firstInputMarkupStart);
        $firstInputMarkup = substr($content, $firstInputMarkupStart, 150);
        $this->assertNotFalse(strpos($firstInputMarkup, 'value="42"'));
    }

    public function testSearchFiltersResults()
    {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
            ['code_id' => 'A2', 'code_sub' => 'X2', 'description' => 'Beta entry'],
            ['code_id' => 'A3', 'code_sub' => 'X3', 'description' => 'Gamma entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES?search=Beta');

        $response->assertStatus(200);
        $response->assertSee('Beta entry');
        $response->assertDontSee('Alpha entry');
        $response->assertDontSee('Gamma entry');
        $response->assertSee('value="Beta"', false);
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testGuestViewDoesNotShowActions()
    {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Alpha entry'],
        ]);

        $response = $this->get('/codes/TEST_CODES');

        $response->assertStatus(200);
        $response->assertDontSee('edit');
        $response->assertDontSee('delete');
        $response->assertDontSee('新增');
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testTextCodesUsesExplicitPrimaryKeyOverride()
    {
        DB::table('TEXT_CODES')->insert([
            [
                'c_textid' => 'T001',
                'c_title' => 'Sample Title',
                'c_title_chn' => 'Sample Title CHN',
                'c_created_by' => 'origin',
                'c_created_date' => '20200101',
                'c_modified_by' => 'previous',
                'c_modified_date' => '20200102',
            ],
        ]);

        $user = new User([
            'name' => 'text-admin',
            'email' => 'text-admin@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 5;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->get('/codes/TEXT_CODES');

        $response->assertStatus(200);
        $response->assertViewHas('keyColumns', ['c_textid']);
        $response->assertSee('href="/codes/TEXT_CODES/T001/edit"', false);
        $response->assertDontSee('href="/codes/TEXT_CODES/T001_._', false);
        $response->assertSee('<th>c_textid</th>', false);
        $response->assertSee('<th>c_title_chn</th>', false);
        $response->assertSee('<th>c_title</th>', false);
        $response->assertDontSee('<th>c_created_by</th>', false);
        $response->assertSee('Sample Title CHN');
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testActiveUserCanSubmitCreateProposal()
    {
        $this->operationSpy->calls = [];
        $user = new User([
            'name' => 'proposer',
            'email' => 'proposer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 10;
        $user->is_active = 1;
        $this->actingAs($user);

        $payload = [
            'code_id' => 'PX',
            'code_sub' => '01',
            'description' => 'Proposal create',
            '__proposal_comment' => 'Please review',
        ];

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES/proposal', $payload);

        $response->assertRedirect(route('codes.show', ['table_name' => 'TEST_CODES']));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(0, $call['c_personid']);
        $this->assertSame(\App\Operation::TYPE_PROPOSAL_CREATE, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('PX_._01', substr($call['resource_id'], 0, 7));
        $this->assertSame('pending', $call['resource_data']['__review_status']);
        $this->assertSame(['code_id', 'code_sub'], $call['resource_data']['__key_columns']);
        $this->assertSame('Proposal create', $call['resource_data']['description']);
        $this->assertSame('Please review', $call['resource_data']['__proposal_meta']['comment']);
    }

    public function testDuplicateCreateProposalIsBlockedWhenPendingExists()
    {
        $this->operationSpy->calls = [];
        $user = new User([
            'name' => 'proposer',
            'email' => 'proposer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 12;
        $user->is_active = 1;
        $this->actingAs($user);

        $payload = [
            'code_id' => 'PX',
            'code_sub' => '01',
            'description' => 'Proposal create',
            '__proposal_comment' => 'Please review',
        ];

        $this->post('/codes/TEST_CODES/proposal', $payload);
        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];

        DB::table('operations')->insert([
            'id' => 1,
            'user_id' => $user->id,
            'resource' => $call['resource'],
            'resource_id' => $call['resource_id'],
            'op_type' => $call['op_type'],
            'resource_data' => json_encode($call['resource_data']),
            'resource_original' => json_encode($call['ori']),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $this->operationSpy->calls = [];

        $response = $this->from('/codes/TEST_CODES/create')
            ->post('/codes/TEST_CODES/proposal', $payload);

        $response->assertRedirect('/codes/TEST_CODES/create');
        $response->assertSessionHas('_old_input.code_id', 'PX');
        $this->assertEmpty($this->operationSpy->calls);
    }

    public function testProposalOwnerCanViewEditFormForCreateProposal()
    {
        $user = new User([
            'name' => 'proposer',
            'email' => 'proposer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 13;
        $user->is_active = 1;
        $this->actingAs($user);

        DB::table('operations')->delete();
        $resourceData = [
            'code_id' => 'PX',
            'code_sub' => '01',
            'description' => 'Proposal',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
            '__proposal_meta' => [
                'submitted_by' => $user->name,
                'submitted_by_id' => $user->id,
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ],
        ];
        DB::table('operations')->insert([
            'id' => 2,
            'user_id' => $user->id,
            'resource' => 'TEST_CODES',
            'resource_id' => 'PX_._01',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_data' => json_encode($resourceData),
            'resource_original' => json_encode([]),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $controller = new class(app(CodesRepository::class), $this->operationSpy, $resourceData, $user) extends CodesController {
            private $mockOperation;

            public function __construct($codesRepository, $operationRepository, array $resourceData, User $user)
            {
                parent::__construct($codesRepository, $operationRepository);
                $this->mockOperation = [
                    'id' => 2,
                    'resource' => 'TEST_CODES',
                    'op_type' => Operation::TYPE_PROPOSAL_CREATE,
                    'resource_data' => json_encode($resourceData),
                    'resource_original' => json_encode([]),
                    'user_id' => $user->id,
                ];
            }

            protected function findOperationOrAbort(int $operationId): array
            {
                return $this->mockOperation;
            }
        };

        $view = $controller->proposalEdit('TEST_CODES', 2);
        $this->assertSame('codes.proposal-edit', $view->getName());
        $data = $view->getData();
        $this->assertSame('PX', $data['values']['code_id']);
        $this->assertSame('01', $data['values']['code_sub']);
        $this->assertSame('Proposal', $data['values']['description']);
    }

    public function testActiveUserCanSubmitUpdateProposal()
    {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'UX', 'code_sub' => '02', 'description' => 'Original'],
        ]);

        $this->operationSpy->calls = [];
        $user = new User([
            'name' => 'editor',
            'email' => 'editor@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 11;
        $user->is_active = 1;
        $this->actingAs($user);

        $payload = [
            'code_id' => 'UX',
            'code_sub' => '02',
            'description' => 'Updated Desc',
            '__proposal_comment' => 'Need approval',
        ];

        $response = $this->from('/codes/TEST_CODES/UX_._02/edit')
            ->post('/codes/TEST_CODES/UX_._02/proposal', $payload);

        $response->assertRedirect(route('codes.edit', ['table_name' => 'TEST_CODES', 'id' => 'UX_._02']));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(\App\Operation::TYPE_PROPOSAL_UPDATE, $call['op_type']);
        $this->assertSame('pending', $call['resource_data']['__review_status']);
        $this->assertSame('Updated Desc', $call['resource_data']['description']);
        $this->assertSame('Original', $call['ori']['description']);
        $this->assertSame(['code_id', 'code_sub'], $call['resource_data']['__key_columns']);
    }

    public function testProposalOwnerCanCancelPendingProposal()
    {
        $user = new User([
            'name' => 'proposer',
            'email' => 'proposer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 15;
        $user->is_active = 1;
        $this->actingAs($user);

        DB::table('operations')->delete();
        $resourceData = [
            'code_id' => 'PX',
            'code_sub' => '01',
            'description' => 'Proposal',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'pending',
            '__proposal_meta' => [
                'submitted_by' => $user->name,
                'submitted_by_id' => $user->id,
                'submitted_at' => Carbon::now()->format('Y-m-d H:i:s'),
            ],
        ];
        DB::table('operations')->insert([
            'id' => 4,
            'user_id' => $user->id,
            'resource' => 'TEST_CODES',
            'resource_id' => 'PX_._01',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_data' => json_encode($resourceData),
            'resource_original' => json_encode([]),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->from(route('operations.index', ['proposals_only' => 1]))
            ->delete(route('codes.proposals.cancel', ['table_name' => 'TEST_CODES', 'operation' => 4]));

        $response->assertRedirect(route('operations.index', ['proposals_only' => 1]));

        $row = DB::table('operations')->first();
        $stored = json_decode($row->resource_data, true);
        $this->assertSame('cancelled', $stored['__review_status']);
        $this->assertArrayHasKey('cancelled_at', $stored['__proposal_meta']);
        $this->assertSame($user->name, $stored['__proposal_meta']['cancelled_by']);
        $this->assertSame($user->id, $stored['__proposal_meta']['cancelled_by_id']);
    }

    public function testProposalOwnerUpdateResetsStatusToPending()
    {
        $user = new User([
            'name' => 'proposer',
            'email' => 'proposer@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 14;
        $user->is_active = 1;
        $this->actingAs($user);

        DB::table('operations')->delete();
        $resourceData = [
            'code_id' => 'PX',
            'code_sub' => '01',
            'description' => 'Proposal',
            '__key_columns' => ['code_id', 'code_sub'],
            '__review_status' => 'rejected',
            '__review_comment' => 'Missing info',
            '__proposal_meta' => [
                'submitted_by' => $user->name,
                'submitted_by_id' => $user->id,
                'submitted_at' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
            ],
        ];
        DB::table('operations')->insert([
            'id' => 3,
            'user_id' => $user->id,
            'resource' => 'TEST_CODES',
            'resource_id' => 'PX_._01',
            'op_type' => Operation::TYPE_PROPOSAL_CREATE,
            'resource_data' => json_encode($resourceData),
            'resource_original' => json_encode([]),
            'created_at' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->subDay()->format('Y-m-d H:i:s'),
        ]);

        $response = $this->from(route('codes.proposals.edit', ['table_name' => 'TEST_CODES', 'operation' => 3]))
            ->patch(route('codes.proposals.update', ['table_name' => 'TEST_CODES', 'operation' => 3]), [
                'code_id' => 'PX',
                'code_sub' => '02',
                'description' => 'Updated proposal',
                '__proposal_comment' => 'Updated info',
            ]);

        $response->assertRedirect(route('operations.index', ['proposals_only' => 1]));

        $row = DB::table('operations')->first();
        $this->assertNotNull($row);
        $stored = json_decode($row->resource_data, true);
        $this->assertSame('PX', $stored['code_id']);
        $this->assertSame('02', $stored['code_sub']);
        $this->assertSame('Updated proposal', $stored['description']);
        $this->assertSame('pending', $stored['__review_status']);
        $this->assertArrayNotHasKey('__review_comment', $stored);
        $this->assertArrayHasKey('updated_at', $stored['__proposal_meta']);
        $this->assertSame('Updated info', $stored['__proposal_meta']['comment']);

        $this->assertSame('PX_._02', $row->resource_id);
    }

    public function testAuditFieldsAreReadonlyAndPrefilledOnEdit()
    {
        Carbon::setTestNow(Carbon::create(2024, 3, 22, 12));

        DB::table('TEXT_CODES')->insert([
            [
                'c_textid' => 'T001',
                'c_title' => 'Sample Title',
                'c_title_chn' => 'Sample Title CHN',
                'c_created_by' => 'origin',
                'c_created_date' => '20200101',
                'c_modified_by' => 'previous',
                'c_modified_date' => '20200102',
            ],
        ]);

        $user = new User([
            'name' => 'text-admin',
            'email' => 'text-admin@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 6;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->get('/codes/TEXT_CODES/T001/edit');

        $response->assertStatus(200);
        $content = $response->getContent();
        $createdByPos = strpos($content, 'name="c_created_by"');
        $this->assertNotFalse($createdByPos);
        $this->assertNotFalse(strpos($content, 'value="origin"', $createdByPos));
        $this->assertNotFalse(strpos($content, 'readonly', $createdByPos));

        $createdDatePos = strpos($content, 'name="c_created_date"');
        $this->assertNotFalse($createdDatePos);
        $this->assertNotFalse(strpos($content, 'value="20200101"', $createdDatePos));
        $this->assertNotFalse(strpos($content, 'readonly', $createdDatePos));

        $modifiedByPos = strpos($content, 'name="c_modified_by"');
        $this->assertNotFalse($modifiedByPos);
        $this->assertNotFalse(strpos($content, 'value="text-admin"', $modifiedByPos));
        $this->assertNotFalse(strpos($content, 'readonly', $modifiedByPos));

        $modifiedDatePos = strpos($content, 'name="c_modified_date"');
        $this->assertNotFalse($modifiedDatePos);
        $this->assertNotFalse(strpos($content, 'value="'.Carbon::now()->format('Ymd').'"', $modifiedDatePos));
        $this->assertNotFalse(strpos($content, 'readonly', $modifiedDatePos));
        $response->assertDontSee('value="previous"', false);
        $response->assertDontSee('value="20200102"', false);

        Carbon::setTestNow();
    }

    public function testActiveUserUpdateLogsOperation()
    {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Old'],
        ]);

        $this->operationSpy->calls = [];

        $user = new User([
            'name' => 'editor',
            'email' => 'editor@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 3;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->put('/codes/TEST_CODES/A1_._X1', [
            'description' => 'Updated',
        ]);

        $response->assertRedirect(route('codes.edit', [
            'table_name' => 'TEST_CODES',
            'id' => 'A1_._X1',
        ]));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(2, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('A1_._X1', $call['resource_id']);
        $this->assertSame('Updated', $call['resource_data']['description']);
        $this->assertSame('Old', $call['ori']['description']);
    }

    public function testActiveUserDestroyLogsOperation()
    {
        DB::table('TEST_CODES')->insert([
            ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'To delete'],
        ]);

        $this->operationSpy->calls = [];

        $user = new User([
            'name' => 'deleter',
            'email' => 'deleter@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 4;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->delete('/codes/TEST_CODES/A1_._X1');

        $response->assertRedirect(route('codes.show', ['table_name' => 'TEST_CODES']));

        $this->assertCount(1, $this->operationSpy->calls);
        $call = $this->operationSpy->calls[0];
        $this->assertSame(4, $call['op_type']);
        $this->assertSame('TEST_CODES', $call['resource']);
        $this->assertSame('A1_._X1', $call['resource_id']);
        $this->assertSame('To delete', $call['resource_data']['description']);
    }

    public function testUpdateGracefullyHandlesDuplicateKey()
    {
        $this->fakeDb->tables['TEST_CODES'][] = ['code_id' => 'A1', 'code_sub' => 'X1', 'description' => 'Old'];
        $this->fakeDb->setFailure('update', 'Duplicate entry #1062');
        $this->operationSpy->calls = [];

        $user = new User([
            'name' => 'editor',
            'email' => 'editor@example.com',
            'confirmation_token' => Str::random(32),
        ]);
        $user->id = 6;
        $user->is_active = 1;
        $this->actingAs($user);

        $response = $this->from('/codes/TEST_CODES/A1_._X1/edit')->put('/codes/TEST_CODES/A1_._X1', [
            'description' => 'Updated',
        ]);

        $response->assertRedirect('/codes/TEST_CODES/A1_._X1/edit');
        $response->assertSessionHasErrors(['duplicate']);
        $response->assertSessionHas('_old_input.description', 'Updated');
        $this->assertEmpty($this->operationSpy->calls);

        $this->fakeDb->clearFailures();
        $this->assertCount(1, $this->fakeDb->failuresCleared);
    }
}

class FakeDatabaseManager
{
    public $tables = [];
    public $failures = [];
    public $failuresCleared = [];
    public $schemaColumns = [];

    public function __construct(array $tables = [], array $schemaColumns = [])
    {
        $this->tables = $tables;
        $this->schemaColumns = $schemaColumns;
    }

    public function table($name)
    {
        if (!array_key_exists($name, $this->tables)) {
            $this->tables[$name] = [];
        }

        $rows =& $this->tables[$name];
        return new FakeQueryBuilder($rows, $this, $name);
    }

    public function connection($name = null)
    {
        return $this;
    }

    public function getDoctrineSchemaManager()
    {
        return new FakeDoctrineSchemaManager();
    }

    public function select($query)
    {
        return [];
    }

    public function getSchemaBuilder()
    {
        return new FakeSchemaBuilder($this->schemaColumns);
    }

    public function setFailure(string $operation, string $message = 'Simulated failure'): void
    {
        $this->failures[$operation] = $message;
    }

    public function clearFailures(): void
    {
        $this->failures = [];
        $this->failuresCleared[] = true;
    }

    public function shouldFail(string $operation): bool
    {
        return array_key_exists($operation, $this->failures);
    }

    public function failureMessage(string $operation): string
    {
        return $this->failures[$operation] ?? 'Simulated failure';
    }
}

class FakeDoctrineSchemaManager
{
    public function listTableDetails($table)
    {
        return new FakeTableDetails();
    }
}

class FakeTableDetails
{
    public function hasPrimaryKey()
    {
        return false;
    }

    public function getPrimaryKey()
    {
        return null;
    }
}

class FakeSchemaBuilder
{
    private $schemaColumns = [];

    public function __construct(array $schemaColumns = [])
    {
        $this->schemaColumns = $schemaColumns;
    }

    public function getColumnListing($table)
    {
        if (isset($this->schemaColumns[$table]) && !empty($this->schemaColumns[$table])) {
            return $this->schemaColumns[$table];
        }

        return ['code_id', 'code_sub', 'description'];
    }

    public function hasTable($table)
    {
        return array_key_exists($table, $this->schemaColumns);
    }
}

class FakeQueryBuilder
{
    private $rows;
    private $conditions = [];
    private $table;
    private $manager;

    public function __construct(array &$rows, FakeDatabaseManager $manager, string $table)
    {
        $this->rows =& $rows;
        $this->manager = $manager;
        $this->table = $table;
    }

    public function __clone()
    {
        $this->conditions = [];
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_callable($column)) {
            $column($this);
            return $this;
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->conditions[] = [
            'column' => $column,
            'operator' => strtolower((string) $operator),
            'value' => $value,
            'boolean' => strtolower($boolean),
        ];

        return $this;
    }

    public function orWhere($column, $operator = null, $value = null)
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->where($column, $operator, $value, 'or');
    }

    public function first()
    {
        $filtered = $this->applyConditions();
        $first = reset($filtered);
        return $first ? (object) $first : null;
    }

    public function insert(array $data)
    {
        if ($this->manager->shouldFail('insert')) {
            throw new QueryException('insert into '.$this->table, [], new \Exception($this->manager->failureMessage('insert')));
        }

        if (isset($data[0]) && is_array($data[0])) {
            foreach ($data as $row) {
                $this->rows[] = $row;
            }
        } else {
            $this->rows[] = $data;
        }
        return true;
    }

    public function update(array $data)
    {
        if ($this->manager->shouldFail('update')) {
            throw new QueryException('update '.$this->table, [], new \Exception($this->manager->failureMessage('update')));
        }

        foreach ($this->rows as &$row) {
            if ($this->rowMatches($row)) {
                foreach ($data as $key => $value) {
                    $row[$key] = $value;
                }
            }
        }

        return true;
    }

    public function delete()
    {
        if ($this->manager->shouldFail('delete')) {
            throw new QueryException('delete from '.$this->table, [], new \Exception($this->manager->failureMessage('delete')));
        }

        $this->rows = array_values(array_filter($this->rows, function ($row) {
            return !$this->rowMatches($row);
        }));
    }

    public function paginate($perPage)
    {
        $items = array_map(function ($row) {
            return (object) $row;
        }, $this->applyConditions());

        return new LengthAwarePaginator(
            $items,
            count($items),
            $perPage,
            1,
            ['path' => url()->current()]
        );
    }

    public function get()
    {
        return array_map(function ($row) {
            return (object) $row;
        }, $this->applyConditions());
    }

    public function max($column)
    {
        $filtered = $this->applyConditions();
        if (empty($filtered)) {
            return null;
        }

        $values = array_map(function ($row) use ($column) {
            return $row[$column] ?? null;
        }, $filtered);

        $values = array_filter($values, function ($value) {
            return $value !== null;
        });

        if (empty($values)) {
            return null;
        }

        return max($values);
    }

    private function applyConditions(): array
    {
        if (empty($this->conditions)) {
            return $this->rows;
        }

        return array_values(array_filter($this->rows, function ($row) {
            return $this->rowMatches($row);
        }));
    }

    private function rowMatches(array $row): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        $result = null;
        foreach ($this->conditions as $condition) {
            $match = $this->matchCondition($row, $condition);
            if ($condition['boolean'] === 'or') {
                $result = ($result ?? false) || $match;
            } else {
                $result = ($result ?? true) && $match;
            }
        }

        return (bool) $result;
    }

    private function matchCondition(array $row, array $condition): bool
    {
        $value = $row[$condition['column']] ?? null;
        $expected = $condition['value'];

        if ($condition['operator'] === 'like') {
            $needle = trim((string) $expected, '%');
            return stripos((string) $value, $needle) !== false;
        }

        return (string) $value === (string) $expected;
    }
}
