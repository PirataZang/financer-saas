<?php

namespace Tests;

use App\Models\Company;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Assertions outside a request (Customer::sole(), factories) run as the default company,
     * like the seeders; tests of another tenant use Company::as($id, fn () => ...).
     */
    protected function setUp(): void
    {
        parent::setUp();
        Company::use(Company::DEFAULT_ID);
    }
}
