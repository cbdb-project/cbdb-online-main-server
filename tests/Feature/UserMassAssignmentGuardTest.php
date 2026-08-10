<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * is_admin（角色）與 is_active（啟用狀態）是提權欄位，不可批量賦值。
 *
 * 本測試把「不在 $fillable」這件事釘住：只要有人為了方便把欄位加回
 * User::$fillable，任何 User::create($request->all()) 之類的寫法就會瞬間
 * 變成提權漏洞，這裡會先紅起來。
 *
 * 注意：`create_ignores_privilege_columns` 與 `update_ignores_privilege_columns`
 * 斷言的是「靜默丟棄」，這依賴本專案未啟用 Eloquent strict mode。若日後有人加上
 * `Model::preventSilentlyDiscardingAttributes()`，這兩條會改成拋 MassAssignmentException，
 * 需同步改為 expectException——行為仍然安全，只是斷言形式要換。
 */
class UserMassAssignmentGuardTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        config(['cache.default' => 'array']);
        config(['session.driver' => 'array']);

        Schema::dropIfExists('users');
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
    }

    #[Test]
    public function privilege_columns_are_not_in_fillable(): void {
        $fillable = (new User())->getFillable();

        $this->assertNotContains('is_admin', $fillable);
        $this->assertNotContains('is_active', $fillable);
    }

    #[Test]
    public function create_ignores_privilege_columns(): void {
        $user = User::create([
            'name' => 'Mass Assign',
            'email' => 'mass@example.com',
            'password' => bcrypt('secret'),
            'confirmation_token' => 'token',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);

        $user->refresh();

        $this->assertSame(User::STATUS_INACTIVE, $user->is_active);
        $this->assertSame(User::ROLE_REGULAR, $user->is_admin);
        $this->assertFalse($user->isSuperAdmin());
    }

    #[Test]
    public function update_ignores_privilege_columns(): void {
        $user = User::forceCreate([
            'name' => 'Regular',
            'email' => 'regular@example.com',
            'password' => bcrypt('secret'),
            'confirmation_token' => 'token',
            'is_active' => User::STATUS_ACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);

        $user->update([
            'name' => 'Renamed',
            'is_active' => User::STATUS_INACTIVE,
            'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);

        $user->refresh();

        $this->assertSame('Renamed', $user->name);
        $this->assertSame(User::STATUS_ACTIVE, $user->is_active);
        $this->assertSame(User::ROLE_REGULAR, $user->is_admin);
    }

    #[Test]
    public function explicit_assignment_still_works(): void {
        $user = User::forceCreate([
            'name' => 'Regular',
            'email' => 'regular2@example.com',
            'password' => bcrypt('secret'),
            'confirmation_token' => 'token',
            'is_active' => User::STATUS_INACTIVE,
            'is_admin' => User::ROLE_REGULAR,
        ]);

        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_EXPERT;
        $user->save();

        $user->refresh();

        $this->assertTrue($user->isActive());
        $this->assertTrue($user->isExpert());
    }
}
