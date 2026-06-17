<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conta-família (Dependentes): titular e dependentes compartilham a mesma visão
 * financeira; o escopo das queries é por família (ownerId), não por usuário.
 */
class FamilyAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_titular_owner_id_is_self(): void
    {
        $titular = User::factory()->create();

        $this->assertSame($titular->id, $titular->ownerId());
        $this->assertTrue($titular->isTitular());
    }

    public function test_dependent_points_to_titular(): void
    {
        $titular = User::factory()->create();
        $dependent = User::factory()->create(['account_owner_id' => $titular->id]);

        $this->assertSame($titular->id, $dependent->ownerId());
        $this->assertFalse($dependent->isTitular());
        $this->assertTrue($titular->dependents->contains($dependent));
        $this->assertTrue($dependent->titular->is($titular));
    }
}
