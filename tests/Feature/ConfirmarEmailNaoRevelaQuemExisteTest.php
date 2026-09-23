<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O link de confirmação da troca de e-mail não revela quem tem conta (achado da rodada de
 * 23/09/2026, na varredura do 403 × 404).
 *
 * O defeito: `/meu-perfil/confirmar-email/{user}` tinha model binding, e o binding roda
 * ANTES do `signed`. Sem assinatura, um id que existe passava pelo binding e caía no 403 da
 * assinatura; um id que não existe parava no 404 do binding. Qualquer conta logada, trocando
 * o número na URL, contava os usuários do app.
 *
 * O comportamento certo: sem assinatura válida, todo id recebe a MESMA resposta. A pessoa só
 * é procurada depois de a assinatura conferir — e, se o link assinado é de uma conta que não
 * existe mais, a tela diz que ele é de outra conta, sem erro 500.
 */
class ConfirmarEmailNaoRevelaQuemExisteTest extends TestCase
{
    use RefreshDatabase;

    private function pedir(User $quem, string $id, bool $json = false): TestResponse
    {
        $cabecalhos = $json ? ['Accept' => 'application/json'] : [];

        return $this->actingAs($quem)->get('/meu-perfil/confirmar-email/'.$id.'?hash=qualquer', $cabecalhos);
    }

    /** Status, corpo e CSP (com o nonce, que muda a cada resposta, normalizado). */
    private function oQueSeVe(TestResponse $resposta): array
    {
        $semNonce = fn (?string $texto) => preg_replace('/nonce-[A-Za-z0-9+\/=_-]+/', 'nonce-X', (string) $texto);

        return [
            'status' => $resposta->getStatusCode(),
            'corpo' => $semNonce($resposta->getContent()),
            'csp' => $semNonce($resposta->headers->get('Content-Security-Policy')),
            'nonce' => $resposta->headers->has('X-Csp-Nonce'),
        ];
    }

    public function test_sem_assinatura_quem_existe_e_quem_nao_existe_recebem_o_mesmo_403(): void
    {
        // Como em produção: com o debug ligado, o JSON de erro traz o rastro da chamada, e o
        // rastro muda com a linha deste teste — não com a pessoa.
        config(['app.debug' => false]);

        $alguem = User::factory()->create();
        $curioso = User::factory()->create();
        $inexistente = (string) (User::max('id') + 1000);

        foreach ([false, true] as $json) {
            $existe = $this->oQueSeVe($this->pedir($curioso, (string) $alguem->id, $json));
            $naoExiste = $this->oQueSeVe($this->pedir($curioso, $inexistente, $json));

            $this->assertSame(403, $existe['status']);
            $this->assertSame($existe, $naoExiste, 'A resposta mudou conforme a pessoa existe ou não'.($json ? ' (JSON).' : '.'));
        }
    }

    /** Link assinado de uma conta que foi excluída depois: recado de "outra conta", nunca 500. */
    public function test_link_assinado_de_conta_que_nao_existe_mais_nao_quebra(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $sumiu = User::factory()->create();
        $link = URL::temporarySignedRoute('profile.email.confirm', now()->addHours(2), [
            'user' => $sumiu->id,
            'hash' => sha1('novo@exemplo.test'),
        ]);
        $sumiu->delete();

        $this->actingAs(User::factory()->create())->get($link)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('confirmacao_email');
    }

    /** O caminho de sempre continua: a própria conta, com o link assinado, confirma a troca. */
    public function test_o_dono_com_o_link_assinado_continua_confirmando(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $ana = User::factory()->create(['email' => 'ana@antigo.test', 'password' => 'senha-da-ana']);
        $this->actingAs($ana)->patch(route('profile.update'), [
            'name' => $ana->name,
            'email' => 'ana@novo.test',
            'current_password' => 'senha-da-ana',
        ])->assertSessionHasNoErrors();

        $link = URL::temporarySignedRoute('profile.email.confirm', now()->addHours(2), [
            'user' => $ana->id,
            'hash' => sha1('ana@novo.test'),
        ]);

        $this->actingAs($ana->fresh())->get($link)->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();
        $this->assertSame('ana@novo.test', $ana->fresh()->email);
    }
}
