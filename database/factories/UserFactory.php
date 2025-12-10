<?php

namespace Database\Factories;

use App\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UserFactory extends Factory {
    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition() {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => bcrypt('secret'), // 默认密码
            'remember_token' => Str::random(10),
            'confirmation_token' => Str::random(32), // 添加 confirmation_token 避免 NOT NULL 约束错误
            'is_active' => User::STATUS_ACTIVE, // 默认为活跃状态
            'is_admin' => User::ROLE_REGULAR, // 默认为一般用户
        ];
    }

    /**
     * 设置用户为活跃状态
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function active() {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => User::STATUS_ACTIVE,
            ];
        });
    }

    /**
     * 设置用户为非活跃状态
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function inactive() {
        return $this->state(function (array $attributes) {
            return [
                'is_active' => User::STATUS_INACTIVE,
            ];
        });
    }

    /**
     * 设置用户为系统管理员
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function superAdmin() {
        return $this->state(function (array $attributes) {
            return [
                'is_admin' => User::ROLE_SUPER_ADMIN,
            ];
        });
    }

    /**
     * 设置用户为专家
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function expert() {
        return $this->state(function (array $attributes) {
            return [
                'is_admin' => User::ROLE_EXPERT,
            ];
        });
    }

    /**
     * 设置用户为众包用户
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function crowdsourcing() {
        return $this->state(function (array $attributes) {
            return [
                'is_admin' => User::ROLE_CROWDSOURCING,
            ];
        });
    }

    /**
     * 设置用户为一般用户
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public function regular() {
        return $this->state(function (array $attributes) {
            return [
                'is_admin' => User::ROLE_REGULAR,
            ];
        });
    }
}
