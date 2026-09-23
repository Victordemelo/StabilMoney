<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Mail\AlertaDoPainel;
use App\Mail\ContaDaFamiliaExcluida;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Support\AdminAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Excluir uma conta é tudo ou nada (item 12 da rodada de 22/09/2026).
 *
 * O defeito: `ProfileController::destroy` e `Admin\ModeracaoController::excluir` rodavam a
 * exclusão fora de qualquer transação. O hook `deleting` do User faz várias escritas — cada
 * dependente, as linhas de `sessions`, a própria linha — e ainda apagava a FOTO do disco
 * logo de saída. Uma falha no meio (erro de banco no 2º dependente) deixava a família pela
 * metade: um dependente já apagado, outro de pé, fotos sumidas com a linha viva. E o e-mail
 * "sua conta foi excluída" saía ANTES do delete — chegava mesmo quando a conta continuava lá.
 * No painel, a linha de auditoria era gravada DEPOIS e fora da transação: uma falha nela
 * deixava a exclusão sem a única prova de que ela aconteceu.
 *
 * O comportamento certo: toda a parte de banco numa `DB::transaction`; o que não volta
 * atrás (apagar arquivo, mandar e-mail) só DEPOIS do commit. No painel, delete e auditoria
 * juntos — os dois ou nenhum —, e o e-mail do AdminAudit só depois do commit.
 *
 * ⚠️ Sobre `afterCommit` com `RefreshDatabase`: a transação que embrulha cada teste NÃO
 * conta como transação para os callbacks (ver Foundation\Testing\DatabaseTransactionsManager)
 * — eles rodam no commit da transação do controller, como em produção. Os testes de
 * "caminho feliz" abaixo existem para provar isso: sem eles, uma foto que nunca fosse
 * apagada passaria despercebida.
 */
class ExclusaoDeContaNaoFicaPelaMetadeTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-de-teste-1234';

    private User $titular;

    /** @var list<User> */
    private array $dependentes;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(User::AVATAR_DISK);

        // `BrowserSessions::purgeForUser` só age com o driver `database` — a suíte roda com
        // `array`, e sem isto as sessões nem entrariam na conta do teste.
        config(['session.driver' => 'database']);

        $this->titular = $this->comFotoESessao(User::factory()->create([
            'name' => 'Titular',
            'email' => 'titular@familia.test',
            'password' => Hash::make(self::SENHA),
            'is_admin' => true,
        ]));

        $this->dependentes = [
            $this->comFotoESessao(User::factory()->create([
                'name' => 'Dependente Um', 'account_owner_id' => $this->titular->id, 'is_admin' => false,
            ])),
            $this->comFotoESessao(User::factory()->create([
                'name' => 'Dependente Dois', 'account_owner_id' => $this->titular->id, 'is_admin' => false,
            ])),
        ];
    }

    private function comFotoESessao(User $user): User
    {
        $user->storeAvatar(UploadedFile::fake()->create('foto.jpg', 12, 'image/jpeg'));
        $user->save();

        DB::table('sessions')->insert([
            'id' => 'sessao-'.$user->id,
            'user_id' => $user->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => time(),
        ]);

        return $user->fresh();
    }

    /** O DELETE do segundo dependente falha, como um erro de banco no meio do caminho. */
    private function falharNoSegundoDependente(): void
    {
        $apagados = 0;

        User::deleting(function (User $alvo) use (&$apagados) {
            if (! $alvo->isTitular() && ++$apagados === 2) {
                throw new \RuntimeException('Falha simulada ao apagar o segundo dependente.');
            }
        });
    }

    /** @return list<User> */
    private function familia(): array
    {
        return [$this->titular, ...$this->dependentes];
    }

    private function assertNadaFoiApagado(): void
    {
        foreach ($this->familia() as $pessoa) {
            $this->assertModelExists($pessoa);
            Storage::disk(User::AVATAR_DISK)->assertExists($pessoa->avatar_path);
            $this->assertDatabaseHas('sessions', ['id' => 'sessao-'.$pessoa->id]);
        }
    }

    private function assertFamiliaApagada(): void
    {
        foreach ($this->familia() as $pessoa) {
            $this->assertModelMissing($pessoa);
            // A foto sai no `afterCommit`: se ele não rodasse, o arquivo ficaria aqui.
            Storage::disk(User::AVATAR_DISK)->assertMissing($pessoa->avatar_path);
            $this->assertDatabaseMissing('sessions', ['id' => 'sessao-'.$pessoa->id]);
        }
    }

    private function excluirPeloPerfil(): TestResponse
    {
        return $this->actingAs($this->titular)
            ->from(route('settings', 'conta'))
            ->delete(route('profile.destroy'), ['password' => self::SENHA, 'confirmo_dependentes' => '1']);
    }

    private function comoAdmin(): static
    {
        config(['admin.enabled' => true]);

        $admin = Admin::factory()->comDoisFatores()->create(['name' => 'Chefe']);

        return $this->actingAs($admin, 'admin')->withSession(['admin_2fa_ok' => true]);
    }

    // ══════════════════════════════════════════════════ pelo perfil (o próprio titular)

    public function test_falha_no_meio_da_exclusao_pelo_perfil_nao_apaga_nada(): void
    {
        Mail::fake();
        Exceptions::fake();
        $this->falharNoSegundoDependente();

        $this->excluirPeloPerfil()
            ->assertRedirect(route('settings', 'conta'))
            ->assertSessionHasErrorsIn('userDeletion', 'exclusao');

        $this->assertNadaFoiApagado();

        // Nenhum "sua conta foi excluída" para quem continua com a conta de pé.
        Mail::assertNothingSent();

        // Quem pediu continua dentro: o logout só vem depois do commit.
        $this->assertAuthenticatedAs($this->titular);

        // E a falha não some: vai para o log de erros, como qualquer 500 iria.
        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'Falha simulada'));
    }

    public function test_a_tela_diz_que_nada_foi_apagado(): void
    {
        Mail::fake();
        $this->falharNoSegundoDependente();

        $this->followingRedirects()
            ->actingAs($this->titular)
            ->from(route('settings', 'conta'))
            ->delete(route('profile.destroy'), ['password' => self::SENHA, 'confirmo_dependentes' => '1'])
            ->assertOk()
            ->assertSee('Não conseguimos excluir a sua conta agora, e nada foi apagado.');
    }

    public function test_caminho_feliz_pelo_perfil_apaga_tudo_inclusive_as_fotos(): void
    {
        Mail::fake();

        $this->excluirPeloPerfil()->assertSessionHasNoErrors()->assertRedirect('/');

        $this->assertFamiliaApagada();
        $this->assertGuest();

        Mail::assertSent(AlertaDeSeguranca::class, fn (AlertaDeSeguranca $mail) => $mail->hasTo('titular@familia.test'));
        Mail::assertSent(ContaDaFamiliaExcluida::class, 2);
    }

    /**
     * Os avisos saem DEPOIS da exclusão, e não antes: no instante de cada envio, a conta
     * já não existe. Transporte `array` de verdade — o `Mail::fake()` não dispara o evento
     * de envio, e é nele que se olha o banco.
     */
    public function test_os_avisos_saem_depois_que_a_exclusao_ja_aconteceu(): void
    {
        $contaExistiaNoEnvio = [];

        Event::listen(MessageSending::class, function () use (&$contaExistiaNoEnvio) {
            $contaExistiaNoEnvio[] = User::whereKey($this->titular->id)->exists();
        });

        $this->excluirPeloPerfil()->assertRedirect('/');

        // Titular + 2 dependentes, os três com a família já apagada.
        $this->assertSame([false, false, false], $contaExistiaNoEnvio);
    }

    /**
     * No próprio model: quem embrulha o `delete()` numa transação que é desfeita recebe a
     * família de volta INTEIRA — inclusive a foto, que é o que o banco não devolve sozinho.
     */
    public function test_rollback_em_volta_do_delete_devolve_tudo_inclusive_a_foto(): void
    {
        try {
            DB::transaction(function () {
                $this->titular->delete();

                throw new \RuntimeException('Desfeito depois do delete.');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertNadaFoiApagado();
    }

    // ══════════════════════════════════════════════════ pelo painel administrativo

    public function test_falha_no_meio_da_exclusao_pelo_painel_nao_apaga_nada_nem_registra(): void
    {
        Mail::fake();
        Exceptions::fake();
        $this->falharNoSegundoDependente();

        // Desde 23/09/2026 a falha não sobe mais como HTTP 500: vai para o log e o admin recebe
        // o recado "nada foi apagado" (PainelAdminExclusaoQueFalhaTest cobre o texto). O que
        // este teste guarda é o lado do banco E do disco: nada pela metade.
        $this->comoAdmin()
            ->delete(route('painel.excluir', $this->titular->id), ['confirmacao' => 'titular@familia.test'])
            ->assertSessionHasErrors('excluir');

        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'Falha simulada'));
        $this->assertNadaFoiApagado();
        $this->assertDatabaseMissing('admin_audit_logs', ['acao' => AdminAuditLog::EXCLUIU]);
        Mail::assertNothingSent();
    }

    /**
     * Os dois ou nenhum, no outro sentido: se o histórico não pode ser gravado, a exclusão
     * é desfeita. Antes a família sumia e sobrava um 500 — sem prova nenhuma do que houve.
     */
    public function test_se_o_registro_da_exclusao_falha_a_exclusao_e_desfeita(): void
    {
        Mail::fake();

        AdminAuditLog::creating(function (AdminAuditLog $log) {
            if ($log->acao === AdminAuditLog::EXCLUIU) {
                throw new \RuntimeException('Falha simulada ao gravar o histórico.');
            }
        });

        Exceptions::fake();

        $this->comoAdmin()
            ->delete(route('painel.excluir', $this->titular->id), ['confirmacao' => 'titular@familia.test'])
            ->assertSessionHasErrors('excluir');

        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'histórico'));
        $this->assertNadaFoiApagado();
        Mail::assertNothingSent();
    }

    public function test_caminho_feliz_pelo_painel_apaga_registra_e_avisa(): void
    {
        Mail::fake();

        $this->comoAdmin()
            ->delete(route('painel.excluir', $this->titular->id), ['confirmacao' => 'titular@familia.test'])
            ->assertRedirect(route('painel.pessoas'));

        $this->assertFamiliaApagada();

        $log = AdminAuditLog::where('acao', AdminAuditLog::EXCLUIU)->firstOrFail();
        $this->assertSame($this->titular->id, $log->target_user_id);
        $this->assertStringContainsString('titular@familia.test', $log->alvo_descricao);

        Mail::assertSent(AlertaDoPainel::class, fn (AlertaDoPainel $mail) => $mail->acao === AdminAuditLog::EXCLUIU);
    }

    // ══════════════════════════════════════════════════ o e-mail do AdminAudit

    public function test_o_alerta_do_painel_so_sai_depois_do_commit(): void
    {
        Mail::fake();
        $admin = Admin::factory()->comDoisFatores()->create();

        try {
            DB::transaction(function () use ($admin) {
                AdminAudit::registrar(
                    AdminAuditLog::EXCLUIU,
                    $admin,
                    Request::create('/'),
                    alvoDescricao: 'Fulano <fulano@exemplo.test>',
                );

                throw new \RuntimeException('Desfeito depois de registrar.');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        // O registro foi desfeito — e o e-mail, que anunciaria uma exclusão que não houve,
        // não chegou a sair.
        $this->assertDatabaseMissing('admin_audit_logs', ['acao' => AdminAuditLog::EXCLUIU]);
        Mail::assertNothingSent();
    }

    /** Fora de transação nada muda: banir continua avisando na hora. */
    public function test_banir_continua_avisando_na_hora(): void
    {
        Mail::fake();

        $this->comoAdmin()
            ->post(route('painel.banir', $this->titular->id), ['motivo' => 'Motivo qualquer'])
            ->assertRedirect();

        Mail::assertSent(AlertaDoPainel::class, fn (AlertaDoPainel $mail) => $mail->acao === AdminAuditLog::BANIU);
    }
}
