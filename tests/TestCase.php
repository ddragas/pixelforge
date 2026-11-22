<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Server-side tests assert on what the server hands to the page, so they must
     * pass on a checkout where "npm run build" never ran. Only the browser tests
     * drive the real page and need the compiled frontend.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! str_contains(static::class, '\\Browser\\')) {
            $this->withoutVite();
        }
    }
}
