<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The `array` cache store persists for the whole test-run process, so
        // RateLimiter buckets (keyed by IP) would otherwise accumulate across
        // tests and trip the API throttle. Reset per test.
        Cache::clear();
    }
}
