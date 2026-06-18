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
 * P2-5 codes/proposal-edit Inertia 變體（app.codes.proposals.edit/update）測試。
 */
class CodesProposalEditInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-codes-proposal-edit';
        if (!is_dir($compiledViewPath)) {
            mkdir($compiledViewPath, 0777, true);
        }
        config(['view.compiled' => $compiledViewPath]);

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config(['codes.tables' => ['TEST_PROP_CODES' => '測試代碼']]);

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

        Schema::create('TEST_PROP_CODES', function ($table) {
            $table->integer('code_id')->primary();
            $table->string('description')->nullable();
        });

        Schema::create('operations', function ($table) {
            $table->increments('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('c_personid')->default(0);
            $table->string('resource')->nullable();
            $table->text('resource_id')->nullable();
            $table->string('op_type')->nullable();
            $table->longText('resource_data')->nullable();
            $table->longText('resource_original')->nullable();
            $table->integer('crowdsourcing_status')->default(0);
            $table->timestamps();
        });
    }

    private function activeUser(): User {
        return User::create([
            'name' => 'U', 'email' => 'u@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    private function seedProposal(int $userId, array $payloadOverrides = []): int {
        $payload = array_merge([
            'code_id' => 5,
            'description' => 'proposed value',
            '__proposal_meta' => ['submitted_by_id' => $userId, 'comment' => 'please add'],
            '__key_columns' => ['code_id'],
            '__review_status' => 'pending',
        ], $payloadOverrides);

        return (int) DB::table('operations')->insertGetId([
            'user_id' => $userId,
            'c_personid' => 0,
            'resource' => 'TEST_PROP_CODES',
            'resource_id' => '5',
            'op_type' => Operation::TYPE_PROPOSAL_UPDATE,
            'resource_data' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'crowdsourcing_status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function proposal_edit_renders_with_values(): void {
        $user = $this->activeUser();
        $opId = $this->seedProposal($user->id);

        $this->actingAs($user)
            ->get(route('app.codes.proposals.edit', ['table_name' => 'TEST_PROP_CODES', 'operation' => $opId]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/ProposalEdit')
                ->where('table', 'TEST_PROP_CODES')
                ->where('values.code_id', 5)
                ->where('values.description', 'proposed value')
                ->where('operation_id', $opId)
                ->has('urls.update'));
    }

    #[Test]
    public function proposal_update_persists_and_redirects(): void {
        $user = $this->activeUser();
        $opId = $this->seedProposal($user->id);

        $this->actingAs($user)
            ->patch(route('app.codes.proposals.update', ['table_name' => 'TEST_PROP_CODES', 'operation' => $opId]), [
                'code_id' => 5,
                'description' => 'edited proposal',
            ])
            ->assertRedirect(route('operations.index', ['proposals_only' => 1]));

        $op = DB::table('operations')->find($opId);
        $this->assertStringContainsString('edited proposal', $op->resource_data);
    }

    #[Test]
    public function proposal_cancel_marks_cancelled_and_redirects(): void {
        $user = $this->activeUser();
        $opId = $this->seedProposal($user->id);

        $this->actingAs($user)
            ->delete(route('app.codes.proposals.cancel', ['table_name' => 'TEST_PROP_CODES', 'operation' => $opId]), ['reason' => '撤回測試'])
            ->assertRedirect(route('operations.index', ['proposals_only' => 1]));

        $op = DB::table('operations')->find($opId);
        $this->assertStringContainsString('cancelled', $op->resource_data);
    }

    #[Test]
    public function non_submitter_cannot_edit(): void {
        $owner = $this->activeUser();
        $opId = $this->seedProposal($owner->id);
        $other = User::create([
            'name' => 'O', 'email' => 'o@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);

        $this->actingAs($other)
            ->get(route('app.codes.proposals.edit', ['table_name' => 'TEST_PROP_CODES', 'operation' => $opId]))
            ->assertForbidden();
    }
}
