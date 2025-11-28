<?php

namespace App;

use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    // 帐号启用状态常量
    const STATUS_INACTIVE = 0;
    const STATUS_ACTIVE = 1;
    const STATUS_RESERVED = 2;

    // 帐号角色常量
    const ROLE_REGULAR = 0;
    const ROLE_EXPERT = 1;
    const ROLE_CROWDSOURCING = 2;
    const ROLE_SUPER_ADMIN = 3;

    /**
     * The attributes that are mass assignable.
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
    public function isActive(): bool
    {
        return $this->is_active === self::STATUS_ACTIVE;
    }

    /**
     * 检查用户是否为专家或系统管理员
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return in_array($this->is_admin, [self::ROLE_EXPERT, self::ROLE_SUPER_ADMIN]);
    }

    /**
     * 检查用户是否为专家用户
     *
     * @return bool
     */
    public function isExpert(): bool
    {
        return $this->is_admin === self::ROLE_EXPERT;
    }

    /**
     * 检查用户是否为系统管理员
     *
     * @return bool
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_admin === self::ROLE_SUPER_ADMIN;
    }

    /**
     * 检查用户是否为众包用户
     *
     * @return bool
     */
    public function isCrowdsourcingUser(): bool
    {
        return $this->is_admin === self::ROLE_CROWDSOURCING;
    }

    /**
     * 检查用户是否为一般用户
     *
     * @return bool
     */
    public function isRegularUser(): bool
    {
        return $this->is_admin === self::ROLE_REGULAR;
    }

    /**
     * 检查用户是否可以管理其他用户（活跃的专家或系统管理员）
     *
     * @return bool
     */
    public function canManageUsers(): bool
    {
        return $this->isActive() && $this->isAdmin();
    }

    /**
     * 检查用户是否可以执行操作复原（活跃的专家或系统管理员）
     *
     * @return bool
     */
    public function canRestoreOperations(): bool
    {
        return $this->isActive() && $this->isAdmin();
    }

    /**
     * 检查用户是否可以直接写入数据（活跃且非众包用户）
     *
     * @return bool
     */
    public function canWriteDirectly(): bool
    {
        return $this->isActive() && !$this->isCrowdsourcingUser();
    }

    /**
     * 获取用户角色名称（中文）
     *
     * @return string
     */
    public function getRoleName(): string
    {
        switch ($this->is_admin) {
            case self::ROLE_SUPER_ADMIN:
                return '系统管理员';
            case self::ROLE_EXPERT:
                return '专家';
            case self::ROLE_CROWDSOURCING:
                return '众包';
            case self::ROLE_REGULAR:
            default:
                return '一般';
        }
    }

    public function operation()
    {
        return $this->has('App\Operation');
    }
}
