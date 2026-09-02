<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Banir, desbanir e excluir — as ações que mexem na conta de outra pessoa.
 *
 * Duas regras estruturam tudo aqui:
 *
 *  - **Banir é reversível e não apaga nada.** Bloqueia o acesso do titular E dos
 *    dependentes (eles enxergam os mesmos dados da família; deixá-los entrar tornaria
 *    o banimento decorativo).
 *  - **Excluir é irreversível** e exige digitar o e-mail. É a única ação sem volta do
 *    painel.
 *
 * Toda ação vira linha no histórico, inclusive a exclusão — cuja linha precisa
 * sobreviver ao sumiço do alvo.
 */
class PainelAdminModeracaoTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private User $titular;

    private User $dependente;

    protected function setUp(): void
    {
        parent::setUp();

        config(['admin.enabled' => true]);

        $this->admin = Admin::factory()->comDoisFatores()->create(['name' => 'Chefe']);

        $this->titular = User::factory()->create([
            'name' => 'Titular', 'email' => 'titular@exemplo.com', 'is_admin' => true,
        ]);
        $this->dependente = User::factory()->create([
            'name' => 'Dependente', 'email' => 'dep@exemplo.com',
            'account_owner_id' => $this->titular->id, 'is_admin' => false,
        ]);
    }

    private function comoAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin')->withSession(['admin_2fa_ok' => true]);
    }

    // ── Banir ────────────────────────────────────────────────────────────────

    public function test_banir_o_titular_bloqueia_ele_e_os_dependentes(): void
    {
        $this->comoAdmin()
            ->post(route('painel.banir', $this->titular->id), ['motivo' => 'Uso indevido reportado'])
            ->assertRedirect();

        $this->assertNotNull($this->titular->fresh()->banned_at);
        $this->assertTrue($this->titular->fresh()->estaBanido());

        // O dependente não tem `banned_at` próprio, mas está alcançado.
        $this->assertNull($this->dependente->fresh()->banned_at);
        $this->assertTrue($this->dependente->fresh()->estaBanido());
    }

    public function test_banir_um_dependente_nao_atinge_o_titular(): void
    {
        $this->comoAdmin()
            ->post(route('painel.banir', $this->dependente->id), ['motivo' => 'Motivo qualquer'])
            ->assertRedirect();

        $this->assertTrue($this->dependente->fresh()->estaBanido());
        $this->assertFalse($this->titular->fresh()->estaBanido());
    }

    public function test_banir_exige_motivo(): void
    {
        $this->comoAdmin()
            ->post(route('painel.banir', $this->titular->id), ['motivo' => ''])
            ->assertSessionHasErrors('motivo');

        $this->assertNull($this->titular->fresh()->banned_at);
    }

    /** O banimento vira linha no histórico, com o motivo e quem fez. */
    public function test_banir_fica_registrado_com_motivo_e_autor(): void
    {
        $this->comoAdmin()->post(route('painel.banir', $this->titular->id), ['motivo' => 'Fraude confirmada']);

        $this->assertDatabaseHas('admin_audit_logs', [
            'acao' => AdminAuditLog::BANIU,
            'admin_id' => $this->admin->id,
            'target_user_id' => $this->titular->id,
            'motivo' => 'Fraude confirmada',
        ]);
    }

    // ── O banimento tem efeito de verdade no app ─────────────────────────────

    /**
     * O ponto que separa "marcado como banido" de "sem acesso": o app precisa recusar.
     */
    public function test_banido_nao_navega_no_app(): void
    {
        $this->actingAs($this->titular, 'web')->get('/')->assertOk();

        $this->comoAdmin()->post(route('painel.banir', $this->titular->id), ['motivo' => 'Motivo qualquer']);

        $this->actingAs($this->titular->fresh(), 'web')->get('/')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }

    public function test_dependente_de_titular_banido_tambem_perde_o_app(): void
    {
        $this->comoAdmin()->post(route('painel.banir', $this->titular->id), ['motivo' => 'Motivo qualquer']);

        $this->actingAs($this->dependente->fresh(), 'web')->get('/')->assertRedirect(route('login'));
    }

    /** Banir derruba quem já estava logado: as linhas de `sessions` vão junto. */
    public function test_banir_apaga_as_sessoes_abertas_da_familia(): void
    {
        // `BrowserSessions::purgeForUser` só age com driver `database` — a suíte roda
        // com `array`, então sem isto o teste passaria sem exercitar nada.
        config(['session.driver' => 'database']);

        foreach ([$this->titular->id, $this->dependente->id] as $id) {
            DB::table('sessions')->insert([
                'id' => 'sessao-'.$id,
                'user_id' => $id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'teste',
                'payload' => '',
                'last_activity' => time(),
            ]);
        }

        $this->comoAdmin()->post(route('painel.banir', $this->titular->id), ['motivo' => 'Motivo qualquer']);

        // A asserção é pelo ID da sessão, não por `user_id`: `sessions.user_id` é
        // agnóstico de guard, e o `actingAs(..., 'admin')` do teste muda o guard PADRÃO
        // do processo — então o Laravel grava a sessão do próprio admin com o id dele,
        // que colide com o id do titular. (Em produção isso não acontece: numa
        // requisição do painel o guard padrão continua sendo o `web`, sem ninguém
        // autenticado, e a linha nasce com `user_id` nulo.)
        $this->assertSame(0, DB::table('sessions')->whereIn('id', [
            'sessao-'.$this->titular->id, 'sessao-'.$this->dependente->id,
        ])->count(), 'As sessões abertas da família precisam cair junto com o banimento.');
    }

    // ── Desbanir ─────────────────────────────────────────────────────────────

    public function test_desbanir_devolve_o_acesso(): void
    {
        $this->comoAdmin()->post(route('painel.banir', $this->titular->id), ['motivo' => 'Engano meu']);
        $this->comoAdmin()->post(route('painel.desbanir', $this->titular->id))->assertRedirect();

        $titular = $this->titular->fresh();
        $this->assertNull($titular->banned_at);
        $this->assertNull($titular->banned_reason);
        $this->assertFalse($titular->estaBanido());

        $this->actingAs($titular, 'web')->get('/')->assertOk();
        $this->assertDatabaseHas('admin_audit_logs', ['acao' => AdminAuditLog::DESBANIU]);
    }

    /** Banir não apaga dado nenhum — é o que separa banimento de exclusão. */
    public function test_banir_preserva_os_dados_da_familia(): void
    {
        Account::factory()->for($this->titular)->create(['type' => 'checking', 'initial_balance' => 100]);

        $this->comoAdmin()->post(route('painel.banir', $this->titular->id), ['motivo' => 'Motivo qualquer']);

        $this->assertDatabaseHas('users', ['id' => $this->titular->id]);
        $this->assertDatabaseHas('users', ['id' => $this->dependente->id]);
        $this->assertSame(1, Account::where('user_id', $this->titular->id)->count());
    }

    // ── Excluir ──────────────────────────────────────────────────────────────

    public function test_excluir_exige_o_email_digitado_corretamente(): void
    {
        $this->comoAdmin()
            ->delete(route('painel.excluir', $this->titular->id), ['confirmacao' => 'outro@email.com'])
            ->assertSessionHasErrors('confirmacao');

        $this->assertDatabaseHas('users', ['id' => $this->titular->id]);
    }

    public function test_excluir_apaga_a_familia_inteira(): void
    {
        $this->comoAdmin()
            ->delete(route('painel.excluir', $this->titular->id), ['confirmacao' => 'titular@exemplo.com'])
            ->assertRedirect(route('painel.pessoas'));

        $this->assertDatabaseMissing('users', ['id' => $this->titular->id]);
        $this->assertDatabaseMissing('users', ['id' => $this->dependente->id]);
    }

    /**
     * A linha do histórico precisa sobreviver ao alvo — senão apagar alguém apagaria
     * junto a prova de que foi apagado.
     */
    public function test_o_registro_da_exclusao_sobrevive_a_pessoa_excluida(): void
    {
        $this->comoAdmin()
            ->delete(route('painel.excluir', $this->titular->id), ['confirmacao' => 'titular@exemplo.com']);

        $log = AdminAuditLog::where('acao', AdminAuditLog::EXCLUIU)->firstOrFail();

        $this->assertSame($this->titular->id, $log->target_user_id);
        $this->assertStringContainsString('titular@exemplo.com', $log->alvo_descricao);
        $this->assertStringContainsString('Titular', $log->alvo_descricao);
    }

    // ── Sem sessão de painel, nada disso existe ──────────────────────────────

    public function test_usuario_comum_nao_bane_ninguem(): void
    {
        $outro = User::factory()->create();

        $this->actingAs($outro, 'web')
            ->post(route('painel.banir', $this->titular->id), ['motivo' => 'Deixa eu banir'])
            ->assertRedirect(route('painel.login'));

        $this->assertNull($this->titular->fresh()->banned_at);
    }

    public function test_admin_sem_segundo_fator_provado_nao_bane(): void
    {
        $this->actingAs($this->admin, 'admin')   // sem `admin_2fa_ok`
            ->post(route('painel.banir', $this->titular->id), ['motivo' => 'Motivo qualquer'])
            ->assertRedirect(route('painel.2fa.desafio'));

        $this->assertNull($this->titular->fresh()->banned_at);
    }

    /** O IP de quem agiu fica cifrado em repouso (é dado pessoal, e o log é eterno). */
    public function test_o_ip_do_registro_fica_cifrado_no_banco(): void
    {
        $this->comoAdmin()->post(route('painel.banir', $this->titular->id), ['motivo' => 'Motivo qualquer']);

        $cru = DB::table('admin_audit_logs')->where('acao', AdminAuditLog::BANIU)->value('ip');
        $lido = AdminAuditLog::where('acao', AdminAuditLog::BANIU)->value('ip');

        $this->assertNotSame($lido, $cru, 'O IP está em texto puro no banco.');
        $this->assertSame('127.0.0.1', $lido);
    }

    /** Sessões do banido continuam barradas mesmo com "lembrar de mim". */
    public function test_o_banimento_resiste_ao_lembrar_de_mim(): void
    {
        $this->comoAdmin()->post(route('painel.banir', $this->titular->id), ['motivo' => 'Motivo qualquer']);

        // `actingAs` simula exatamente o que o cookie de remember-me faria: devolver a
        // sessão autenticada sem passar pelo formulário de login. O middleware barra
        // mesmo assim — é essa a diferença entre apagar sessões e negar acesso.
        $this->actingAs($this->titular->fresh(), 'web')->get('/')->assertRedirect(route('login'));
        $this->assertGuest('web');
    }
}
