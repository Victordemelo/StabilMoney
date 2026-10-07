<?php

namespace Tests\Feature;

use App\Models\Atividade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quando os Termos/Política mudam de versão, quem aceitou a anterior cai numa tela de aceite
 * antes de usar o app (out/2026 — decisão do Victor). O aceite grava a mesma prova do cadastro
 * (data, versão, IP — LGPD art. 8º, §1º) e entra no registro de atividade. Quem não concorda
 * pode sair ou excluir a conta sem aceitar nada (direito de eliminação).
 */
class AceiteDaPoliticaAtualTest extends TestCase
{
    use RefreshDatabase;

    private function versaoAntiga(): User
    {
        return User::factory()->aceitouAVersao('3.1')->create(['is_admin' => true]);
    }

    public function test_quem_aceitou_a_versao_atual_entra_direto(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
    }

    public function test_quem_aceitou_versao_antiga_cai_no_aceite_e_volta_para_onde_ia(): void
    {
        $user = $this->versaoAntiga();

        $this->actingAs($user)->get(route('metas.index'))->assertRedirect(route('termos.aceite'));

        $this->get(route('termos.aceite'))->assertOk()
            ->assertSee('Atualizamos os nossos documentos')
            ->assertSee('Versão '.config('legal.version'))
            ->assertSee('O que mudou na versão '.config('legal.version'))
            ->assertSee(config('legal.mudancas')[config('legal.version')][0])
            ->assertSee('name="aceito"', false)
            ->assertSee('Sair da conta');

        $this->post(route('termos.aceitar'), ['aceito' => '1'], ['REMOTE_ADDR' => '203.0.113.5'])
            ->assertRedirect(route('metas.index'));

        $user->refresh();
        $this->assertSame(config('legal.version'), $user->terms_version);
        $this->assertTrue($user->terms_accepted_at->isToday());
        $this->assertSame('203.0.113.5', $user->terms_accepted_ip);

        $this->get(route('metas.index'))->assertOk();
    }

    public function test_sem_marcar_a_caixa_nada_e_gravado(): void
    {
        $user = $this->versaoAntiga();

        $this->actingAs($user)->from(route('termos.aceite'))->post(route('termos.aceitar'), [])
            ->assertRedirect(route('termos.aceite'))
            ->assertSessionHasErrors(['aceito' => 'Marque a caixa para aceitar os Termos de Uso e a Política de Privacidade.']);

        $this->assertSame('3.1', $user->fresh()->terms_version);
        $this->get(route('dashboard'))->assertRedirect(route('termos.aceite'));
    }

    public function test_o_aceite_entra_no_registro_de_atividade(): void
    {
        $user = $this->versaoAntiga();

        $this->actingAs($user)->post(route('termos.aceitar'), ['aceito' => '1']);

        $linha = Atividade::where('acao', 'acesso.termos_aceitos')->sole();
        $this->assertSame($user->id, $linha->user_id);
        $this->assertSame('acesso', $linha->grupo);
        $this->assertStringContainsString('aceitou os Termos de Uso e a Política de Privacidade (versão '.config('legal.version').')', $linha->descricao);
    }

    public function test_dependente_que_nunca_aceitou_ve_o_primeiro_aceite(): void
    {
        $titular = User::factory()->create(['is_admin' => true]);
        $dependente = User::factory()->aceitouAVersao(null)->create(['account_owner_id' => $titular->id, 'is_admin' => false]);

        $this->actingAs($dependente)->get(route('dashboard'))->assertRedirect(route('termos.aceite'));
        $this->get(route('termos.aceite'))->assertOk()->assertSee('Antes de continuar');

        $this->post(route('termos.aceitar'), ['aceito' => '1'])->assertRedirect(route('dashboard'));
        $this->assertSame(config('legal.version'), $dependente->fresh()->terms_version);
        // Só o dependente aceitou: o titular não é tocado.
        $this->assertNull(Atividade::where('acao', 'acesso.termos_aceitos')->where('user_id', $titular->id)->first());
    }

    public function test_requisicao_json_recebe_403_explicando_em_vez_de_redirect(): void
    {
        $this->actingAs($this->versaoAntiga())
            ->postJson(route('transactions.store'), [])
            ->assertForbidden()
            ->assertJson(['aceitar' => route('termos.aceite')])
            ->assertJsonPath('message', 'Os Termos de Uso e a Política de Privacidade mudaram. Aceite a nova versão para continuar.');
    }

    public function test_quem_nao_concorda_pode_sair_ou_excluir_a_conta_sem_aceitar(): void
    {
        $user = $this->versaoAntiga();

        $this->actingAs($user)->get(route('settings', 'conta'))->assertOk();
        $this->get(route('settings', 'seguranca'))->assertRedirect(route('termos.aceite'));

        $this->delete(route('profile.destroy'), ['password' => 'password', 'confirmo_pendencias' => '1'])
            ->assertRedirect('/');
        $this->assertModelMissing($user);
    }

    public function test_sair_funciona_sem_aceitar(): void
    {
        $this->actingAs($this->versaoAntiga())->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_quem_ja_aceitou_nao_ve_a_tela_de_aceite(): void
    {
        $this->actingAs(User::factory()->create())->get(route('termos.aceite'))->assertRedirect(route('dashboard'));
    }

    public function test_a_tela_de_aceite_exige_login(): void
    {
        $this->get(route('termos.aceite'))->assertRedirect(route('login'));
        $this->post(route('termos.aceitar'), ['aceito' => '1'])->assertRedirect(route('login'));
    }

    public function test_toda_versao_tem_a_lista_do_que_mudou(): void
    {
        // A tela mostra "O que mudou" da versão atual: subir a legal.version sem dizer o que
        // mudou mandaria todo mundo aceitar às cegas.
        $this->assertNotEmpty(config('legal.mudancas')[config('legal.version')] ?? null);
    }

    public function test_no_celular_os_dois_botoes_ficam_no_centro(): void
    {
        $css = file_get_contents(resource_path('views/layouts/legal.blade.php'));

        $this->assertMatchesRegularExpression('/@media \(max-width: 600px\) \{\s*\.aceite-form > \.btn-primary \{ justify-self: center; \}\s*\.aceite-sair form \{[^}]*justify-content: center;/', $css);
    }
}
