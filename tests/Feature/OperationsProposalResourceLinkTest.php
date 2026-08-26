<?php

namespace Tests\Feature;

use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 操作紀錄「資源」連結：提案的 resource_id 是**提案完成後**的主鍵，先於現實寫入，
 * 直接拿去開 edit-v2 會 404（編輯器以主鍵查不到列就 abort(404)）。
 *
 * 這是所有複合主鍵子資源共通的缺口，不限別名：
 * - create 提案核准前那一列不存在；
 * - update 提案只要改到任一主鍵成員（文本型如 ALTNAME_DATA.c_alt_name_chn，
 *   或代碼型如 BIOG_ADDR_DATA.c_addr_type）就是尚不存在的新鍵；
 * - delete 提案核准後舊鍵那一列已被刪除。
 *
 * @see \App\Http\Controllers\OperationsController::resolveLinkResourceId()
 */
class OperationsProposalResourceLinkTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-op-proposal-links';
        if (!is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0777, true);
        }
        config(['view.compiled' => $compiledViewPath]);
        config(['migration_flags.pages.basicinformation.altname' => 'new']);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('confirmation_token')->nullable();
            $table->tinyInteger('is_active')->default(0);
            $table->tinyInteger('is_admin')->default(0);
            $table->timestamps();
        });

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->default(0);
            $table->integer('c_personid')->default(0);
            $table->integer('op_type')->default(0);
            $table->string('resource')->nullable();
            $table->string('resource_id')->nullable();
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });

        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name')->nullable();
            $table->string('c_name_chn')->nullable();
        });

        DB::table('BIOG_MAIN')->insert([
            'c_personid' => 85303, 'c_name' => 'Hong Ping', 'c_name_chn' => '洪枰',
        ]);
    }

    private function admin(): User {
        return User::forceCreate([
            'name' => 'Admin', 'email' => 'op-link@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function proposal(User $admin, int $opType, string $resourceId, array $payload, array $original = []): Operation {
        return Operation::forceCreate([
            'user_id' => $admin->id,
            'c_personid' => 85303,
            'op_type' => $opType,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => $resourceId,
            'resource_data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'resource_original' => $original === [] ? null : json_encode($original, JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);
    }

    /** @return array<string,mixed> */
    private function altnamePayload(string $name, string $reviewStatus = 'pending'): array {
        return [
            'c_personid' => 85303,
            'c_alt_name_chn' => $name,
            'c_alt_name_type_code' => 5,
            '__review_status' => $reviewStatus,
            '__key_columns' => ['c_personid', 'c_alt_name_chn', 'c_alt_name_type_code'],
            '__proposal_meta' => ['action' => 'create', 'submitted_by' => 'Someone'],
        ];
    }

    #[Test]
    public function pending_create_proposal_gets_no_resource_link(): void {
        $admin = $this->admin();
        $this->proposal(
            $admin,
            Operation::TYPE_PROPOSAL_CREATE,
            'c_personid=85303&c_alt_name_chn=%E9%9B%AA%E4%95%AC&c_alt_name_type_code=5',
            $this->altnamePayload('雪䕬')
        );

        $this->actingAs($admin)
            ->get(route('app.operations.index', ['proposals_only' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Operations/Index')
                ->where('lists.0.op_type', Operation::TYPE_PROPOSAL_CREATE)
                // 那一列還沒建立，任何 edit-v2 連結都必然 404 ⇒ 不出連結。
                ->where('lists.0.resource_link', null)
                // 但描述仍要顯示提案的主鍵，審核者才看得出提案內容。
                ->where('lists.0.resource_description', "c_personid：85303\nc_alt_name_chn：雪䕬\nc_alt_name_type_code：5"));
    }

    #[Test]
    public function pending_update_proposal_that_changes_the_pk_links_to_the_original_row(): void {
        $admin = $this->admin();
        // 提案：把既有的「雪蔃」改成「雪䕬」。c_alt_name_chn 是主鍵成員 ⇒ resource_id 是新鍵。
        $this->proposal(
            $admin,
            Operation::TYPE_PROPOSAL_UPDATE,
            'c_personid=85303&c_alt_name_chn=%E9%9B%AA%E4%95%AC&c_alt_name_type_code=5',
            $this->altnamePayload('雪䕬'),
            ['c_personid' => 85303, 'c_alt_name_chn' => '雪蔃', 'c_alt_name_type_code' => 5]
        );

        $this->actingAs($admin)
            ->get(route('app.operations.index', ['proposals_only' => 1]))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $link = $page->toArray()['props']['lists'][0]['resource_link'];
                $this->assertIsString($link);
                $this->assertStringStartsWith('/app/basicinformation/85303/altnames/edit-v2?', $link);
                // 指向現存那一列（雪蔃），不是提案後才會存在的新鍵（雪䕬）。
                $this->assertStringContainsString(rawurlencode('雪蔃'), $link);
                $this->assertStringNotContainsString(rawurlencode('雪䕬'), $link);
            });
    }

    #[Test]
    public function approved_update_proposal_links_to_the_new_pk(): void {
        $admin = $this->admin();
        $this->proposal(
            $admin,
            Operation::TYPE_PROPOSAL_UPDATE,
            'c_personid=85303&c_alt_name_chn=%E9%9B%AA%E4%95%AC&c_alt_name_type_code=5',
            $this->altnamePayload('雪䕬', 'approved'),
            ['c_personid' => 85303, 'c_alt_name_chn' => '雪蔃', 'c_alt_name_type_code' => 5]
        );

        $this->actingAs($admin)
            ->get(route('app.operations.index', ['proposals_only' => 1]))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $link = $page->toArray()['props']['lists'][0]['resource_link'];
                $this->assertIsString($link);
                // 已核准 ⇒ 新鍵就是現況。
                $this->assertStringContainsString(rawurlencode('雪䕬'), $link);
            });
    }

    #[Test]
    public function approved_delete_proposal_gets_no_resource_link(): void {
        $admin = $this->admin();
        $this->proposal(
            $admin,
            Operation::TYPE_PROPOSAL_DELETE,
            'c_personid=85303&c_alt_name_chn=%E9%9B%AA%E4%95%AC&c_alt_name_type_code=5',
            $this->altnamePayload('雪䕬', 'approved'),
            ['c_personid' => 85303, 'c_alt_name_chn' => '雪䕬', 'c_alt_name_type_code' => 5]
        );

        // delete 提案（op_type 10）不進 proposals_only 清單（那裡只收 8／9），走一般操作紀錄清單。
        $this->actingAs($admin)
            ->get(route('app.operations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('lists.0.resource_link', null));
    }

    #[Test]
    public function pending_delete_proposal_still_links_to_the_row_being_deleted(): void {
        $admin = $this->admin();
        $this->proposal(
            $admin,
            Operation::TYPE_PROPOSAL_DELETE,
            'c_personid=85303&c_alt_name_chn=%E9%9B%AA%E4%95%AC&c_alt_name_type_code=5',
            $this->altnamePayload('雪䕬'),
            ['c_personid' => 85303, 'c_alt_name_chn' => '雪䕬', 'c_alt_name_type_code' => 5]
        );

        $this->actingAs($admin)
            ->get(route('app.operations.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $link = $page->toArray()['props']['lists'][0]['resource_link'];
                $this->assertIsString($link);
                $this->assertStringContainsString(rawurlencode('雪䕬'), $link);
            });
    }

    #[Test]
    public function direct_update_link_is_unchanged(): void {
        $admin = $this->admin();
        Operation::forceCreate([
            'user_id' => $admin->id,
            'c_personid' => 85303,
            'op_type' => Operation::TYPE_UPDATE,
            'resource' => 'ALTNAME_DATA',
            'resource_id' => 'c_personid=85303&c_alt_name_chn=%E9%9B%AA%E8%94%83&c_alt_name_type_code=5',
            'resource_data' => json_encode(['c_alt_name_chn' => '雪蔃'], JSON_UNESCAPED_UNICODE),
            'resource_original' => json_encode(['c_alt_name_chn' => '雪强'], JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
        ]);

        $this->actingAs($admin)
            ->get(route('app.operations.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $link = $page->toArray()['props']['lists'][0]['resource_link'];
                $this->assertIsString($link);
                $this->assertStringContainsString(rawurlencode('雪蔃'), $link);
            });
    }
}
