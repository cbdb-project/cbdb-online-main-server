<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase {
    use CreatesApplication;

    protected function setUp(): void {
        parent::setUp();
        $this->serverVariables['HTTP_ACCEPT_LANGUAGE'] = 'zh-TW,zh;q=0.9,en;q=0.1';
    }
}
