<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foto que o servidor não consegue ler vira as INICIAIS, não um `<img>` quebrado (out/2026).
 *
 * No dev, a pasta `storage/app/private/avatars` foi criada por um comando rodado como root
 * com 0700 (o padrão do Flysystem para pastas) e o Apache, como www-data, deixou de enxergar
 * as fotos: a rota `avatar.show` dava 404 e o shell inteiro (sidebar, popover, Família, Meu
 * perfil) mostrava um círculo vazio no lugar da foto — e no lugar das iniciais, que são o
 * fallback de todas essas telas. O mesmo acontece quando o banco volta de um backup sem os
 * arquivos. `User::avatarUrl()` agora só aponta para a foto que existe e pode ser lida.
 */
class FotoQueOServidorNaoLeCaiNasIniciaisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(User::AVATAR_DISK);
    }

    public function test_foto_sem_arquivo_no_disco_nao_vira_url(): void
    {
        $user = User::factory()->create(['avatar_path' => 'avatars/sumiu.png']);

        $this->assertNull($user->avatarUrl());
        $this->assertFalse($user->temFotoLegivel());
    }

    public function test_foto_que_existe_vira_url_versionada(): void
    {
        Storage::disk(User::AVATAR_DISK)->put('avatars/existe.png', 'png');
        $user = User::factory()->create(['avatar_path' => 'avatars/existe.png']);

        $this->assertSame(route('avatar.show', ['membro' => $user, 'v' => $user->versaoDaFoto()]), $user->avatarUrl());
    }

    public function test_o_shell_mostra_as_iniciais_quando_a_foto_nao_pode_ser_lida(): void
    {
        $user = User::factory()->create(['name' => 'Ana Quintela', 'avatar_path' => 'avatars/sumiu.png']);

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        // Nenhuma <img> apontando para a rota da foto (seria um 404 = círculo vazio)...
        $this->assertStringNotContainsString(url('/avatar/'.$user->id), $html);
        // ...e as iniciais no rodapé da sidebar.
        $this->assertMatchesRegularExpression('#<span class="avatar-initials">\s*AQ\s*</span>#', $html);
    }

    public function test_a_conferencia_acompanha_a_troca_de_foto_no_mesmo_model(): void
    {
        $user = User::factory()->create(['avatar_path' => 'avatars/sumiu.png']);
        $this->assertNull($user->avatarUrl());

        Storage::disk(User::AVATAR_DISK)->put('avatars/nova.png', 'png');
        $user->avatar_path = 'avatars/nova.png';

        $this->assertNotNull($user->avatarUrl(), 'O resultado guardado era de OUTRO caminho: tinha de conferir de novo.');
    }
}
