<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.env' => 'testing',
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
    }
}
