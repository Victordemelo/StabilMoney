<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foto trocada aparece na hora (item 18 da rodada de 22/09/2026).
 *
 * O defeito: `User::avatarUrl()` devolvia sempre a MESMA URL (`/avatar/{id}`) e o
 * `AvatarController` mandava o navegador guardá-la por 1 hora. Quem trocava a foto
 * continuava vendo a antiga no perfil, na sidebar, no popover e nos cards de dependentes —
 * e concluía que a troca não tinha funcionado.
 *
 * A correção versiona a URL (`?v=`, um trecho do hash do caminho do arquivo, que muda a
 * cada upload porque o `storeAvatar` sorteia um nome novo). A URL com a versão atual pode
 * ficar no cache; a sem versão (ou com a de uma foto velha) responde a foto atual sem
 * deixar o navegador guardá-la. O controle de acesso por família não muda.
 */
class FotoTrocadaApareceNaHoraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(User::AVATAR_DISK);
    }

    /** Conteúdo distinto por foto: é o que permite provar QUAL foto a URL devolveu. */
    private function foto(string $conteudo): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('foto.jpg', $conteudo);
    }

    private function trocarFoto(User $user, string $conteudo): User
    {
        $user->storeAvatar($this->foto($conteudo));
        $user->save();

        return $user->fresh();
    }

    public function test_a_url_da_foto_muda_quando_a_foto_muda(): void
    {
        $user = $this->trocarFoto(User::factory()->create(), 'foto antiga');
        $urlAntiga = $user->avatarUrl();

        $user = $this->trocarFoto($user, 'foto nova');

        $this->assertNotSame(
            $urlAntiga,
            $user->avatarUrl(),
            'A URL não mudou: o navegador segue servindo a foto antiga do cache por até 1 hora.',
        );
    }

    public function test_a_url_nova_traz_a_foto_nova_e_pode_ir_para_o_cache(): void
    {
        $user = $this->trocarFoto(User::factory()->create(), 'foto antiga');
        $user = $this->trocarFoto($user, 'foto nova');

        $resposta = $this->actingAs($user)->get($user->avatarUrl());

        $resposta->assertOk()->assertHeader('Cache-Control', 'max-age=3600, private');
        $this->assertSame('foto nova', $resposta->streamedContent());
    }

    /**
     * O fluxo real: trocar a foto em "Meu perfil" e voltar à tela. A página tem de apontar
     * para a URL nova — é ela que o navegador ainda não tem no cache.
     */
    public function test_depois_de_trocar_a_foto_o_perfil_aponta_para_a_url_nova(): void
    {
        $user = $this->trocarFoto(User::factory()->create(), 'foto antiga');
        $urlAntiga = $user->avatarUrl();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $this->foto('foto nova'),
        ])->assertSessionHasNoErrors();

        $urlNova = $user->fresh()->avatarUrl();
        $this->assertNotSame($urlAntiga, $urlNova);

        $this->actingAs($user->fresh())->get(route('profile.edit'))
            ->assertOk()
            ->assertSee($urlNova)
            ->assertDontSee($urlAntiga);

        $this->assertSame('foto nova', $this->get($urlNova)->streamedContent());
    }

    /** A URL sem versão continua valendo — e sempre com a foto de agora. */
    public function test_url_sem_versao_responde_a_foto_atual_sem_cache(): void
    {
        $user = $this->trocarFoto(User::factory()->create(), 'foto antiga');
        $user = $this->trocarFoto($user, 'foto nova');

        $resposta = $this->actingAs($user)->get(route('avatar.show', $user));

        $resposta->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
        $this->assertSame('foto nova', $resposta->streamedContent());
    }

    /**
     * Uma aba aberta antes da troca ainda pede a URL com a versão velha: ela recebe a foto
     * de agora, mas o navegador não a guarda sob essa chave.
     */
    public function test_versao_de_uma_foto_ja_trocada_nao_fica_no_cache(): void
    {
        $user = $this->trocarFoto(User::factory()->create(), 'foto antiga');
        $urlAntiga = $user->avatarUrl();
        $user = $this->trocarFoto($user, 'foto nova');

        $resposta = $this->actingAs($user)->get($urlAntiga);

        $resposta->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
        $this->assertSame('foto nova', $resposta->streamedContent());
    }

    /** `?v[]=x` chega como array: não pode virar erro 500 numa URL que qualquer um digita. */
    public function test_versao_malformada_nao_derruba_a_rota(): void
    {
        $user = $this->trocarFoto(User::factory()->create(), 'foto');

        $this->actingAs($user)
            ->get(route('avatar.show', $user).'?v[]=x')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-cache, private');
    }

    /** A versão certa não abre a porta: foto de outra família continua 403. */
    public function test_a_versao_nao_muda_quem_pode_ver_a_foto(): void
    {
        $dono = $this->trocarFoto(User::factory()->create(), 'foto do dono');
        $estranho = User::factory()->create();

        $this->actingAs($estranho)->get($dono->avatarUrl())->assertForbidden();
    }
}
