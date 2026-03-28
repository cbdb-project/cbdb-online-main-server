<?php

namespace Tests\Unit;

use App\Models\User;
use App\Repositories\ToolsRepository;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 測試 ToolsRepository::timestamp() 的 audit 欄位處理
 *
 * 確保更新模式下 c_created_by / c_created_date 不會被意外覆寫（#844）
 */
class ToolsRepositoryTimestampTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $user = User::factory()->make(['name' => 'Test User']);
        Auth::shouldReceive('user')->andReturn($user);
    }

    #[Test]
    public function update_mode_strips_c_created_by_and_c_created_date(): void {
        $repo = new ToolsRepository();

        $data = [
            'c_name_chn' => '張三',
            'c_created_by' => 'should-be-removed',
            'c_created_date' => '2020-01-01 00:00:00',
        ];

        $result = $repo->timestamp($data, false);

        $this->assertArrayNotHasKey('c_created_by', $result, '更新模式不應包含 c_created_by');
        $this->assertArrayNotHasKey('c_created_date', $result, '更新模式不應包含 c_created_date');
        $this->assertArrayHasKey('c_modified_by', $result);
        $this->assertArrayHasKey('c_modified_date', $result);
        $this->assertSame('Test User', $result['c_modified_by']);
        $this->assertSame('張三', $result['c_name_chn']);
    }

    #[Test]
    public function update_mode_works_normally_without_created_fields(): void {
        $repo = new ToolsRepository();

        $data = [
            'c_name_chn' => '李四',
            'c_source' => 10,
        ];

        $result = $repo->timestamp($data, false);

        $this->assertArrayNotHasKey('c_created_by', $result);
        $this->assertArrayNotHasKey('c_created_date', $result);
        $this->assertArrayHasKey('c_modified_by', $result);
        $this->assertArrayHasKey('c_modified_date', $result);
    }

    #[Test]
    public function create_mode_sets_created_fields_normally(): void {
        $repo = new ToolsRepository();

        $data = [
            'c_name_chn' => '王五',
        ];

        $result = $repo->timestamp($data, true);

        $this->assertSame('Test User', $result['c_created_by']);
        $this->assertArrayHasKey('c_created_date', $result);
        $this->assertArrayNotHasKey('c_modified_by', $result);
        $this->assertArrayNotHasKey('c_modified_date', $result);
    }
}
