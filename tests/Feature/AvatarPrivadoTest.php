<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A foto de perfil sai por rota autenticada, não pelo symlink de storage/.
 *
 * Antes ela ficava no disco `public`: servida sem passar pelo Laravel e, portanto, sem
 * sessão — quem tivesse a URL via a foto para sempre, mesmo depois de sair do app ou de
 * ser removido da família. O nome aleatório de 40 caracteres impedia enumeração, mas URL
 * vaza (print, histórico, cache, backup) e rosto é dado pessoal.
 */
class AvatarPrivadoTest extends TestCase
{
    use RefreshDatabase;

    private function comFoto(User $user): User
    {
        $user->storeAvatar(UploadedFile::fake()->create('eu.jpg', 12));
        $user->save();

        return $user->fresh();
    }

    public function test_avatar_e_gravado_no_disco_privado_e_nao_no_publico(): void
    {
        Storage::fake(User::AVATAR_DISK);
        Storage::fake('public');

        $user = $this->comFoto(User::factory()->create());

        Storage::disk(User::AVATAR_DISK)->assertExists($user->avatar_path);
        Storage::disk('public')->assertMissing($user->avatar_path);
    }

    public function test_avatar_url_aponta_para_a_rota_autenticada(): void
    {
        Storage::fake(User::AVATAR_DISK);

        $user = $this->comFoto(User::factory()->create());

        // Com a versão da foto na query (ver FotoTrocadaApareceNaHoraTest).
        $this->assertSame(
            route('avatar.show', ['membro' => $user, 'v' => $user->versaoDaFoto()]),
            $user->avatarUrl(),
        );
        $this->assertStringNotContainsString(
            '/storage/',
            (string) $user->avatarUrl(),
            'A URL ainda aponta para o symlink público.',
        );
    }

    public function test_dono_ve_a_propria_foto(): void
    {
        Storage::fake(User::AVATAR_DISK);

        $user = $this->comFoto(User::factory()->create());

        // A URL que as telas usam (com a versão) pode ficar 1 hora no cache do navegador:
        // quando a foto muda, a versão muda e a URL também.
        $this->actingAs($user)
            ->get($user->avatarUrl())
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, private');
    }

    public function test_dependente_ve_a_foto_do_titular_e_vice_versa(): void
    {
        Storage::fake(User::AVATAR_DISK);

        $titular = $this->comFoto(User::factory()->create(['is_admin' => true]));

        $dependente = User::factory()->create([
            'account_owner_id' => $titular->id,
            'is_admin' => false,
        ]);
        $dependente = $this->comFoto($dependente);

        $this->actingAs($dependente)->get(route('avatar.show', $titular))->assertOk();
        $this->actingAs($titular)->get(route('avatar.show', $dependente))->assertOk();
    }

    /**
     * O estranho recebe o 404 de uma pessoa que não existe — o 403 de antes confirmava que o
     * id era de alguém, e varrer `/avatar/{id}` contava os usuários do app.
     */
    public function test_estranho_nao_ve_a_foto_de_outra_familia(): void
    {
        Storage::fake(User::AVATAR_DISK);

        $dono = $this->comFoto(User::factory()->create());
        $estranho = User::factory()->create();

        $this->actingAs($estranho)
            ->get(route('avatar.show', $dono))
            ->assertNotFound();
    }

    public function test_visitante_sem_sessao_nao_ve_foto_nenhuma(): void
    {
        Storage::fake(User::AVATAR_DISK);

        $dono = $this->comFoto(User::factory()->create());

        $this->get(route('avatar.show', $dono))->assertRedirect(route('login'));
    }

    /** Usuário sem foto: 404 limpo, não erro de servidor. */
    public function test_usuario_sem_foto_devolve_404(): void
    {
        $user = User::factory()->create(['avatar_path' => null]);

        $this->actingAs($user)->get(route('avatar.show', $user))->assertNotFound();
        $this->assertNull($user->avatarUrl());
    }

    /** Registro apontando para arquivo que sumiu do disco: 404, não 500. */
    public function test_arquivo_ausente_devolve_404(): void
    {
        Storage::fake(User::AVATAR_DISK);

        $user = User::factory()->create(['avatar_path' => 'avatars/nao-existe.jpg']);

        $this->actingAs($user)->get(route('avatar.show', $user))->assertNotFound();
    }

    /** Excluir a conta continua apagando o arquivo — agora no disco privado. */
    public function test_excluir_conta_apaga_o_arquivo_privado(): void
    {
        Storage::fake(User::AVATAR_DISK);

        $user = $this->comFoto(User::factory()->create());
        $caminho = $user->avatar_path;

        Storage::disk(User::AVATAR_DISK)->assertExists($caminho);

        $user->delete();

        Storage::disk(User::AVATAR_DISK)->assertMissing($caminho);
    }
}
