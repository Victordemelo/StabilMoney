<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shell reorganizado (out/2026): menu por intenção, relógio no fuso escolhido, Contas e
 * cartões separados por tipo e o botão "Instalar o app".
 */
class ShellReorganizadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_por_intencao_com_a_linha_de_para_que_serve_e_as_vencidas(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-15 10:00:00'));
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'initial_balance' => 100]);
        FixedBill::create([
            'user_id' => $user->id, 'made_by_user_id' => $user->id, 'name' => 'Aluguel', 'amount' => 900,
            'due_day' => 5, 'account_id' => $conta->id, 'starts_on' => '2026-10-01', 'active' => true,
        ]);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#Início.*Dia a dia.*Planejamento.*Cadastros#s', $html);
        $this->assertStringContainsString('<span class="nav-desc">Pague faturas e contas</span>', $html);
        $this->assertStringContainsString('<span class="nav-desc">Bancos, cartões e Pix</span>', $html);
        // O "Novo lançamento" saiu da sidebar (out/2026): o "+" da topbar já está em toda tela.
        $this->assertStringNotContainsString('side-lancar', $html);
        $this->assertStringContainsString('data-launch-open', $html);
        // Aluguel venceu dia 5: o item "Contas a pagar" mostra 1 vencida.
        $this->assertMatchesRegularExpression('#id="navContasAPagar" data-pjax-atualizar><span class="badge late" title="1 conta vencida" aria-label="1 conta vencida">1</span>#', $html);

        // O que vence HOJE também entra no selo (out/2026).
        FixedBill::create([
            'user_id' => $user->id, 'made_by_user_id' => $user->id, 'name' => 'Internet', 'amount' => 100,
            'due_day' => 15, 'account_id' => $conta->id, 'starts_on' => '2026-10-01', 'active' => true,
        ]);
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#id="navContasAPagar" data-pjax-atualizar><span class="badge late" title="1 conta vencida · 1 vence hoje" aria-label="1 conta vencida · 1 vence hoje">2</span>#', $html);
    }

    public function test_relogio_em_brasilia_por_padrao_e_no_fuso_escolhido(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-04 15:30:00', new \DateTimeZone('America/Sao_Paulo')));
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertSee('data-fuso="America/Sao_Paulo"', false)
            ->assertSee('<span class="tb-hora" data-relogio-hora>15:30</span>', false)
            ->assertSee('Brasília · UTC−3');

        $this->actingAs($user)->patch(route('settings.relogio'), ['timezone' => 'America/Manaus'])
            ->assertRedirect(route('settings', 'conta'));
        $this->assertSame('America/Manaus', $user->fresh()->timezone);

        $this->actingAs($user->fresh())->get(route('dashboard'))
            ->assertSee('<span class="tb-hora" data-relogio-hora>14:30</span>', false)
            ->assertSee('Manaus · UTC−4');
    }

    public function test_fuso_fora_da_lista_e_recusado(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch(route('settings.relogio'), ['timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');
        $this->assertNull($user->fresh()->timezone);
    }

    public function test_contas_e_cartoes_separados_por_tipo_em_ordem_alfabetica(): void
    {
        $user = User::factory()->create();
        $corrente = Account::factory()->for($user)->create(['type' => 'checking', 'name' => 'Zeta Corrente', 'initial_balance' => 0]);
        Account::factory()->for($user)->create(['type' => 'savings', 'name' => 'alfa Poupança', 'initial_balance' => 0]);
        Account::factory()->for($user)->creditCard()->create(['name' => 'Roxinho']);
        Account::factory()->for($user)->creditCard()->create(['name' => 'Azul']);
        Account::factory()->for($user)->debitCard($corrente->id)->create(['name' => 'Débito']);

        $html = $this->actingAs($user)->get(route('accounts.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#>Contas <span class="acct-grupo-n">2</span>.*>Cartões de crédito <span class="acct-grupo-n">2</span>.*>Cartões de débito <span class="acct-grupo-n">1</span>#s', $html);
        $this->assertStringNotContainsString('>Pix e TED <span', $html, 'Grupo vazio não aparece.');
        $this->assertLessThan(strpos($html, 'Zeta Corrente'), strpos($html, 'alfa Poupança'), 'Ordem alfabética sem diferenciar maiúscula.');
        $this->assertLessThan(strpos($html, 'Roxinho'), strpos($html, 'Azul'));
    }

    public function test_instalar_o_app_no_login_e_nas_configuracoes(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('data-instalar-app hidden', false)->assertSee('data-instalar-ios hidden', false);

        // Aba própria desde out/2026 (antes era um card no pé da aba Conta).
        $this->actingAs(User::factory()->create())->get(route('settings', 'celular'))->assertOk()
            ->assertSee('>Instalar no celular</a>', false)
            ->assertSee('Aplicativo no celular')
            ->assertSee('Como instalar')
            ->assertSee('data-instalar-app hidden', false);
    }
}
