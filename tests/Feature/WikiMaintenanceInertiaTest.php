<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P5-9 admin/wiki-maintenance Inertia 變體測試。
 */
class WikiMaintenanceInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-wiki';
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

        Schema::create('BIOG_SOURCE_DATA', function ($table) {
            $table->integer('c_personid');
            $table->integer('c_textid');
            $table->string('c_pages')->nullable();
            $table->text('c_notes')->nullable();
        });

        Schema::create('BIOG_MAIN', function ($table) {
            $table->integer('c_personid')->primary();
            $table->string('c_name_chn')->nullable();
        });

        Schema::create('TEXT_CODES', function ($table) {
            $table->integer('c_textid')->primary();
            $table->string('c_url_api')->nullable();
            $table->string('c_url_api_coda')->nullable();
        });
    }

    private function admin(): User {
        return User::create([
            'name' => 'Admin', 'email' => 'a@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_SUPER_ADMIN,
        ]);
    }

    #[Test]
    public function renders_component_with_sources_and_records(): void {
        DB::table('BIOG_MAIN')->insert(['c_personid' => 1, 'c_name_chn' => '某人']);
        DB::table('TEXT_CODES')->insert(['c_textid' => 60795, 'c_url_api' => 'https://zh.wikipedia.org/wiki/', 'c_url_api_coda' => '']);
        DB::table('BIOG_SOURCE_DATA')->insert(['c_personid' => 1, 'c_textid' => 60795, 'c_pages' => '李白', 'c_notes' => null]);

        $this->actingAs($this->admin())
            ->get(route('app.admin.wiki-maintenance'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/WikiMaintenance/Index')
                ->where('current_source_id', 60795)
                ->has('sources', 3)
                ->where('records.0.c_personid', 1)
                ->where('records.0.link', 'https://zh.wikipedia.org/wiki/%E6%9D%8E%E7%99%BD')
                ->has('urls.index')
                ->has('page_translations.admin'));
    }

    #[Test]
    public function non_admin_forbidden(): void {
        $regular = User::create([
            'name' => 'R', 'email' => 'r@example.com', 'password' => bcrypt('x'),
            'confirmation_token' => 't', 'is_active' => 1, 'is_admin' => User::ROLE_REGULAR,
        ]);

        $this->actingAs($regular)
            ->get(route('app.admin.wiki-maintenance'))
            ->assertForbidden();
    }
}
