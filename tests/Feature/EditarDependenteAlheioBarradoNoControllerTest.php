<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateDependentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\DesligaEscopoDaFamiliaNaRota;
use Tests\TestCase;

/**
 * Editar dependente de OUTRA família é recusado pelo próprio `DependentController::update`,
 * e não só pelo `authorize()` do UpdateDependentRequest.
 *
 * Achado da auditoria dos Form Requests: o `authorize()` era a ÚNICA barreira do `update`.
 * Numa mutação que o removeu, o dependente de outra família foi editado de fato — nome,
 * e-mail de acesso e senha, ou seja, a conta dele passava a ser de quem editou. O `destroy`
 * já tinha a própria checagem; agora o `update` tem a mesma.
 *
 * Para provar a segunda linha sozinha, os testes trocam o Form Request por uma subclasse
 * SEM a barreira (binding no container — o controller recebe a subclasse pelo type-hint).
 * O arquivo do Form Request não é tocado. O último teste confere que a subclasse deixa o
 * caminho legítimo passar: sem isso, um 403 aqui poderia vir da própria troca, e não da
 * checagem do controller.
 *
 * Desde 23/09/2026 há uma porta ANTES das duas: o `{dependent}` só encontra dependente da
 * família de quem pede (User::daFamiliaNaRota), e o de outra família recebe o 404 de um id
 * que não existe — coberto pelo IdAlheioNaRotaIgualAIdInexistenteTest. Aqui essa porta
 * também é desligada: senão o dependente alheio pararia no 404 do binding, sem chegar à
 * checagem do controller que este teste existe para provar.
 */
class EditarDependenteAlheioBarradoNoControllerTest extends TestCase
{
    use DesligaEscopoDaFamiliaNaRota, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // O binding como era antes do escopo de família: acha o dependente de QUALQUER família.
        $this->desligarEscopoDaFamiliaNaRota('dependent', User::class);

        // O Form Request como ele ficaria se alguém apagasse o `authorize()`: regras de
        // validação iguais, barreira de posse nenhuma.
        $semAutorizacao = new class extends UpdateDependentRequest
        {
            public function authorize(): bool
            {
                return true;
            }
        };

        $this->app->bind(UpdateDependentRequest::class, $semAutorizacao::class);
    }

    public function test_titular_de_outra_familia_nao_edita_dependente_alheio_mesmo_sem_o_authorize(): void
    {
        $intruso = User::factory()->create();
        $titularDono = User::factory()->create();
        $alheio = User::factory()->create([
            'account_owner_id' => $titularDono->id,
            'name' => 'Dependente de B',
            'email' => 'dependente@familia-b.test',
            'password' => Hash::make('senha-original-do-dependente'),
        ]);

        $this->actingAs($intruso)->patch(route('dependentes.update', $alheio), [
            '_form' => 'edit-'.$alheio->id,
            'name' => 'Tomado',
            'email' => 'intruso@familia-a.test',
            'password' => 'senha-do-intruso-123',
        ])->assertForbidden();

        $alheio->refresh();
        $this->assertSame('Dependente de B', $alheio->name);
        $this->assertSame('dependente@familia-b.test', $alheio->email);
        $this->assertTrue(Hash::check('senha-original-do-dependente', $alheio->password));
        $this->assertNull($alheio->password_changed_at);
        Mail::assertNothingSent();
    }

    /** A outra metade da regra (a mesma do `destroy`): dependente não edita ninguém. */
    public function test_dependente_nao_edita_outro_dependente_da_familia_mesmo_sem_o_authorize(): void
    {
        $titular = User::factory()->create();
        $dependente = User::factory()->create(['account_owner_id' => $titular->id]);
        $irmao = User::factory()->create(['account_owner_id' => $titular->id, 'name' => 'Irmão']);

        $this->actingAs($dependente)->patch(route('dependentes.update', $irmao), [
            '_form' => 'edit-'.$irmao->id,
            'name' => 'Renomeado pelo irmão',
            'email' => $irmao->email,
        ])->assertForbidden();

        $this->assertSame('Irmão', $irmao->fresh()->name);
    }

    /** Controle: com a subclasse no lugar, o titular dono segue editando normalmente. */
    public function test_sem_o_authorize_o_titular_dono_continua_editando(): void
    {
        $titular = User::factory()->create();
        $dependente = User::factory()->create(['account_owner_id' => $titular->id, 'name' => 'Antes']);

        $this->actingAs($titular)->patch(route('dependentes.update', $dependente), [
            '_form' => 'edit-'.$dependente->id,
            'name' => 'Depois',
            'email' => $dependente->email,
        ])->assertRedirect(route('dependentes'));

        $this->assertSame('Depois', $dependente->fresh()->name);
    }
}
