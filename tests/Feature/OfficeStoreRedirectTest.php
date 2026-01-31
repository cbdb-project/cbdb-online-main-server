<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 測試官名新增後的重定向 URL 是否正確
 *
 * 重現問題：officeStoreById() 返回查詢參數格式的 resource_id（如 c_office_id=87473&c_posting_id=2104406），
 * 但 store() 仍用 explode('-', ...) 解析，導致重定向 URL 中 c_office_id 包含完整查詢字串、c_posting_id 為空。
 */
class OfficeStoreRedirectTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('institution')->nullable();
            $table->json('settings')->nullable();
            $table->string('avatar')->nullable();
            $table->string('confirmation_token')->nullable();
            $table->smallInteger('is_active')->default(0);
            $table->smallInteger('is_admin')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('BIOG_MAIN', function (Blueprint $table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
        });

        Schema::create('POSTING_DATA', function (Blueprint $table) {
            $table->integer('c_posting_id')->primary();
            $table->integer('c_personid');
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('POSTED_TO_OFFICE_DATA', function (Blueprint $table) {
            $table->integer('c_posting_id')->primary();
            $table->integer('c_personid');
            $table->integer('c_office_id');
            $table->integer('c_inst_code')->default(0);
            $table->integer('c_inst_name_code')->default(0);
            $table->integer('c_fy_intercalary')->default(0);
            $table->integer('c_ly_intercalary')->default(0);
            $table->integer('c_source')->default(0);
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('POSTED_TO_ADDR_DATA', function (Blueprint $table) {
            $table->integer('c_personid');
            $table->integer('c_posting_id');
            $table->integer('c_office_id');
            $table->integer('c_addr_id');
            $table->string('c_created_by')->nullable();
            $table->timestamp('c_created_date')->nullable();
            $table->string('c_modified_by')->nullable();
            $table->timestamp('c_modified_date')->nullable();
        });

        Schema::create('operations', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('c_personid');
            $table->smallInteger('op_type');
            $table->string('resource');
            $table->string('resource_id');
            $table->text('resource_data')->nullable();
            $table->text('resource_original')->nullable();
            $table->smallInteger('crowdsourcing_status')->default(0);
            $table->smallInteger('rate')->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void {
        Schema::dropIfExists('operations');
        Schema::dropIfExists('POSTED_TO_ADDR_DATA');
        Schema::dropIfExists('POSTED_TO_OFFICE_DATA');
        Schema::dropIfExists('POSTING_DATA');
        Schema::dropIfExists('BIOG_MAIN');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function createUser(): User {
        return User::create([
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'is_admin' => 0,
        ]);
    }

    private function createPerson(int $personId = 89593): void {
        DB::table('BIOG_MAIN')->insert([
            'c_personid' => $personId,
            'c_name_chn' => '測試人物',
        ]);
    }

    /**
     * 將重定向 URL 的查詢參數解析為陣列
     */
    private function parseRedirectQueryParams(string $redirectUrl): array {
        $parsedUrl = parse_url($redirectUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);

        return $queryParams;
    }

    /**
     * 斷言重定向 URL 包含正確的 offices 查詢參數
     */
    private function assertOfficeRedirectParams(array $queryParams, string $expectedOfficeId): void {
        $this->assertSame(
            $expectedOfficeId,
            $queryParams['c_office_id'] ?? null,
            '重定向 URL 中 c_office_id 應為純數字值，而非包含完整查詢字串'
        );

        $this->assertNotEmpty(
            $queryParams['c_posting_id'] ?? '',
            '重定向 URL 中 c_posting_id 不應為空'
        );

        $this->assertMatchesRegularExpression(
            '/^\d+$/',
            $queryParams['c_posting_id'] ?? '',
            '重定向 URL 中 c_posting_id 應為數字'
        );
    }

    #[Test]
    public function testStoreRedirectsWithCorrectQueryParameters(): void {
        $user = $this->createUser();

        $this->createPerson();

        $response = $this->actingAs($user)->post('/basicinformation/89593/offices', [
            '_token' => 'test',
            'c_addr' => [0],
            'c_office_id' => 87473,
            'c_inst_code' => '0',
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        $response->assertRedirect();
        $queryParams = $this->parseRedirectQueryParams($response->headers->get('Location'));
        $this->assertOfficeRedirectParams($queryParams, '87473');
    }

    #[Test]
    public function testUpdateWithChangesRedirectsWithCorrectQueryParameters(): void {
        $user = $this->createUser();
        $this->createPerson();

        // 先建立一筆官名記錄
        $this->actingAs($user)->post('/basicinformation/89593/offices', [
            '_token' => 'test',
            'c_addr' => [0],
            'c_office_id' => 100,
            'c_inst_code' => '0',
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        $postingId = DB::table('POSTING_DATA')->where('c_personid', 89593)->value('c_posting_id');

        // 透過 updateQuery 路由修改 c_fy_intercalary
        $response = $this->actingAs($user)->put(
            '/basicinformation/89593/offices/update?c_office_id=100&c_posting_id='.$postingId,
            [
                '_token' => 'test',
                '_method' => 'PUT',
                'c_addr' => [0],
                '_id' => 89593,
                '_postingid' => $postingId,
                '_officeid' => 100,
                'c_personid' => 89593,
                'c_office_id' => 100,
                'c_inst_code' => '0',
                'c_fy_intercalary' => 1,
                'c_ly_intercalary' => 0,
                'c_source' => 0,
            ]
        );

        $response->assertRedirect();
        $queryParams = $this->parseRedirectQueryParams($response->headers->get('Location'));
        $this->assertOfficeRedirectParams($queryParams, '100');
        $this->assertSame((string) $postingId, $queryParams['c_posting_id']);
    }

    #[Test]
    public function testUpdateWithNoChangesRedirectsWithCorrectQueryParameters(): void {
        $user = $this->createUser();
        $this->createPerson();

        // 先建立一筆官名記錄
        $this->actingAs($user)->post('/basicinformation/89593/offices', [
            '_token' => 'test',
            'c_addr' => [0],
            'c_office_id' => 200,
            'c_inst_code' => '0',
            'c_fy_intercalary' => 0,
            'c_ly_intercalary' => 0,
            'c_source' => 0,
        ]);

        $postingId = DB::table('POSTING_DATA')->where('c_personid', 89593)->value('c_posting_id');

        // 送出與原始資料完全相同的值（不做任何修改）
        $response = $this->actingAs($user)->put(
            '/basicinformation/89593/offices/update?c_office_id=200&c_posting_id='.$postingId,
            [
                '_token' => 'test',
                '_method' => 'PUT',
                'c_addr' => [0],
                '_id' => 89593,
                '_postingid' => $postingId,
                '_officeid' => 200,
                'c_personid' => 89593,
                'c_office_id' => 200,
                'c_inst_code' => '0',
                'c_fy_intercalary' => 0,
                'c_ly_intercalary' => 0,
                'c_source' => 0,
            ]
        );

        $response->assertRedirect();
        $queryParams = $this->parseRedirectQueryParams($response->headers->get('Location'));
        $this->assertOfficeRedirectParams($queryParams, '200');
        $this->assertSame((string) $postingId, $queryParams['c_posting_id']);
    }
}
