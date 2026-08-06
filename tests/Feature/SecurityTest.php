<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_tab_shows_password_and_sessions(): void
    {
        $user = User::factory()->create();

        // O 2FA saiu daqui em 06/08/2026: com senha + sessões + 2FA no mesmo lugar,
        // a aba passava de duas telas de rolagem. Agora tem aba própria (abaixo).
        $this->actingAs($user)->get('/configuracoes')
            ->assertOk()
            ->assertSee('Senha')
            ->assertSee('Sessões ativas')
            ->assertSee('Encerrar outras sessões')
            ->assertDontSee('Verificação em duas etapas');
    }

    public function test_2fa_tem_aba_propria(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/configuracoes/2fa')
            ->assertOk()
            ->assertSee('Verificação em duas etapas')
            ->assertSee('Ativar verificação em duas etapas')
            // Card lateral explicativo: o que é e como se recupera.
            ->assertSee('Como funciona')
            ->assertSee('Códigos de recuperação');
    }

    public function test_as_tres_abas_aparecem_e_a_atual_fica_marcada(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/configuracoes/2fa')->assertOk()->getContent();

        // Rótulo curto: "2FA" é mais reconhecível que o nome por extenso na pílula.
        $this->assertStringContainsString('>2FA</a>', $html);
        $this->assertStringContainsString('>Segurança</a>', $html);
        $this->assertStringContainsString('>Conta</a>', $html);
        $this->assertMatchesRegularExpression('/class="settings-tab active"[^>]*>\s*2FA/u', $html);
    }

    public function test_aba_conta_mostra_o_resumo_ao_lado_da_zona_de_perigo(): void
    {
        $user = User::factory()->create(['email' => 'dono@example.com']);

        $this->actingAs($user)->get('/configuracoes/conta')
            ->assertOk()
            ->assertSee('Sua conta')
            ->assertSee('dono@example.com')
            ->assertSee('Nenhum dependente')
            // A zona de perigo continua lá, agora com companhia.
            ->assertSee('Excluir conta');
    }

    public function test_changing_password_stamps_password_changed_at(): void
    {
        $user = User::factory()->create(['password_changed_at' => null]);

        $this->actingAs($user)->from('/configuracoes')->put('/password', [
            'current_password' => 'password',
            'password' => 'nova-senha-bem-forte-123',
            'password_confirmation' => 'nova-senha-bem-forte-123',
        ])->assertRedirect();

        $this->assertNotNull($user->fresh()->password_changed_at);
        $this->assertTrue(Hash::check('nova-senha-bem-forte-123', $user->fresh()->password));
    }

    public function test_destroying_other_sessions_requires_correct_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/configuracoes')
            ->delete(route('settings.sessions.destroy'), ['password' => 'senha-errada'])
            ->assertRedirect('/configuracoes')
            ->assertSessionHasErrors('password', errorBag: 'logoutOtherSessions');
    }

    public function test_destroying_other_sessions_removes_other_rows_but_keeps_current(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        $other = User::factory()->create();

        // Duas sessões antigas do usuário (ids diferentes da sessão da requisição,
        // então ambas devem cair) + uma sessão de outra pessoa (intocável).
        DB::table('sessions')->insert([
            ['id' => 'device-a', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'a', 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'device-b', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'b', 'payload' => 'x', 'last_activity' => time()],
            ['id' => 'device-c', 'user_id' => $other->id, 'ip_address' => '8.8.8.8', 'user_agent' => 'c', 'payload' => 'x', 'last_activity' => time()],
        ]);

        $this->actingAs($user)
            ->delete(route('settings.sessions.destroy'), ['password' => 'password'])
            ->assertRedirect();

        // As outras sessões do próprio usuário saem...
        $this->assertDatabaseMissing('sessions', ['id' => 'device-a']);
        $this->assertDatabaseMissing('sessions', ['id' => 'device-b']);
        // ...mas a de outra pessoa nunca é tocada.
        $this->assertDatabaseHas('sessions', ['id' => 'device-c']);
    }

    public function test_o_2fa_e_ligado_pelo_switch_e_nao_por_um_botao_separado(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/configuracoes/2fa')->assertOk()->getContent();

        // O <summary> É a linha do switch: clicar nela abre a confirmação por senha
        // dentro do próprio card, sem botão extra empurrando o conteúdo para baixo.
        $this->assertStringContainsString('class="tfa-toggle"', $html);
        $this->assertMatchesRegularExpression('/<summary class="sec-2fa-row"[^>]*>/u', $html);
        $this->assertStringContainsString('senha_ativar_2fa', $html, 'a senha precisa vir junto, no mesmo card');

        // O botão-gatilho antigo saiu de cena.
        $this->assertStringNotContainsString('sess-logout-trigger tfa-trigger', $html);
    }

    public function test_a_lista_de_autenticadores_da_familia_aparece(): void
    {
        $titular = User::factory()->create(['name' => 'Victor']);
        $dependente = User::factory()->create([
            'name' => 'Maria',
            'account_owner_id' => $titular->id,
            'is_admin' => false,
        ]);

        $html = $this->actingAs($titular)->get('/configuracoes/2fa')->assertOk()->getContent();

        $this->assertStringContainsString('Autenticadores da família', $html);
        $this->assertStringContainsString('Victor', $html);
        $this->assertStringContainsString('Maria', $html);
        // Ninguém ligou ainda: 0 de 2.
        $this->assertStringContainsString('0 de 2 pessoas', $html);
        // Nenhum segredo escapa para a tela.
        $this->assertStringNotContainsString($dependente->two_factor_secret ?? 'NADA-A-VAZAR', $html);
    }

    public function test_a_lista_da_familia_conta_quem_ja_ativou(): void
    {
        $titular = User::factory()->create(['name' => 'Victor']);
        User::factory()->create([
            'name' => 'Maria',
            'account_owner_id' => $titular->id,
            'is_admin' => false,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($titular)->get('/configuracoes/2fa')
            ->assertOk()
            ->assertSee('1 de 2 pessoas');
    }

    public function test_a_zona_de_perigo_lista_o_que_some(): void
    {
        $user = User::factory()->create();

        // A frase corrida virou lista: o tamanho do estrago precisa ser visível
        // antes de o dedo chegar no botão vermelho.
        $this->actingAs($user)->get('/configuracoes/conta')
            ->assertOk()
            ->assertSee('Contas e cartões')
            ->assertSee('Todo o histórico')
            ->assertSee('Os dependentes')
            ->assertSee('Excluir minha conta');
    }

    public function test_o_modal_de_excluir_usa_os_primitivos_do_design_system(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/configuracoes/conta')->assertOk()->getContent();

        // `.modal-scrim`/`.modal` já resolvem largura, centralização e rolagem. A
        // versão com utilitários Tailwind arbitrários (`w-full max-w-[440px]` num
        // grid `place-items-center`) colapsava o painel para MIN-CONTENT: ~100px,
        // com o texto saindo uma palavra por linha.
        $this->assertStringContainsString('id="confirm-user-deletion"', $html);
        $this->assertStringContainsString('modal-scrim', $html);
        $this->assertStringNotContainsString('max-w-[440px]', $html);
    }

    public function test_o_modal_de_excluir_fica_fora_de_qualquer_card(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/configuracoes/conta')->assertOk()->getContent();

        // `.card` tem overflow:hidden e animação com transform, e transform em
        // ancestral vira o bloco de contenção de um `position: fixed` — o modal
        // ficava preso e recortado dentro do card. Ele precisa nascer como IRMÃO.
        $posCard = strrpos($html, 'class="card sec-card span6"');
        $posModal = strpos($html, 'id="confirm-user-deletion"');
        $this->assertNotFalse($posModal);
        $this->assertGreaterThan($posCard, $posModal, 'o modal precisa vir DEPOIS do último card, fora dele');

        // E o card que o precede tem de estar fechado antes: nada de aninhamento.
        $entre = substr($html, $posCard, $posModal - $posCard);
        $this->assertSame(
            substr_count($entre, '<div'),
            substr_count($entre, '</div>'),
            'o card não foi fechado antes do modal — ele ficou aninhado',
        );
    }
}
