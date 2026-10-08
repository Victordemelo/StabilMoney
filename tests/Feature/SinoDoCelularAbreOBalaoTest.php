<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O sino do celular abre o MESMO balão do computador (out/2026 — Victor: tocar no sino sem
 * nada vencendo parecia não fazer nada). Antes era um link direto para Contas a pagar.
 */
class SinoDoCelularAbreOBalaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_vencimento_o_balao_diz_que_nada_vence(): void
    {
        $html = $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<button class="icon-btn " type="button"\s+id="mNotif"[^>]*aria-expanded="false" aria-haspopup="true">#', $html);
        $this->assertStringContainsString('id="mNotifPop" data-pjax-atualizar role="region"', $html);
        // O balão do celular e o do computador têm o mesmo conteúdo, inclusive o vazio.
        $this->assertSame(2, substr_count($html, '<p>Nada perto de vencer</p>'));
        $this->assertSame(2, substr_count($html, 'class="notif-ver" href="'.route('faturas.index').'"'));
    }

    public function test_com_vencimento_o_balao_do_celular_lista_a_conta(): void
    {
        $u = User::factory()->create();
        $conta = Account::factory()->for($u)->create(['type' => 'checking', 'initial_balance' => 1000]);
        FixedBill::create([
            'user_id' => $u->id, 'name' => 'Condomínio', 'amount' => 450, 'due_day' => (int) now()->addDays(2)->format('j'),
            'account_id' => $conta->id, 'starts_on' => now()->startOfMonth()->toDateString(), 'active' => true,
        ]);

        $html = $this->actingAs($u)->get(route('dashboard'))->assertOk()->getContent();
        $inicio = strpos($html, 'id="mNotifPop"');
        $balao = substr($html, $inicio, strpos($html, '</header>', $inicio) - $inicio);

        $this->assertStringContainsString('<strong>Condomínio</strong>', $balao);
        $this->assertStringNotContainsString('Nada perto de vencer', $balao);
    }
}
