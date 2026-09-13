<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test may reach the network. An unfaked outbound call used to go to the
     * real provider sandbox and, where the caller swallows failures, pass
     * silently — so fail it here, loudly, instead.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
