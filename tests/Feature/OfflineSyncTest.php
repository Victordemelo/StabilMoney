<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sincronização de lançamentos offline (Fase 2 do PWA).
 *
 * O cliente (offline-queue.js) guarda o lançamento feito offline com um
 * client_uuid e o reenvia por POST JSON quando volta online. O servidor
 * precisa: criar respondendo JSON, ser IDEMPOTENTE (não duplicar se o replay
 * reenviar o mesmo lançamento) e continuar redirecionando no fluxo web normal.
 */
class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->account = Account::factory()->for($this->user)->create();
    }

    /** @return array<string, mixed> */
    private function payload(string $uuid): array
    {
        return [
            'client_uuid' => $uuid,
            'type' => 'expense',
            'amount' => '25.90',
            'account_id' => $this->account->id,
            'date' => now()->toDateString(),
        ];
    }

    public function test_sync_post_creates_transaction_and_responds_json(): void
    {
        $uuid = (string) Str::uuid();

        $response = $this->actingAs($this->user)
            ->postJson('/transactions', $this->payload($uuid));

        $response->assertCreated(); // 201
        $this->assertDatabaseHas('transactions', [
            'client_uuid' => $uuid,
            'user_id' => $this->user->id,
            'type' => 'expense',
        ]);
    }

    public function test_sync_is_idempotent_on_repeated_client_uuid(): void
    {
        $uuid = (string) Str::uuid();

        // 1ª vez: cria (201).
        $this->actingAs($this->user)
            ->postJson('/transactions', $this->payload($uuid))
            ->assertCreated();

        // 2ª vez (replay reenviou o mesmo): NÃO duplica, responde 200.
        $this->actingAs($this->user)
            ->postJson('/transactions', $this->payload($uuid))
            ->assertOk();

        $this->assertSame(1, Transaction::where('client_uuid', $uuid)->count());
    }

    public function test_sync_post_with_invalid_account_returns_422_json(): void
    {
        $payload = $this->payload((string) Str::uuid());
        $payload['account_id'] = 999999; // não existe / não é da família

        $this->actingAs($this->user)
            ->postJson('/transactions', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_id');
    }

    public function test_normal_web_form_still_redirects_with_flash(): void
    {
        // post (não postJson) = sem Accept: json → fluxo web normal, intacto.
        $response = $this->actingAs($this->user)->post('/transactions', [
            'type' => 'expense',
            'amount' => '25,90',
            'account_id' => $this->account->id,
            'date' => now()->toDateString(),
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('transactions.index'));
        $response->assertSessionHas('status');
    }

    public function test_client_uuid_is_scoped_per_family(): void
    {
        $uuid = (string) Str::uuid();

        // Família A lança com o uuid.
        $this->actingAs($this->user)
            ->postJson('/transactions', $this->payload($uuid))
            ->assertCreated();

        // Família B (outro titular) com o MESMO uuid → cria normalmente,
        // sem colidir (idempotency key é escopada por família).
        $userB = User::factory()->create();
        $accountB = Account::factory()->for($userB)->create();

        $this->actingAs($userB)->postJson('/transactions', [
            'client_uuid' => $uuid,
            'type' => 'expense',
            'amount' => '10.00',
            'account_id' => $accountB->id,
            'date' => now()->toDateString(),
        ])->assertCreated();

        $this->assertSame(1, Transaction::where('user_id', $this->user->id)->where('client_uuid', $uuid)->count());
        $this->assertSame(1, Transaction::where('user_id', $userB->id)->where('client_uuid', $uuid)->count());
    }
}
