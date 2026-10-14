<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contas e cartões com o ÍCONE quadrado do banco (out/2026 — pedido do Victor: "em vez de mostrar
 * os cartões, ícones quadrados com a mesma dimensão, fundo transparente, 5 por linha, no mesmo
 * padrão"). A imagem do cartão tinha proporção diferente em cada banco.
 */
class ContasECartoesComIconeDoBancoTest extends TestCase
{
    use RefreshDatabase;

    public function test_todo_banco_tem_um_icone_quadrado_do_mesmo_tamanho_com_os_cantos_transparentes(): void
    {
        foreach (array_keys(Account::BANKS) as $banco) {
            $arquivo = public_path("assets/banks/icones/{$banco}.png");
            $this->assertFileExists($arquivo, "Falta o ícone do banco {$banco}.");

            [$largura, $altura, $tipo] = getimagesize($arquivo);
            $this->assertSame([256, 256, IMAGETYPE_PNG], [$largura, $altura, $tipo], "Ícone de {$banco} fora do padrão.");

            // Fundo transparente fora do canto arredondado: o PNG tem canal alfa (tipo de cor 6,
            // lido do cabeçalho IHDR — sem depender do GD do container).
            $cabecalho = file_get_contents($arquivo, false, null, 0, 33);
            $this->assertSame(6, ord($cabecalho[25]), "O ícone de {$banco} precisa ter canal alfa (fundo transparente).");
            $this->assertLessThan(60 * 1024, filesize($arquivo), "Ícone de {$banco} pesado demais.");
        }
    }

    public function test_a_tela_mostra_o_icone_em_grade_e_nao_mais_o_cartao(): void
    {
        $user = User::factory()->create();
        Account::factory()->for($user)->create(['type' => 'checking', 'name' => 'Conta principal', 'bank' => 'itau', 'initial_balance' => 100]);
        Account::factory()->for($user)->creditCard()->create(['name' => 'Roxinho', 'bank' => 'nubank']);
        $semBanco = Account::factory()->for($user)->create(['type' => 'savings', 'name' => 'Reserva antiga', 'bank' => null, 'initial_balance' => 0]);

        $html = $this->actingAs($user)->get(route('accounts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="acct-grade">', $html);
        $this->assertMatchesRegularExpression('#<img class="acct-ico" src="[^"]*assets/banks/icones/itau\.png" alt="Itaú"#', $html);
        $this->assertMatchesRegularExpression('#<img class="acct-ico" src="[^"]*assets/banks/icones/nubank\.png" alt="Nubank"#', $html);
        $this->assertStringNotContainsString('class="bankcard"', $html);
        // Sem banco: as iniciais no mesmo quadrado.
        $this->assertStringContainsString('<span class="acct-ico acct-ico-sem" aria-hidden="true">RE</span>', $html);
        // A linha embaixo do apelido: o tipo nas contas, o banco só quando o apelido não o diz.
        $this->assertStringContainsString('<span class="acct-type">Corrente · Itaú</span>', $html);
        $this->assertStringContainsString('<span class="acct-type">Poupança</span>', $html);
        $this->assertStringContainsString('<span class="acct-type">Nubank</span>', $html);
        $bradesco = Account::factory()->for($user)->create(['type' => 'checking', 'name' => 'Bradesco', 'bank' => 'bradesco', 'initial_balance' => 0]);
        $this->assertSame('Corrente', $bradesco->linhaDoCard(), '"Bradesco · Bradesco" não.');
        $this->assertSame('Cartão de Crédito', Account::factory()->for($user)->creditCard()->create(['name' => 'Nubank Roxinho', 'bank' => 'nubank'])->linhaDoCard());
        $this->assertNotNull($semBanco);

        $css = file_get_contents(resource_path('css/forms.css'));
        $this->assertMatchesRegularExpression('/\.acct-grade \{[^}]*grid-template-columns: repeat\(5, minmax\(0, 1fr\)\);/', $css);
    }

    public function test_o_formulario_e_as_movimentacoes_usam_o_mesmo_icone(): void
    {
        $user = User::factory()->create();
        $conta = Account::factory()->for($user)->create(['type' => 'checking', 'name' => 'Conta', 'bank' => 'santander', 'initial_balance' => 0]);

        $this->assertStringEndsWith('assets/banks/icones/santander.png', $conta->bankIconUrl());
        $this->assertNull(Account::factory()->for($user)->create(['bank' => null, 'type' => 'checking', 'initial_balance' => 0])->bankIconUrl());

        $editar = $this->actingAs($user)->get(route('accounts.edit', $conta))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-bank-preview\s+src="[^"]*assets/banks/icones/santander\.png"#', $editar);
    }
}
