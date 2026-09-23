<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Exclusão pelo painel que falha vira recado, não HTTP 500 (achado da rodada 1, 22/09/2026).
 *
 * O `ModeracaoController::excluir` já rodava numa transação — uma falha no meio não apagava
 * nada —, mas a exceção subia até o topo: o admin via uma página de erro sem explicação, sem
 * saber se a família tinha sido apagada ou não, e a tentação é repetir. O comportamento certo:
 * a exceção vai para o log e a ficha volta com "não conseguimos excluir agora, e nada foi
 * apagado".
 *
 * E o recado só pode dizer "nada foi apagado" quando é verdade. O que roda no `afterCommit`
 * (a foto do disco, o e-mail do AdminAudit) roda com a exclusão JÁ feita, e uma exceção ali
 * sai pelo mesmo `DB::transaction()`. Nesse caso a tela conta a exclusão, e a falha fica só
 * no log.
 */
class PainelAdminExclusaoQueFalhaTest extends TestCase
{
    use RefreshDatabase;

    private const RECADO = 'Não conseguimos excluir esta conta agora, e nada foi apagado. Tente de novo em instantes.';

    private User $titular;

    private User $dependente;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => true]);
        Mail::fake();
        Exceptions::fake();

        $this->titular = User::factory()->create([
            'name' => 'Titular', 'email' => 'titular@familia.test', 'is_admin' => true,
        ]);
        $this->dependente = User::factory()->create([
            'account_owner_id' => $this->titular->id, 'is_admin' => false,
        ]);
    }

    /** Exclui o titular a partir da ficha dele, como o formulário faz. */
    private function excluir(): TestResponse
    {
        $admin = Admin::factory()->comDoisFatores()->create();

        return $this->actingAs($admin, 'admin')
            ->withSession(['admin_2fa_ok' => true])
            ->from(route('painel.pessoa', $this->titular->id))
            ->delete(route('painel.excluir', $this->titular->id), ['confirmacao' => 'titular@familia.test']);
    }

    /** A falha vem depois do DELETE do titular (e dos dependentes): quem devolve tudo é o rollback. */
    private function falharDepoisDoDeleteDoTitular(): void
    {
        User::deleted(function (User $alvo) {
            if ($alvo->is($this->titular)) {
                throw new \RuntimeException('Falha simulada depois do DELETE do titular.');
            }
        });
    }

    public function test_falha_no_meio_da_exclusao_volta_para_a_ficha_com_o_recado(): void
    {
        $this->falharDepoisDoDeleteDoTitular();

        $this->excluir()
            ->assertRedirect(route('painel.pessoa', $this->titular->id))
            ->assertSessionHasErrors(['excluir' => self::RECADO]);

        $this->assertModelExists($this->titular);
        $this->assertModelExists($this->dependente);
        $this->assertDatabaseMissing('admin_audit_logs', ['acao' => AdminAuditLog::EXCLUIU]);
        Mail::assertNothingSent();

        // A falha não some: vai para o log, como o 500 iria.
        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'Falha simulada'));
    }

    /** O que o admin VÊ: a ficha de pé, com o recado — nada de página de erro. */
    public function test_a_ficha_mostra_que_nada_foi_apagado(): void
    {
        $this->falharDepoisDoDeleteDoTitular();

        $this->followingRedirects()
            ->excluir()
            ->assertOk()
            ->assertSee('nada foi apagado')
            ->assertSee('titular@familia.test');
    }

    /** Falha ao gravar o histórico desfaz a exclusão — e também vira recado, não 500. */
    public function test_falha_ao_gravar_o_historico_tambem_vira_recado(): void
    {
        AdminAuditLog::creating(function (AdminAuditLog $log) {
            if ($log->acao === AdminAuditLog::EXCLUIU) {
                throw new \RuntimeException('Falha simulada ao gravar o histórico.');
            }
        });

        $this->excluir()->assertSessionHasErrors(['excluir' => self::RECADO]);

        $this->assertModelExists($this->titular);
        $this->assertModelExists($this->dependente);
        Mail::assertNothingSent();
        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'histórico'));
    }

    /**
     * O outro lado do "nada foi apagado": falha DEPOIS do commit não desfaz a exclusão, e o
     * recado não pode dizer que desfez — o admin tentaria apagar de novo quem já não existe.
     */
    public function test_falha_depois_do_commit_conta_a_exclusao_que_aconteceu(): void
    {
        User::deleted(function (User $alvo) {
            if ($alvo->is($this->titular)) {
                DB::afterCommit(fn () => throw new \RuntimeException('Falha simulada depois do commit.'));
            }
        });

        $this->excluir()
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('painel.pessoas'))
            ->assertSessionHas('status', 'Conta de Titular <titular@familia.test> excluída definitivamente.');

        $this->assertModelMissing($this->titular);
        $this->assertModelMissing($this->dependente);
        $this->assertDatabaseHas('admin_audit_logs', ['acao' => AdminAuditLog::EXCLUIU, 'target_user_id' => $this->titular->id]);
        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'depois do commit'));
    }
}
