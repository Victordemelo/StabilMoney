<?php

namespace Tests\Feature;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * T-6 da auditoria de 02/09/2026: meta com prazo vencido tem de continuar
 * editável.
 *
 * O `after_or_equal:hoje` do `StoreGoalRequest` era herdado inteiro pelo
 * `UpdateGoalRequest`. O modal de edição pré-preenche o prazo antigo e o envia de
 * volta junto com o resto — então, passado o prazo, nem renomear a meta dava:
 * o servidor recusava a data que ele mesmo tinha gravado.
 *
 * A regra agora: o prazo INALTERADO é aceito; trocar para outra data no passado
 * continua recusado, e a criação não regride.
 */
class MetaComPrazoVencidoEditavelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Goal $meta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->user);

        $this->meta = Goal::factory()->for($this->user)->create([
            'name' => 'Viagem de fim de ano',
            'target_amount' => 3000,
            'emoji' => '✈️',
            'color' => '#0F6B47',
            'target_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
        ]);
    }

    /** Envia o formulário do modal de edição: todos os campos, como o Blade faz. */
    private function editar(array $extra = []): TestResponse
    {
        return $this->patch(route('metas.update', $this->meta), array_merge([
            'name' => $this->meta->name,
            'target_amount' => '3.000,00',
            // O <input type="month"> manda "AAAA-MM" — o prazo antigo, pré-preenchido.
            'target_date' => $this->meta->target_date->format('Y-m'),
            'emoji' => '✈️',
            'color' => '#0F6B47',
        ], $extra));
    }

    public function test_renomear_meta_com_prazo_vencido_e_aceito_quando_a_data_nao_muda(): void
    {
        $prazoAntigo = $this->meta->target_date->toDateString();

        $this->editar(['name' => 'Viagem (adiada)'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('metas.index'));

        $meta = $this->meta->fresh();
        $this->assertSame('Viagem (adiada)', $meta->name);
        $this->assertSame($prazoAntigo, $meta->target_date->toDateString(), 'O prazo gravado não podia ter mudado.');
    }

    public function test_trocar_o_prazo_para_outra_data_passada_continua_recusado(): void
    {
        $this->editar(['target_date' => now()->subMonths(5)->format('Y-m')])
            ->assertSessionHasErrors('target_date');

        $this->assertSame(
            now()->subMonths(2)->startOfMonth()->toDateString(),
            $this->meta->fresh()->target_date->toDateString(),
        );
    }

    public function test_trocar_o_prazo_para_uma_data_futura_segue_valendo(): void
    {
        $this->editar(['target_date' => now()->addMonths(3)->format('Y-m')])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            now()->addMonths(3)->startOfMonth()->toDateString(),
            $this->meta->fresh()->target_date->toDateString(),
        );
    }

    public function test_limpar_o_prazo_vencido_tambem_e_aceito(): void
    {
        $this->editar(['target_date' => ''])->assertSessionHasNoErrors();

        $this->assertNull($this->meta->fresh()->target_date);
    }

    /** A criação NÃO regride: data passada continua recusada. */
    public function test_criar_meta_com_prazo_no_passado_continua_recusado(): void
    {
        $this->post(route('metas.store'), [
            'name' => 'Meta atrasada',
            'target_amount' => '500,00',
            'target_date' => now()->subMonths(2)->format('Y-m'),
            'emoji' => '🎯',
            'color' => '#0F6B47',
        ])->assertSessionHasErrors('target_date');

        $this->assertSame(1, Goal::count());
    }

    /** A folga vale só para a meta da rota: o prazo vencido de OUTRA meta não serve de salvo-conduto. */
    public function test_o_prazo_perdoado_e_o_da_propria_meta(): void
    {
        $outra = Goal::factory()->for($this->user)->create([
            'target_date' => now()->subMonths(4)->startOfMonth()->toDateString(),
        ]);

        $this->editar(['target_date' => $outra->target_date->format('Y-m')])
            ->assertSessionHasErrors('target_date');
    }
}
