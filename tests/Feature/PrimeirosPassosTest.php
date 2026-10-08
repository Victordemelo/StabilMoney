<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PrimeirosPassos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * "Primeiros passos" (08/10/2026 — pedido do Victor): o card no topo da Visão geral diz a quem
 * acabou de se cadastrar por onde começar; cada passo se marca sozinho pelos dados da família.
 */
class PrimeirosPassosTest extends TestCase
{
    use RefreshDatabase;

    private function painel(User $user): string
    {
        return $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
    }

    public function test_conta_nova_ve_o_card_com_os_dois_passos_por_fazer(): void
    {
        $user = User::factory()->create();

        $html = $this->painel($user);
        $this->assertStringContainsString('data-primeiros-passos', $html);
        $this->assertStringContainsString('<b>0</b> de 2', $html);
        $this->assertStringContainsString('Cadastrar sua conta de banco', $html);
        $this->assertStringContainsString('Fazer o primeiro lançamento', $html);
        $this->assertStringContainsString('data-tutorial-iniciar', $html);
    }

    public function test_os_passos_se_marcam_sozinhos(): void
    {
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 100]);

        $passos = PrimeirosPassos::de($user);
        $this->assertSame(1, $passos['feitos']);

        Transaction::factory()->for($user)->for($conta)->create(['type' => 'income', 'amount' => 10]);
        $passos = PrimeirosPassos::de($user->fresh());
        $this->assertSame(2, $passos['feitos']);
        $this->assertStringContainsString('<b>2</b> de 2', $this->painel($user));
    }

    public function test_esconder_tira_o_card_e_o_tutorial_traz_de_volta(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('primeiros-passos.ocultar'))->assertRedirect();
        $this->assertNotNull($user->fresh()->primeiros_passos_ocultos_at);
        $this->assertStringNotContainsString('data-primeiros-passos', $this->painel($user->fresh()));

        // No Tutorial ele continua, com o botão de trazer de volta.
        $this->actingAs($user->fresh())->get(route('tutorial'))->assertOk()
            ->assertSee('data-primeiros-passos', false)
            ->assertSee('Mostrar de novo na Visão geral');

        $this->actingAs($user->fresh())->patch(route('primeiros-passos.mostrar'))->assertRedirect(route('dashboard'));
        $this->assertNull($user->fresh()->primeiros_passos_ocultos_at);
    }

    public function test_dependente_tem_roteiro_proprio_sem_o_que_ele_nao_pode_fazer(): void
    {
        $titular = User::factory()->create(['is_admin' => true]);
        $dependente = User::factory()->create(['account_owner_id' => $titular->id, 'is_admin' => false]);

        $html = $this->painel($dependente);
        $this->assertStringContainsString('Fazer o seu primeiro lançamento', $html);
        $this->assertStringContainsString('<b>0</b> de 1', $html);
        $this->assertStringNotContainsString('Cadastrar sua conta de banco', $html);
        $this->assertStringNotContainsString('Alguém da família', $html);
    }

    public function test_quem_ja_fez_tudo_nao_ve_o_card(): void
    {
        $user = User::factory()->create(['two_factor_secret' => 'x', 'two_factor_confirmed_at' => now()]);
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 100]);
        Account::factory()->for($user)->creditCard()->create();
        Transaction::factory()->for($user)->for($conta)->create(['type' => 'income', 'amount' => 10]);
        FixedBill::factory()->create(['user_id' => $user->id, 'account_id' => $conta->id]);
        Goal::factory()->create(['user_id' => $user->id]);
        User::factory()->create(['account_owner_id' => $user->id, 'is_admin' => false]);

        $this->assertTrue(PrimeirosPassos::de($user)['completo']);
        $this->assertStringNotContainsString('data-primeiros-passos', $this->painel($user));
    }

    public function test_a_migration_esconde_o_card_de_quem_ja_usa_o_app(): void
    {
        $usa = User::factory()->create();
        $conta = Account::factory()->for($usa)->create(['type' => 'checking', 'initial_balance' => 100]);
        Transaction::factory()->for($usa)->for($conta)->create(['type' => 'income', 'amount' => 10]);
        $novo = User::factory()->create();

        $migration = require database_path('migrations/2026_10_08_000100_ultimo_acesso_e_primeiros_passos_nos_usuarios.php');
        Schema::table('users', fn ($t) => $t->dropColumn(['last_login_at', 'last_seen_at', 'primeiros_passos_ocultos_at']));
        $migration->up();

        $this->assertNotNull($usa->fresh()->primeiros_passos_ocultos_at);
        $this->assertNull($novo->fresh()->primeiros_passos_ocultos_at);
    }
}
