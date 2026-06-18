<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P2-2 codes/show Inertia 變體（app.codes.show）測試。
 */
class CodesShowInertiaTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $compiledViewPath = sys_get_temp_dir() . '/cbdb-test-views-codes-show-inertia';
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
        config(['codes.tables' => ['TEST_CODES' => '測試代碼']]);
        config(['codes.per_page' => 20]);

        Schema::create('TEST_CODES', function ($table) {
            $table->integer('code_id');
            $table->string('description')->nullable();
        });
        DB::table('TEST_CODES')->insert([
            ['code_id' => 1, 'description' => 'alpha'],
            ['code_id' => 2, 'description' => 'beta'],
            ['code_id' => 3, 'description' => 'gamma'],
        ]);
    }

    #[Test]
    public function it_renders_show_component_with_table_data(): void {
        $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Codes/Show')
                ->where('table', 'TEST_CODES')
                ->has('thead')
                ->has('rows', 3)
                ->where('use_cursor', false)
                ->has('meta', fn (Assert $meta) => $meta->where('total', 3)->etc())
                ->has('urls')
                ->has('page_translations.codes'));
    }

    #[Test]
    public function it_applies_search_filter(): void {
        $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES', 'search' => 'alpha']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('rows', 1)
                ->where('search', 'alpha'));
    }

    #[Test]
    public function it_applies_column_filter(): void {
        $this->get(route('app.codes.show', ['table_name' => 'TEST_CODES', 'filters' => ['description' => 'beta']]))
            ->assertInertia(fn (Assert $page) => $page->has('rows', 1));
    }

    #[Test]
    public function unknown_table_404(): void {
        $this->get(route('app.codes.show', ['table_name' => 'NOPE_TABLE']))->assertNotFound();
    }

    #[Test]
    public function missing_physical_table_redirects_to_index(): void {
        // 在 allowlist 內但實體表不存在 → PDOException → 重導 app.codes.index。
        config(['codes.tables' => ['TEST_CODES' => '測試代碼', 'GHOST_TABLE' => '幽靈表']]);

        $this->get(route('app.codes.show', ['table_name' => 'GHOST_TABLE']))
            ->assertRedirect(route('app.codes.index'));
    }
}
