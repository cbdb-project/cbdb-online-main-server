<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

trait CreatesApplication {
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication() {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();
        // Force in-memory SQLite for all tests to avoid touching external databases.
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        // Force non-file logging in tests to avoid permission issues.
        $app['config']->set('logging.default', 'errorlog');
        $app['config']->set('logging.channels.stack.channels', ['errorlog']);
        // Reset any pre-bootstrap connection that may have been opened by service providers.
        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException('Tests must run with SQLite in-memory database.');
        }

        return $app;
    }
}
