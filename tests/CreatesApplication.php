<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

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
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException('Tests must run with SQLite in-memory database.');
        }

        return $app;
    }
}
