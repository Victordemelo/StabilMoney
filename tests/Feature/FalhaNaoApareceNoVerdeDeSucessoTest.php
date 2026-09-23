<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Falha não aparece no verde de sucesso (achado da rodada de 23/09/2026).
 *
 * O defeito: "Não conseguimos remover Bruno agora, e nada foi apagado" era mandado pelo
 * `status` — o aviso VERDE, com o ✓, que some sozinho em 4 segundos. Uma falha dita no
 * visual de sucesso é lida como sucesso: a pessoa via o verde, não lia até o fim, e seguia
 * achando que o dependente tinha saído. O mesmo acontecia com o "este link de confirmação
 * não vale mais" da troca de e-mail.
 *
 * O comportamento certo: falha de ação que não é erro de campo vai por `->with('erro', ...)`
 * e sai no aviso VERMELHO (`.flash-error`, `role="alert"`), que não some sozinho.
 */
class FalhaNaoApareceNoVerdeDeSucessoTest extends TestCase
{
    use RefreshDatabase;

    /** O aviso verde (sucesso) — classe exata, para não casar com `flash-error`. */
    private const VERDE = 'class="flash" data-flash';

    public function test_falha_ao_remover_dependente_aparece_no_aviso_de_erro(): void
    {
        Exceptions::fake();

        $titular = User::factory()->create(['is_admin' => true]);
        $dependente = User::factory()->create([
            'name' => 'Bruno Dependente',
            'account_owner_id' => $titular->id,
            'is_admin' => false,
        ]);

        User::deleting(function (User $alvo) use ($dependente) {
            if ($alvo->is($dependente)) {
                throw new \RuntimeException('Falha simulada.');
            }
        });

        $pagina = $this->actingAs($titular)
            ->followingRedirects()
            ->delete(route('dependentes.destroy', $dependente))
            ->assertOk();

        $html = $pagina->getContent();
        $this->assertMatchesRegularExpression(
            '/<div class="flash-error" role="alert">\s*<svg[^>]*>.*?<\/svg>\s*Não conseguimos remover Bruno Dependente agora, e nada foi apagado\./s',
            $html,
            'A falha não saiu no aviso vermelho.',
        );
        $this->assertStringNotContainsString(self::VERDE, $html, 'A falha saiu no verde de sucesso.');
        $this->assertModelExists($dependente);
    }

    /** O sucesso continua no verde: a troca é só para falha. */
    public function test_remocao_que_da_certo_continua_no_verde(): void
    {
        $titular = User::factory()->create(['is_admin' => true]);
        $dependente = User::factory()->create(['account_owner_id' => $titular->id, 'is_admin' => false]);

        $html = $this->actingAs($titular)
            ->followingRedirects()
            ->delete(route('dependentes.destroy', $dependente))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::VERDE, $html);
        $this->assertStringContainsString('Dependente removido.', $html);
        // (`flash-error` em si aparece na página: os modais do shell têm o deles, escondido.)
        $this->assertStringNotContainsString('Não conseguimos', $html);
    }

    /**
     * O aviso de erro NÃO leva `data-flash`: é esse atributo que o `shell.js` usa para
     * sumir com o aviso em 4 segundos, e a falha é o que a pessoa precisa ler até o fim.
     */
    public function test_o_aviso_de_erro_nao_some_sozinho(): void
    {
        $titular = User::factory()->create();

        $html = $this->actingAs($titular)
            ->withSession(['erro' => 'Algo deu errado.'])
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<div class="flash-error" role="alert">/', $html);
        $this->assertDoesNotMatchRegularExpression('/<div class="flash-error"[^>]*data-flash/', $html);
    }

    /** O texto do aviso passa pelo escape do Blade, como o do verde. */
    public function test_o_aviso_de_erro_escapa_o_texto(): void
    {
        $titular = User::factory()->create();

        $this->actingAs($titular)
            ->withSession(['erro' => 'Não conseguimos remover <b>Ana</b>.'])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Não conseguimos remover &lt;b&gt;Ana&lt;/b&gt;.', false);
    }

    /** O link de troca de e-mail que já não vale sai como erro, no aviso da tela do perfil. */
    public function test_link_de_troca_de_email_que_nao_vale_mais_aparece_como_erro(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $ana = User::factory()->create(['email' => 'ana@antigo.test', 'password' => 'senha-da-ana']);
        $this->actingAs($ana)->patch(route('profile.update'), [
            'name' => $ana->name,
            'email' => 'ana@novo.test',
            'current_password' => 'senha-da-ana',
        ])->assertSessionHasNoErrors();

        // Link de um pedido ANTERIOR (outro endereço): um pedido mais novo tomou o lugar dele.
        $linkVelho = URL::temporarySignedRoute('profile.email.confirm', now()->addHours(2), [
            'user' => $ana->id,
            'hash' => sha1('ana@pedido-anterior.test'),
        ]);

        $this->get($linkVelho)
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('confirmacao_email')
            ->assertSessionMissing('status');

        $html = $this->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('Este link de confirmação não vale mais', $html);
        $this->assertStringNotContainsString(self::VERDE, $html);
        $this->assertSame('ana@antigo.test', $ana->fresh()->email);
    }
}
