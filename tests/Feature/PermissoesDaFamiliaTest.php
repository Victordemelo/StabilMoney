<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Models\Atividade;
use App\Models\User;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Permissões da página Família (out/2026 — pedido do Victor), em Configurações › Conta:
 *  - "Os dependentes veem a família" (nasce LIGADA): a página e o item da barra lateral
 *    aparecem para eles, só para ver. Desligada, é como antes: só o titular.
 *  - "Quer deixar que eles editem?" (nasce desligada, só com a primeira ligada): cada
 *    dependente edita o cadastro dos OUTROS (nome, e-mail, foto, parentesco, senha). Nunca o
 *    do titular — "o admin que criou a conta" —, nem com a edição ligada; o próprio, em Meu
 *    perfil. Adicionar e remover continuam só com o titular.
 */
class PermissoesDaFamiliaTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private User $ana;

    private User $bia;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->titular = User::factory()->create(['name' => 'Victor Titular', 'email' => 'titular@familia.test']);
        $this->ana = User::factory()->create(['account_owner_id' => $this->titular->id, 'is_admin' => false, 'name' => 'Ana', 'email' => 'ana@familia.test']);
        $this->bia = User::factory()->create(['account_owner_id' => $this->titular->id, 'is_admin' => false, 'name' => 'Bia', 'email' => 'bia@familia.test']);
    }

    private function permitir(bool $ver, bool $editar = false): void
    {
        $this->titular->forceFill(['familia_visivel' => $ver, 'familia_editavel' => $editar])->save();
    }

    private function pagina(User $quem): HTMLDocument
    {
        $html = $this->actingAs($quem)->get(route('dependentes'))->assertOk()->getContent();

        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    private function editar(User $quem, User $alvo, array $campos = []): TestResponse
    {
        return $this->actingAs($quem)->patch(route('dependentes.update', $alvo), [
            '_form' => 'edit-'.$alvo->id,
            'name' => $alvo->name,
            'email' => $alvo->email,
            ...$campos,
        ]);
    }

    public function test_por_padrao_os_dependentes_veem_a_familia_sem_editar(): void
    {
        $novo = User::factory()->create();
        $this->assertTrue($novo->familia_visivel);
        $this->assertFalse($novo->familia_editavel);

        $doc = $this->pagina($this->ana);
        $texto = $doc->body->textContent;
        foreach (['Victor Titular', 'Ana', 'Bia', 'Gastou no mês'] as $esperado) {
            $this->assertStringContainsString($esperado, $texto);
        }
        $this->assertNull($doc->getElementById('depAddBtn'), 'Adicionar é só do titular.');
        $this->assertNull($doc->getElementById('depModal'));
        $this->assertSame(0, $doc->querySelectorAll('[data-edit]')->length, 'Sem a edição liberada, nenhum lápis.');
        $this->assertSame(0, $doc->querySelectorAll('.dp-rm')->length, 'Remover é só do titular.');
        $this->assertNotNull($doc->querySelector('.dep-person .dp-badge.voce'), 'O card de quem vê leva o selo "Você".');

        // O item da barra lateral aparece para ela.
        $this->assertNotNull($doc->querySelector('.sidebar a.nav-item[href="'.route('dependentes').'"]'));
    }

    public function test_desligada_volta_ao_de_antes_so_o_titular(): void
    {
        $this->permitir(false);

        $this->actingAs($this->ana)->get(route('dependentes'))->assertForbidden();
        $html = $this->actingAs($this->ana)->get(route('dashboard'))->getContent();
        $this->assertStringNotContainsString('href="'.route('dependentes').'"', $html, 'O item Família some da barra lateral.');

        $this->pagina($this->titular); // o titular continua vendo
    }

    public function test_com_a_edicao_ligada_edita_os_outros_dependentes_e_nao_o_titular_nem_a_si(): void
    {
        $this->permitir(true, true);

        $doc = $this->pagina($this->ana);
        $this->assertNotNull($doc->querySelector('[data-edit="'.$this->bia->id.'"]'), 'Edita a Bia.');
        $this->assertNotNull($doc->getElementById('depEditModal-'.$this->bia->id));
        $this->assertNull($doc->querySelector('[data-edit="'.$this->ana->id.'"]'), 'O próprio cadastro é em Meu perfil.');
        $this->assertNotNull($doc->querySelector('a.dp-edit[href="'.route('profile.edit').'"]'));
        $this->assertNull($doc->querySelector('.dep-person.titular .dp-edit'), 'Nunca edita o titular.');
        $this->assertSame(0, $doc->querySelectorAll('.dp-rm')->length, 'Remover continua só do titular.');

        $this->editar($this->ana, $this->bia, ['name' => 'Beatriz', 'password' => ''])
            ->assertSessionHasNoErrors()->assertRedirect(route('dependentes'));
        $this->assertSame('Beatriz', $this->bia->fresh()->name);

        // A si mesma: não por aqui (Meu perfil pede a senha atual para trocar o e-mail).
        $this->editar($this->ana, $this->ana, ['name' => 'Outra'])->assertForbidden();
        $this->assertSame('Ana', $this->ana->fresh()->name);

        // O titular nem é um "dependente" na rota: não existe para ela.
        $this->editar($this->ana, $this->titular, ['name' => 'Tomado'])->assertNotFound();
        $this->assertSame('Victor Titular', $this->titular->fresh()->name);
    }

    public function test_sem_a_edicao_ligada_o_patch_e_recusado(): void
    {
        $this->permitir(true, false);

        $this->editar($this->ana, $this->bia, ['name' => 'Beatriz'])->assertForbidden();
        $this->assertSame('Bia', $this->bia->fresh()->name);
    }

    public function test_adicionar_e_remover_seguem_so_do_titular_mesmo_com_a_edicao_ligada(): void
    {
        $this->permitir(true, true);

        $this->actingAs($this->ana)->post(route('dependentes.store'), [
            'name' => 'Nova', 'email' => 'nova@familia.test', 'password' => 'senha-bem-comprida-123',
        ])->assertForbidden();
        $this->actingAs($this->ana)->delete(route('dependentes.destroy', $this->bia), ['password' => 'password'])->assertForbidden();

        $this->assertModelExists($this->bia);
        $this->assertFalse(User::where('email', 'nova@familia.test')->exists());
    }

    public function test_a_senha_trocada_por_outro_dependente_avisa_quem_foi(): void
    {
        $this->permitir(true, true);

        $this->editar($this->ana, $this->bia, ['password' => 'senha-nova-da-bia-123'])->assertSessionHasNoErrors();

        Mail::assertSent(AlertaDeSeguranca::class, function (AlertaDeSeguranca $mail) {
            $html = $mail->render();

            return $mail->hasTo('bia@familia.test')
                && str_contains($mail->assunto, 'Ana alterou a senha')
                && str_contains($html, '<strong>Ana</strong>, da sua conta-família, definiu')
                && ! str_contains($html, 'titular da sua conta-família');
        });
        $this->assertTrue(Atividade::where('acao', 'dependente.senha_trocada')->where('user_id', $this->ana->id)->exists(),
            'O registro de atividade diz que foi a Ana.');
    }

    public function test_dependente_de_outra_familia_nao_edita_mesmo_com_tudo_ligado(): void
    {
        $this->permitir(true, true);
        $outroTitular = User::factory()->create();
        $outroTitular->forceFill(['familia_visivel' => true, 'familia_editavel' => true])->save();
        $intruso = User::factory()->create(['account_owner_id' => $outroTitular->id, 'is_admin' => false]);

        $this->editar($intruso, $this->bia, ['name' => 'Invadido'])->assertNotFound();
        $this->assertSame('Bia', $this->bia->fresh()->name);
    }

    public function test_so_o_titular_muda_as_permissoes_e_fica_registrado(): void
    {
        $this->actingAs($this->ana)->patch(route('settings.familia'), ['permissao' => 'familia_editavel', 'ligada' => 1])
            ->assertForbidden();

        $this->actingAs($this->titular)->patch(route('settings.familia'), ['permissao' => 'familia_editavel', 'ligada' => 1])
            ->assertRedirect(route('settings', 'conta'));
        $this->assertTrue($this->titular->fresh()->familia_editavel);
        $this->assertTrue(Atividade::where('acao', 'familia.edicao_ligada')->exists());

        // Fechar a visão fecha a edição junto: religar a visão não devolve a edição sozinha.
        $this->actingAs($this->titular)->patch(route('settings.familia'), ['permissao' => 'familia_visivel', 'ligada' => 0]);
        $this->assertFalse($this->titular->fresh()->familia_visivel);
        $this->assertFalse($this->titular->fresh()->familia_editavel);
        $this->assertTrue(Atividade::where('acao', 'familia.visivel_desligada')->exists());

        // Edição sem a visão: recusado, nada muda.
        $this->actingAs($this->titular)->patch(route('settings.familia'), ['permissao' => 'familia_editavel', 'ligada' => 1])
            ->assertSessionHas('erro');
        $this->assertFalse($this->titular->fresh()->familia_editavel);
    }

    public function test_a_aba_conta_mostra_os_interruptores_ao_titular_e_o_estado_ao_dependente(): void
    {
        $html = $this->actingAs($this->titular)->get(route('settings', 'conta'))->assertOk()->getContent();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $this->assertSame('true', $doc->querySelector('[data-familia="familia_visivel"]')->getAttribute('aria-checked'));
        $this->assertSame('false', $doc->querySelector('[data-familia="familia_editavel"]')->getAttribute('aria-checked'));
        $this->assertStringContainsString('Quer deixar que eles editem?', $html);

        $this->permitir(false);
        $html = $this->actingAs($this->titular)->get(route('settings', 'conta'))->getContent();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $this->assertNull($doc->querySelector('button[data-familia="familia_editavel"]'), 'Sem a visão, a edição não é oferecida.');

        $html = $this->actingAs($this->ana)->get(route('settings', 'conta'))->getContent();
        $this->assertStringContainsString('A página Família está fechada', $html);
        $this->assertStringNotContainsString(route('settings.familia'), $html);
    }
}
