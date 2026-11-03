<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Tests wipe and rebuild their database. They must never be pointed at a real one. */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $db = (string) config('database.connections.'.config('database.default').'.database');
        if (!str_ends_with($db, '_test')) {
            throw new RuntimeException("Refusing to run tests against database '$db'. Test databases must end in _test.");
        }

        return $app;
    }
}
