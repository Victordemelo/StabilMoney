<?php

namespace Tests\Feature;

use App\Mail\BemVindoDependente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `POST /dependentes` tem o mesmo limite do cadastro público (24/09/2026).
 *
 * Cadastrar dependente cria um login e manda e-mail para o endereço digitado — é o
 * `/register` feito pelo titular. O `/register` leva `throttle:credencial` (5/min por IP),
 * e é esse limite que sustenta a decisão de 23/09 de ele seguir dizendo "Este e-mail já está
 * em uso" (a varredura fica cara). Esta rota não tinha limite nenhum:
 *
 *  - SONDA: com uma senha curta nada é criado, e a resposta diz se o e-mail tem conta no
 *    app — a mesma informação do cadastro, sem o teto que a tornava cara;
 *  - DISPARO: cada cadastro manda o "Bem-vindo" a um endereço qualquer, com o nome do
 *    titular (texto livre) no assunto — e-mail saindo do domínio do app, sem teto.
 */
class CadastroDeDependenteComLimiteTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    protected function setUp(): void
    {
        parent::setUp();

        $this->titular = User::factory()->create(['is_admin' => true, 'account_owner_id' => null]);
    }

    public function test_a_sonda_de_email_cadastrado_esbarra_no_limite(): void
    {
        User::factory()->create(['email' => 'cliente@exemplo.test']);

        $sondar = fn (string $email) => $this->actingAs($this->titular)
            ->postJson(route('dependentes.store'), ['name' => 'X', 'email' => $email, 'password' => 'curta']);

        // A diferença existe (é a mesma do cadastro público, decisão de 23/09)...
        $this->assertArrayHasKey('email', $sondar('cliente@exemplo.test')->assertStatus(422)->json('errors'));
        $this->assertArrayNotHasKey('email', $sondar('ninguem@exemplo.test')->assertStatus(422)->json('errors'));

        for ($i = 3; $i <= 5; $i++) {
            $sondar("chute{$i}@exemplo.test")->assertStatus(422);
        }

        // ...mas não sem teto: a 6ª no mesmo minuto nem chega a ser validada.
        $sondar('outro-cliente@exemplo.test')->assertStatus(429);

        $this->assertSame(0, User::where('account_owner_id', $this->titular->id)->count());
    }

    public function test_o_bem_vindo_nao_sai_sem_teto_para_enderecos_quaisquer(): void
    {
        Mail::fake();

        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($this->titular)
                ->post(route('dependentes.store'), [
                    'name' => "Pessoa {$i}",
                    'email' => "pessoa{$i}@exemplo.test",
                    'password' => 'senha-forte-'.$i.'-xyz',
                ])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($this->titular)
            ->post(route('dependentes.store'), [
                'name' => 'Pessoa 6',
                'email' => 'pessoa6@exemplo.test',
                'password' => 'senha-forte-6-xyz',
            ])
            ->assertStatus(429);

        Mail::assertSent(BemVindoDependente::class, 5);
        $this->assertNull(User::where('email', 'pessoa6@exemplo.test')->first());
    }

    /** Controle: o uso de verdade (um dependente por vez) segue igual. */
    public function test_cadastrar_um_dependente_continua_funcionando(): void
    {
        $this->actingAs($this->titular)
            ->post(route('dependentes.store'), [
                'name' => 'Ana',
                'email' => 'ana@exemplo.test',
                'password' => 'senha-forte-da-ana',
            ])
            ->assertRedirect(route('dependentes'))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->titular->id, User::where('email', 'ana@exemplo.test')->value('account_owner_id'));
    }
}
