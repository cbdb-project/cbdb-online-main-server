<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable {
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    // 帐号启用状态常量
    public const STATUS_INACTIVE = 0;
    public const STATUS_ACTIVE = 1;
    public const STATUS_RESERVED = 2;

    // 帐号角色常量
    public const ROLE_REGULAR = 0;
    public const ROLE_EXPERT = 1;
    public const ROLE_CROWDSOURCING = 2;
    public const ROLE_SUPER_ADMIN = 3;

    /**
     * The attributes that are mass assignable.
     *
     * 安全性：`is_admin`（角色）與 `is_active`（啟用狀態）是提權欄位，刻意不放進
     * $fillable，避免任何 `User::create($request->all())` / `$user->update($validated)`
     * 之類的寫法被動變成提權漏洞。要改這兩個欄位一律顯式單欄賦值
     * （`$user->is_admin = ...; $user->save();`）。
     *
     * 這是縱深防禦，不是唯一防線：真正的授權判斷仍在寫入端點
     * （`ManagementController::performUserUpdate()`）。注意該閘門目前只是
     * `canManageUsers()`（活躍的專家或系統管理員），尚未依操作者等級限制可授予的
     * 目標角色，專家仍能把任意帳號（含自己）提為系統管理員。
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'institution',
        'settings',
        'password',
        'avatar',
        'confirmation_token',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'integer',
        'is_admin' => 'integer',
    ];

    /**
     * 检查用户是否为活跃状态
     *
     * @return bool
     */
    public function isActive(): bool {
        return $this->is_active === self::STATUS_ACTIVE;
    }

    /**
     * 检查用户是否为专家或系统管理员
     *
     * @return bool
     */
    public function isAdmin(): bool {
        return in_array($this->is_admin, [self::ROLE_EXPERT, self::ROLE_SUPER_ADMIN]);
    }

    /**
     * 检查用户是否为专家用户
     *
     * @return bool
     */
    public function isExpert(): bool {
        return $this->is_admin === self::ROLE_EXPERT;
    }

    /**
     * 检查用户是否为系统管理员
     *
     * @return bool
     */
    public function isSuperAdmin(): bool {
        return $this->is_admin === self::ROLE_SUPER_ADMIN;
    }

    /**
     * 检查用户是否为众包用户
     *
     * @return bool
     */
    public function isCrowdsourcingUser(): bool {
        return $this->is_admin === self::ROLE_CROWDSOURCING;
    }

    /**
     * 检查用户是否为一般用户
     *
     * @return bool
     */
    public function isRegularUser(): bool {
        return $this->is_admin === self::ROLE_REGULAR;
    }

    /**
     * 检查用户是否可以管理其他用户（活跃的专家或系统管理员）
     *
     * @return bool
     */
    public function canManageUsers(): bool {
        return $this->isActive() && $this->isAdmin();
    }

    /**
     * 检查用户是否可以执行操作复原（活跃的专家或系统管理员）
     *
     * @return bool
     */
    public function canRestoreOperations(): bool {
        return $this->isActive() && $this->isAdmin();
    }

    /**
     * 检查用户是否可以审核提案（活跃的一般用户、专家或系统管理员）
     *
     * @return bool
     */
    public function canReviewProposals(): bool {
        return $this->isActive() && !$this->isCrowdsourcingUser();
    }

    /**
     * 检查用户是否可以查看審計日誌（活跃的专家或系统管理员）
     *
     * @return bool
     */
    public function canViewAuditLogs(): bool {
        return $this->isActive() && $this->isAdmin();
    }

    /**
     * 检查用户是否可以直接写入数据（活跃且非众包用户）
     *
     * @return bool
     */
    public function canWriteDirectly(): bool {
        return $this->isActive() && !$this->isCrowdsourcingUser();
    }

    /**
     * 检查用户是否可以提交提案（活跃用户即可，與後端 authorizeProposal 一致）
     *
     * @return bool
     */
    public function canPropose(): bool {
        return $this->isActive();
    }

    /**
     * 检查用户是否可以运行批量导入操作（活跃的专家或系统管理员）
     *
     * @return bool
     */
    public function canRunBatchImport(): bool {
        return $this->isActive() && $this->isAdmin();
    }

    /**
     * 获取用户角色名称（中文）
     *
     * @return string
     */
    public function getRoleName(): string {
        switch ($this->is_admin) {
            case self::ROLE_SUPER_ADMIN:
                return '系统管理员';
            case self::ROLE_EXPERT:
                return '专家';
            case self::ROLE_CROWDSOURCING:
                return '眾包';
            case self::ROLE_REGULAR:
            default:
                return '一般';
        }
    }

    public function operation() {
        return $this->has('App\Models\Operation');
    }
}
