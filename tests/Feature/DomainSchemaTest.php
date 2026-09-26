<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_domains_carry_no_verification_token_because_dns_is_the_only_proof_of_control(): void
    {
        $this->assertFalse(Schema::hasColumn('domains', 'verification_token'));
        $this->assertTrue(Schema::hasColumn('domains', 'verified_at'));
    }
}
