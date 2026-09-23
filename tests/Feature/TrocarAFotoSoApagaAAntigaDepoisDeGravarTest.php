<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SimulaSmtpQueRecusa;
use Tests\TestCase;

/**
 * Trocar a foto só apaga a antiga depois que a nova está gravada (item 7 da segunda rodada de
 * 22/09/2026).
 *
 * O defeito: `User::storeAvatar` apagava a foto antiga do disco logo de saída, ANTES do
 * `save()`. Se o save falhasse — ou se a transação em volta fosse desfeita —, a linha seguia
 * apontando para um arquivo que já não existia, e a pessoa ficava sem foto nenhuma (nem a
 * antiga, nem a nova).
 *
 * O comportamento certo: a antiga sai no hook `updated` do User, quando a linha JÁ aponta para
 * a nova, e depois do commit (`DB::afterCommit` — fora de transação, na hora). O nome sorteado a
 * cada upload continua o mesmo (é ele que versiona a URL da foto).
 *
 * Há também a armadilha do hook: com `saved` no lugar de `updated`, um segundo save SEM mudança
 * (o ProfileController faz um quando o link da troca de e-mail não sai) apagaria a foto ATUAL —
 * o `wasChanged` responde pelo save anterior, e o `getOriginal` já é a foto nova. O último
 * teste prende isso.
 */
class TrocarAFotoSoApagaAAntigaDepoisDeGravarTest extends TestCase
{
    use RefreshDatabase;
    use SimulaSmtpQueRecusa;

    private const SENHA = 'senha-de-teste-1234';

    private User $user;

    private string $fotoAntiga;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(User::AVATAR_DISK);

        $user = User::factory()->create(['email' => 'eu@exemplo.test', 'password' => Hash::make(self::SENHA)]);
        $user->storeAvatar(UploadedFile::fake()->createWithContent('antiga.jpg', 'foto antiga'));
        $user->save();

        // Relido do banco, como o app o recebe numa requisição: com TODAS as colunas no
        // "original". O model do factory só conhece as que foram gravadas, e aí qualquer
        // `forceFill` de coluna ausente conta como mudança — o save "sem mudança" do último
        // teste deixaria de existir, e o teste passaria sem provar nada.
        $this->user = $user->fresh();
        $this->fotoAntiga = $this->user->avatar_path;
    }

    private function disco()
    {
        return Storage::disk(User::AVATAR_DISK);
    }

    private function fotoNova(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('nova.jpg', 'foto nova');
    }

    public function test_se_o_save_falha_a_foto_antiga_continua_la(): void
    {
        User::updating(function () {
            throw new \RuntimeException('Falha simulada ao gravar o perfil.');
        });

        try {
            $this->user->storeAvatar($this->fotoNova());
            $this->user->save();
            $this->fail('A falha simulada deveria ter subido.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Falha simulada', $e->getMessage());
        }

        // A linha continua apontando para a antiga — e ela precisa existir.
        $this->assertSame($this->fotoAntiga, $this->user->fresh()->avatar_path);
        $this->disco()->assertExists($this->fotoAntiga);
    }

    /** O caminho real: a mesma falha pela tela de perfil. */
    public function test_pela_tela_de_perfil_um_save_que_falha_nao_leva_a_foto(): void
    {
        User::updating(function () {
            throw new \RuntimeException('Falha simulada ao gravar o perfil.');
        });

        $this->actingAs($this->user)->patch(route('profile.update'), [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'avatar' => $this->fotoNova(),
        ])->assertServerError();

        $this->assertSame($this->fotoAntiga, $this->user->fresh()->avatar_path);
        $this->disco()->assertExists($this->fotoAntiga);
    }

    /** Gravada, mas desfeita pela transação de quem chamou: a antiga também fica. */
    public function test_se_a_transacao_em_volta_e_desfeita_a_foto_antiga_continua_la(): void
    {
        try {
            DB::transaction(function () {
                $this->user->storeAvatar($this->fotoNova());
                $this->user->save();

                throw new \RuntimeException('Desfeito depois do save.');
            });
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame($this->fotoAntiga, $this->user->fresh()->avatar_path);
        $this->disco()->assertExists($this->fotoAntiga);
    }

    public function test_trocada_de_verdade_a_antiga_sai_e_a_nova_fica(): void
    {
        $this->actingAs($this->user)->patch(route('profile.update'), [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'avatar' => $this->fotoNova(),
        ])->assertSessionHasNoErrors();

        $nova = $this->user->fresh()->avatar_path;

        $this->assertNotSame($this->fotoAntiga, $nova, 'O nome da foto nova tem de ser outro: é ele que versiona a URL.');
        $this->disco()->assertExists($nova);
        $this->disco()->assertMissing($this->fotoAntiga);
        $this->assertSame('foto nova', $this->disco()->get($nova));
    }

    /** O titular trocando a foto do dependente passa pelo mesmo caminho. */
    public function test_a_foto_do_dependente_trocada_pelo_titular_tambem(): void
    {
        $dependente = User::factory()->create(['account_owner_id' => $this->user->id, 'is_admin' => false]);
        $dependente->storeAvatar(UploadedFile::fake()->createWithContent('dep.jpg', 'foto antiga do dependente'));
        $dependente->save();
        $antiga = $dependente->avatar_path;

        $this->actingAs($this->user)->patch(route('dependentes.update', $dependente), [
            'name' => $dependente->name,
            'email' => $dependente->email,
            'avatar' => $this->fotoNova(),
        ])->assertSessionHasNoErrors();

        $this->disco()->assertMissing($antiga);
        $this->disco()->assertExists($dependente->fresh()->avatar_path);
    }

    /**
     * Um save sem mudança depois de trocar a foto não pode levar a foto ATUAL. Acontece de
     * verdade: foto nova + e-mail novo com o SMTP recusando o endereço — o ProfileController
     * grava a foto, o link não sai, e ele salva de novo só para limpar a pendência (sem mudança
     * nenhuma na linha).
     */
    public function test_um_save_sem_mudanca_depois_da_troca_nao_apaga_a_foto_atual(): void
    {
        $this->smtpQueRecusa();

        $this->actingAs($this->user)->patch(route('profile.update'), [
            'name' => $this->user->name,
            'email' => 'novo@exemplo.test',
            'current_password' => self::SENHA,
            'avatar' => $this->fotoNova(),
        ])->assertSessionHasErrors('email');

        $atual = $this->user->fresh()->avatar_path;

        $this->assertNotSame($this->fotoAntiga, $atual, 'A foto nova não foi gravada (o resto do perfil fica salvo).');
        $this->disco()->assertExists($atual);
        $this->assertSame('foto nova', $this->disco()->get($atual));
        $this->disco()->assertMissing($this->fotoAntiga);
    }
}
