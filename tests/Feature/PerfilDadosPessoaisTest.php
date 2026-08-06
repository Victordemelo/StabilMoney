<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Perfil: data de nascimento e sexo.
 *
 * Os dois são OPCIONAIS de propósito — nada no app depende deles, e um app de
 * finanças não pede dado que não usa (minimização, LGPD art. 6º, III). O que os
 * testes fixam é justamente isso: salvar sem eles continua funcionando, e o que
 * entra é validado.
 */
class PerfilDadosPessoaisTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => 'Victor de Melo',
            'email' => 'victor@example.com',
            'phone' => '(51) 99999-0000',
        ], $extra);
    }

    public function test_salva_nascimento_e_sexo(): void
    {
        $user = User::factory()->create(['email' => 'victor@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload([
                'birth_date' => '1995-03-14',
                'gender' => 'feminino',
            ]))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('1995-03-14', $user->birth_date->format('Y-m-d'));
        $this->assertSame('feminino', $user->gender);
    }

    public function test_os_dois_campos_continuam_opcionais(): void
    {
        $user = User::factory()->create(['email' => 'victor@example.com']);

        // Quem não quer informar não informa — e salvar o resto tem de funcionar.
        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload())
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNull($user->birth_date);
        $this->assertNull($user->gender);
        $this->assertSame('Victor de Melo', $user->name);
    }

    public function test_limpar_um_valor_ja_gravado_funciona(): void
    {
        $user = User::factory()->create([
            'email' => 'victor@example.com',
            'birth_date' => '1990-01-01',
            'gender' => 'outro',
        ]);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload(['birth_date' => '', 'gender' => '']))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNull($user->birth_date, 'apagar o que já estava lá precisa funcionar');
        $this->assertNull($user->gender);
    }

    public static function datasInvalidas(): array
    {
        return [
            'no futuro' => ['2099-01-01'],
            'amanhã' => [null],           // resolvido no teste (depende de "hoje")
            'ano absurdo' => ['0210-05-04'],
            'texto' => ['ontem'],
        ];
    }

    #[DataProvider('datasInvalidas')]
    public function test_data_de_nascimento_invalida_e_recusada(?string $data): void
    {
        $user = User::factory()->create(['email' => 'victor@example.com']);
        $data ??= now()->addDay()->toDateString();

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload(['birth_date' => $data]))
            ->assertSessionHasErrors('birth_date');

        $this->assertNull($user->fresh()->birth_date);
    }

    public function test_nascer_hoje_e_aceito_na_fronteira(): void
    {
        $user = User::factory()->create(['email' => 'victor@example.com']);

        // A fronteira é `before_or_equal:today` — hoje ainda vale.
        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload(['birth_date' => now()->toDateString()]))
            ->assertSessionHasNoErrors();
    }

    public function test_sexo_fora_da_lista_e_recusado(): void
    {
        $user = User::factory()->create(['email' => 'victor@example.com']);

        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload(['gender' => 'qualquer_coisa']))
            ->assertSessionHasErrors('gender');

        $this->assertNull($user->fresh()->gender);
    }

    public function test_a_tela_oferece_os_campos_e_as_opcoes(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('name="birth_date"', $html);
        $this->assertStringContainsString('name="gender"', $html);
        foreach (User::GENEROS as $rotulo) {
            $this->assertStringContainsString($rotulo, $html);
        }
        // "Prefiro não informar" precisa existir: obrigar alguém a se declarar para
        // usar o app não serve a nada aqui.
        $this->assertStringContainsString('Prefiro não informar', $html);
    }

    public function test_a_tela_mostra_o_valor_ja_gravado(): void
    {
        $user = User::factory()->create(['birth_date' => '1988-07-09', 'gender' => 'masculino']);

        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();

        // O cast `date:Y-m-d` existe para o input[type=date] receber o formato que
        // ele aceita — com o cast padrão vinha "Y-m-d H:i:s" e o campo abria vazio.
        $this->assertStringContainsString('value="1988-07-09"', $html);
        $this->assertMatchesRegularExpression('/<option value="masculino"[^>]*selected/u', $html);
    }

    public function test_trocar_o_email_continua_exigindo_a_senha_atual(): void
    {
        $user = User::factory()->create(['email' => 'victor@example.com']);

        // Não regrediu com os campos novos: o e-mail é o que recupera a conta.
        $this->actingAs($user)
            ->patch(route('profile.update'), $this->payload(['email' => 'outro@example.com']))
            ->assertSessionHasErrors('current_password');

        $this->assertSame('victor@example.com', $user->fresh()->email);
    }
}
