<?php

namespace App\Console\Commands;

use App\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ManageUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cbdb:manage-user
                            {--email= : 用戶 Email（必填，用於查找或創建用戶）}
                            {--name= : 用戶名稱}
                            {--password= : 用戶密碼}
                            {--active= : 激活狀態 (0=未激活, 1=激活, 2=預留)}
                            {--role= : 用戶角色 (regular=一般, expert=專家, crowdsourcing=眾包, super-admin=系統管理員)}
                            {--list : 列出所有用戶}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '管理用戶：創建或更新用戶信息（名稱、Email、密碼、激活狀態、角色）';

    /**
     * 角色映射
     */
    protected $roleMap = [
        'regular' => User::ROLE_REGULAR,
        'expert' => User::ROLE_EXPERT,
        'crowdsourcing' => User::ROLE_CROWDSOURCING,
        'super-admin' => User::ROLE_SUPER_ADMIN,
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // 如果是列出用戶
        if ($this->option('list')) {
            return $this->listUsers();
        }

        // 獲取或詢問 Email
        $email = $this->option('email') ?: $this->ask('請輸入用戶 Email');

        if (!$email) {
            $this->error('Email 是必填項！');
            return 1;
        }

        // 驗證 Email 格式
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            $this->error('Email 格式無效！');
            return 1;
        }

        // 檢查用戶是否存在
        $user = User::where('email', $email)->first();

        if ($user) {
            $this->info("找到現有用戶：{$user->name} ({$user->email})");
            return $this->updateUser($user);
        } else {
            $this->info("用戶不存在，將創建新用戶");
            return $this->createUser($email);
        }
    }

    /**
     * 創建新用戶
     */
    protected function createUser($email)
    {
        $this->info('=== 創建新用戶 ===');

        // 獲取或詢問用戶名稱
        $name = $this->option('name') ?: $this->ask('請輸入用戶名稱');

        if (!$name) {
            $this->error('用戶名稱是必填項！');
            return 1;
        }

        // 獲取或詢問密碼
        $password = $this->option('password') ?: $this->secret('請輸入密碼（至少 6 個字符）');

        if (!$password || strlen($password) < 6) {
            $this->error('密碼必須至少 6 個字符！');
            return 1;
        }

        // 獲取或詢問激活狀態
        $active = $this->option('active');
        if ($active === null) {
            $active = $this->choice(
                '請選擇激活狀態',
                ['0' => '未激活', '1' => '激活', '2' => '預留'],
                '1'
            );
        }

        // 獲取或詢問角色
        $roleInput = $this->option('role');
        if (!$roleInput) {
            $roleInput = $this->choice(
                '請選擇用戶角色',
                [
                    'regular' => '一般用戶',
                    'expert' => '專家',
                    'crowdsourcing' => '眾包用戶',
                    'super-admin' => '系統管理員'
                ],
                'regular'
            );
        }

        $role = $this->roleMap[$roleInput] ?? User::ROLE_REGULAR;

        // 創建用戶（直接使用模型，避免依賴 dev-only 的 Faker）
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => (int)$active,
            'is_admin' => $role,
            'confirmation_token' => Str::random(32),
        ]);

        $this->newLine();
        $this->info('✓ 用戶創建成功！');
        $this->displayUserInfo($user);

        return 0;
    }

    /**
     * 更新現有用戶
     */
    protected function updateUser(User $user)
    {
        $this->info('=== 更新用戶信息 ===');

        $updated = false;

        // 更新名稱
        $name = $this->option('name');
        if ($name === null && $this->confirm("是否更新用戶名稱？（當前：{$user->name}）", false)) {
            $name = $this->ask('請輸入新的用戶名稱', $user->name);
        }
        if ($name && $name !== $user->name) {
            $user->name = $name;
            $updated = true;
        }

        // 更新密碼
        $password = $this->option('password');
        if ($password === null && $this->confirm('是否更新密碼？', false)) {
            $password = $this->secret('請輸入新密碼（至少 6 個字符）');
        }
        if ($password) {
            if (strlen($password) < 6) {
                $this->error('密碼必須至少 6 個字符！');
                return 1;
            }
            $user->password = Hash::make($password);
            $updated = true;
        }

        // 更新激活狀態
        $active = $this->option('active');
        if ($active === null && $this->confirm("是否更新激活狀態？（當前：{$user->is_active}）", false)) {
            $active = $this->choice(
                '請選擇激活狀態',
                ['0' => '未激活', '1' => '激活', '2' => '預留'],
                (string)$user->is_active
            );
        }
        if ($active !== null && (int)$active !== $user->is_active) {
            $user->is_active = (int)$active;
            $updated = true;
        }

        // 更新角色
        $roleInput = $this->option('role');
        if ($roleInput === null) {
            $currentRoleName = array_search($user->is_admin, $this->roleMap) ?: 'regular';
            if ($this->confirm("是否更新用戶角色？（當前：{$user->getRoleName()}）", false)) {
                $roleInput = $this->choice(
                    '請選擇用戶角色',
                    [
                        'regular' => '一般用戶',
                        'expert' => '專家',
                        'crowdsourcing' => '眾包用戶',
                        'super-admin' => '系統管理員'
                    ],
                    $currentRoleName
                );
            }
        }
        if ($roleInput) {
            $role = $this->roleMap[$roleInput] ?? User::ROLE_REGULAR;
            if ($role !== $user->is_admin) {
                $user->is_admin = $role;
                $updated = true;
            }
        }

        if (!$updated) {
            $this->info('沒有任何更新。');
            return 0;
        }

        $user->save();

        $this->newLine();
        $this->info('✓ 用戶信息更新成功！');
        $this->displayUserInfo($user);

        return 0;
    }

    /**
     * 列出所有用戶
     */
    protected function listUsers()
    {
        $users = User::orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->info('沒有找到任何用戶。');
            return 0;
        }

        $this->info("共找到 {$users->count()} 個用戶：");
        $this->newLine();

        $headers = ['ID', '名稱', 'Email', '狀態', '角色'];
        $rows = $users->map(function ($user) {
            return [
                $user->id,
                $user->name,
                $user->email,
                $this->getStatusText($user->is_active),
                $user->getRoleName(),
            ];
        });

        $this->table($headers, $rows);

        return 0;
    }

    /**
     * 顯示用戶信息
     */
    protected function displayUserInfo(User $user)
    {
        $this->table(
            ['屬性', '值'],
            [
                ['ID', $user->id],
                ['名稱', $user->name],
                ['Email', $user->email],
                ['激活狀態', $this->getStatusText($user->is_active)],
                ['角色', $user->getRoleName()],
                ['創建時間', $user->created_at],
            ]
        );
    }

    /**
     * 獲取狀態文本
     */
    protected function getStatusText($status)
    {
        $statusMap = [
            User::STATUS_INACTIVE => '未激活',
            User::STATUS_ACTIVE => '激活',
            User::STATUS_RESERVED => '預留',
        ];

        return $statusMap[$status] ?? '未知';
    }
}
