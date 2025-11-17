<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        $compiledPath = dirname(__DIR__) . '/storage/framework/testing-views';
        if (!is_dir($compiledPath)) {
            mkdir($compiledPath, 0777, true);
        }

        // Ensure Laravel picks up the custom compiled path before the application boots.
        putenv('VIEW_COMPILED_PATH=' . $compiledPath);
        $_ENV['VIEW_COMPILED_PATH'] = $compiledPath;
        $_SERVER['VIEW_COMPILED_PATH'] = $compiledPath;

        parent::setUp();

        config(['view.compiled' => $compiledPath]);
    }
}
