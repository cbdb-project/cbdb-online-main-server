<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserRoleTest extends TestCase {
    /**
     * 测试用户角色常量定义
     */
    #[Test]
    public function testRoleConstants() {
        $this->assertSame(0, User::ROLE_REGULAR);
        $this->assertSame(1, User::ROLE_EXPERT);
        $this->assertSame(2, User::ROLE_CROWDSOURCING);
        $this->assertSame(3, User::ROLE_SUPER_ADMIN);
    }

    /**
     * 测试用户状态常量定义
     */
    #[Test]
    public function testStatusConstants() {
        $this->assertSame(0, User::STATUS_INACTIVE);
        $this->assertSame(1, User::STATUS_ACTIVE);
        $this->assertSame(2, User::STATUS_RESERVED);
    }

    /**
     * 测试 isActive() 方法
     */
    #[Test]
    public function testIsActive() {
        $user = new User();

        $user->is_active = User::STATUS_INACTIVE;
        $this->assertFalse($user->isActive());

        $user->is_active = User::STATUS_ACTIVE;
        $this->assertTrue($user->isActive());

        $user->is_active = User::STATUS_RESERVED;
        $this->assertFalse($user->isActive());
    }

    /**
     * 测试 isAdmin() 方法（专家或系统管理员）
     */
    #[Test]
    public function testIsAdmin() {
        $user = new User();

        $user->is_admin = User::ROLE_REGULAR;
        $this->assertFalse($user->isAdmin());

        $user->is_admin = User::ROLE_EXPERT;
        $this->assertTrue($user->isAdmin());

        $user->is_admin = User::ROLE_CROWDSOURCING;
        $this->assertFalse($user->isAdmin());

        $user->is_admin = User::ROLE_SUPER_ADMIN;
        $this->assertTrue($user->isAdmin());
    }

    /**
     * 测试 isExpert() 方法
     */
    #[Test]
    public function testIsExpert() {
        $user = new User();

        $user->is_admin = User::ROLE_REGULAR;
        $this->assertFalse($user->isExpert());

        $user->is_admin = User::ROLE_EXPERT;
        $this->assertTrue($user->isExpert());

        $user->is_admin = User::ROLE_SUPER_ADMIN;
        $this->assertFalse($user->isExpert());
    }

    /**
     * 测试 isSuperAdmin() 方法
     */
    #[Test]
    public function testIsSuperAdmin() {
        $user = new User();

        $user->is_admin = User::ROLE_EXPERT;
        $this->assertFalse($user->isSuperAdmin());

        $user->is_admin = User::ROLE_SUPER_ADMIN;
        $this->assertTrue($user->isSuperAdmin());
    }

    /**
     * 测试 isCrowdsourcingUser() 方法
     */
    #[Test]
    public function testIsCrowdsourcingUser() {
        $user = new User();

        $user->is_admin = User::ROLE_REGULAR;
        $this->assertFalse($user->isCrowdsourcingUser());

        $user->is_admin = User::ROLE_CROWDSOURCING;
        $this->assertTrue($user->isCrowdsourcingUser());
    }

    /**
     * 测试 isRegularUser() 方法
     */
    #[Test]
    public function testIsRegularUser() {
        $user = new User();

        $user->is_admin = User::ROLE_REGULAR;
        $this->assertTrue($user->isRegularUser());

        $user->is_admin = User::ROLE_EXPERT;
        $this->assertFalse($user->isRegularUser());
    }

    /**
     * 测试 canManageUsers() 方法
     */
    #[Test]
    public function testCanManageUsers() {
        $user = new User();

        // 未启用的专家用户
        $user->is_active = User::STATUS_INACTIVE;
        $user->is_admin = User::ROLE_EXPERT;
        $this->assertFalse($user->canManageUsers());

        // 已启用的一般用户
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_REGULAR;
        $this->assertFalse($user->canManageUsers());

        // 已启用的专家用户
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_EXPERT;
        $this->assertTrue($user->canManageUsers());

        // 已启用的系统管理员
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_SUPER_ADMIN;
        $this->assertTrue($user->canManageUsers());

        // 已启用的众包用户
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_CROWDSOURCING;
        $this->assertFalse($user->canManageUsers());
    }

    /**
     * 测试 canRestoreOperations() 方法
     */
    #[Test]
    public function testCanRestoreOperations() {
        $user = new User();

        // 未启用的专家用户
        $user->is_active = User::STATUS_INACTIVE;
        $user->is_admin = User::ROLE_EXPERT;
        $this->assertFalse($user->canRestoreOperations());

        // 已启用的专家用户
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_EXPERT;
        $this->assertTrue($user->canRestoreOperations());

        // 已启用的系统管理员
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_SUPER_ADMIN;
        $this->assertTrue($user->canRestoreOperations());
    }

    /**
     * 测试 canWriteDirectly() 方法
     */
    #[Test]
    public function testCanWriteDirectly() {
        $user = new User();

        // 未启用的一般用户
        $user->is_active = User::STATUS_INACTIVE;
        $user->is_admin = User::ROLE_REGULAR;
        $this->assertFalse($user->canWriteDirectly());

        // 已启用的一般用户
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_REGULAR;
        $this->assertTrue($user->canWriteDirectly());

        // 已启用的专家用户
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_EXPERT;
        $this->assertTrue($user->canWriteDirectly());

        // 已启用的众包用户（不能直接写入）
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_CROWDSOURCING;
        $this->assertFalse($user->canWriteDirectly());
    }

    /**
     * 测试 canRunBatchImport() 方法
     */
    #[Test]
    public function testCanRunBatchImport() {
        $user = new User();

        // 未启用的专家用户
        $user->is_active = User::STATUS_INACTIVE;
        $user->is_admin = User::ROLE_EXPERT;
        $this->assertFalse($user->canRunBatchImport());

        // 已启用的一般用户
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_REGULAR;
        $this->assertFalse($user->canRunBatchImport());

        // 已启用的专家用户
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_EXPERT;
        $this->assertTrue($user->canRunBatchImport());

        // 已启用的系统管理员
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_SUPER_ADMIN;
        $this->assertTrue($user->canRunBatchImport());

        // 已启用的众包用户
        $user->is_active = User::STATUS_ACTIVE;
        $user->is_admin = User::ROLE_CROWDSOURCING;
        $this->assertFalse($user->canRunBatchImport());
    }

    /**
     * 测试 getRoleName() 方法
     */
    #[Test]
    public function testGetRoleName() {
        $user = new User();

        $user->is_admin = User::ROLE_REGULAR;
        $this->assertSame('一般', $user->getRoleName());

        $user->is_admin = User::ROLE_EXPERT;
        $this->assertSame('专家', $user->getRoleName());

        $user->is_admin = User::ROLE_CROWDSOURCING;
        $this->assertSame('众包', $user->getRoleName());

        $user->is_admin = User::ROLE_SUPER_ADMIN;
        $this->assertSame('系统管理员', $user->getRoleName());
    }
}
