<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A ficha da pessoa diz se a conta tem 2FA, e desde quando (achado A-6 da auditoria de
 * 06/09/2026).
 *
 * O defeito: `AdminPanelService::COLUNAS` não tinha `two_factor_confirmed_at`, então o painel
 * não sabia responder à pergunta mais básica de quem modera — "esta conta estava protegida?".
 *
 * O comportamento certo: entra na lista fechada SÓ o carimbo da confirmação. O segredo, os
 * códigos de recuperação e o passo gasto nunca são lidos: o painel precisa saber que a
 * proteção existe, jamais poder usá-la no lugar da pessoa. É a mesma parede do dinheiro
 * (PainelAdminNaoVeValoresTest), e a checagem é na QUERY, não só no HTML.
 */
class PainelAdminFichaMostraDoisFatoresTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => true]);

        $this->admin = Admin::factory()->comDoisFatores()->create();
    }

    private function comoAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin')->withSession(['admin_2fa_ok' => true]);
    }

    /** Liga o 2FA de verdade (segredo + códigos + confirmação), com a data escolhida. */
    private function comDoisFatores(User $user, ?Carbon $confirmadoEm): User
    {
        $user->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => RecoveryCodes::gerar(),
            'two_factor_confirmed_at' => $confirmadoEm,
        ])->save();

        return $user->fresh();
    }

    public function test_a_ficha_mostra_desde_quando_cada_pessoa_tem_2fa(): void
    {
        $titular = $this->comDoisFatores(User::factory()->create(['is_admin' => true]), Carbon::parse('2026-08-15 10:00'));
        $this->comDoisFatores(
            User::factory()->create(['name' => 'Dependente Protegido', 'account_owner_id' => $titular->id, 'is_admin' => false]),
            Carbon::parse('2026-09-10 08:30'),
        );
        User::factory()->create(['name' => 'Dependente Sem', 'account_owner_id' => $titular->id, 'is_admin' => false]);

        $this->comoAdmin()->get(route('painel.pessoa', $titular->id))
            ->assertOk()
            ->assertSeeInOrder(['2FA', 'Ligado desde 15/08/2026'])
            ->assertSeeInOrder(['Dependente Protegido', '2FA ligado desde 10/09/2026'])
            ->assertSeeInOrder(['Dependente Sem', 'sem 2FA']);
    }

    /** Setup começado e não confirmado não protege nada — e aparece como tal. */
    public function test_2fa_so_configurado_pela_metade_aparece_desligado(): void
    {
        $titular = $this->comDoisFatores(User::factory()->create(['is_admin' => true]), confirmadoEm: null);

        $this->comoAdmin()->get(route('painel.pessoa', $titular->id))
            ->assertOk()
            ->assertSeeInOrder(['2FA', 'Desligado'])
            ->assertDontSee('Ligado desde');
    }

    /**
     * A parede: nenhuma query do painel lê o segredo, os códigos ou o passo gasto — nem pelo
     * nome, nem por um `select *` em `users`, que os traria sem nomeá-los.
     */
    public function test_o_painel_nunca_le_o_segredo_nem_os_codigos(): void
    {
        $titular = $this->comDoisFatores(User::factory()->create(['is_admin' => true]), now());
        $this->comDoisFatores(User::factory()->create(['account_owner_id' => $titular->id, 'is_admin' => false]), now());

        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = $q->sql;
        });

        $paginas = [
            $this->comoAdmin()->get(route('painel.pessoas'))->assertOk()->getContent(),
            $this->comoAdmin()->get(route('painel.pessoa', $titular->id))->assertOk()->getContent(),
            $this->comoAdmin()->get(route('painel.home'))->assertOk()->getContent(),
        ];

        $this->assertNotEmpty($consultas, 'Nenhuma query capturada — o teste não estaria provando nada.');

        foreach ($consultas as $sql) {
            foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'] as $coluna) {
                $this->assertStringNotContainsString($coluna, $sql, "O painel leu `{$coluna}`:\n{$sql}");
            }

            // As duas formas do "todas as colunas": `select * from users` e o `"users".*` que o
            // Eloquent escreve quando há `withCount` (ou join) e nenhum `select` explícito.
            $this->assertDoesNotMatchRegularExpression(
                '/select\s+\*\s+from\s+"users"|"users"\.\*/i',
                $sql,
                "Ler todas as colunas de users traz o segredo sem nomeá-lo:\n{$sql}",
            );
        }

        // E no HTML, nem por acaso.
        foreach ($paginas as $html) {
            $this->assertStringNotContainsString($titular->two_factor_secret, $html);
            foreach ($titular->two_factor_recovery_codes as $codigo) {
                $this->assertStringNotContainsString($codigo, $html);
            }
        }
    }
}
