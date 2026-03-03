<?php

namespace Tests\Unit;

use App\Console\Commands\RebuildIndexYear;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RebuildIndexYearCommandTest extends TestCase {
    #[Test]
    public function test_command_exits_with_error_on_sqlite(): void {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $this->artisan('cbdb:rebuild-index-year')
            ->expectsOutputToContain('不支援 SQLite')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_handle_does_not_call_begin_transaction(): void {
        $reflection = new \ReflectionClass(RebuildIndexYear::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString('beginTransaction', $source);
        $this->assertStringNotContainsString('DB::commit', $source);
        $this->assertStringNotContainsString('DB::rollBack', $source);
    }
}
