<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dá para tirar a foto sem pôr outra no lugar (achado da rodada de 23/09/2026).
 *
 * O defeito: em "Meu perfil" e no modal de editar dependente, a foto só podia ser TROCADA.
 * Quem quisesse voltar às iniciais — foto errada, foto que não quer mais mostrar à família e
 * ao painel — não tinha como, a não ser subindo outra imagem por cima.
 *
 * O comportamento certo: um "Remover a foto" (só quando há foto) que, ao salvar, zera o
 * `avatar_path` e apaga o arquivo DEPOIS do commit, pelo mesmo hook `updated` da troca. Com
 * um arquivo novo junto, vale o arquivo.
 */
class RemoverFotoSemTrocarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(User::AVATAR_DISK);
    }

    private function comFoto(User $user, string $conteudo = 'foto antiga'): User
    {
        $user->storeAvatar(UploadedFile::fake()->createWithContent('foto.jpg', $conteudo));
        $user->save();

        return $user->fresh();
    }

    public function test_remover_a_propria_foto_volta_as_iniciais_e_apaga_o_arquivo(): void
    {
        $user = $this->comFoto(User::factory()->create(['name' => 'Ana Quintela']));
        $arquivo = $user->avatar_path;

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'remover_foto' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertNull($user->avatar_path);
        $this->assertNull($user->avatarUrl());
        Storage::disk(User::AVATAR_DISK)->assertMissing($arquivo);

        // A tela volta às iniciais, e o "Remover a foto" some junto com a foto.
        $pagina = $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('name="remover_foto"', false);
        $this->assertMatchesRegularExpression('/id="avatarPreview"[^>]*>\s*AQ\s*<\/span>/', $pagina->getContent());
    }

    /** Com arquivo novo junto, vale o arquivo: a pessoa escolheu uma foto. */
    public function test_com_foto_nova_junto_vale_a_foto_nova(): void
    {
        $user = $this->comFoto(User::factory()->create());
        $antiga = $user->avatar_path;

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'remover_foto' => '1',
            'avatar' => UploadedFile::fake()->createWithContent('nova.jpg', 'foto nova'),
        ])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        $this->assertNotSame($antiga, $user->avatar_path);
        $this->assertSame('foto nova', Storage::disk(User::AVATAR_DISK)->get($user->avatar_path));
        Storage::disk(User::AVATAR_DISK)->assertMissing($antiga);
    }

    /** Sem foto, o pedido não quebra nada, e o checkbox nem aparece. */
    public function test_sem_foto_nao_ha_o_que_remover(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('name="remover_foto"', false);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'remover_foto' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_com_foto_o_perfil_oferece_remover(): void
    {
        $user = $this->comFoto(User::factory()->create());

        $this->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('name="remover_foto"', false)
            ->assertSee('Remover a foto');
    }

    /** O arquivo só sai do disco depois do commit: um rollback deixa a foto inteira. */
    public function test_o_arquivo_so_sai_depois_do_commit(): void
    {
        $user = $this->comFoto(User::factory()->create());
        $arquivo = $user->avatar_path;

        DB::beginTransaction();
        $user->removeAvatar();
        $user->save();
        Storage::disk(User::AVATAR_DISK)->assertExists($arquivo);
        DB::rollBack();

        Storage::disk(User::AVATAR_DISK)->assertExists($arquivo);
        $this->assertSame($arquivo, $user->fresh()->avatar_path);
    }

    public function test_titular_remove_a_foto_do_dependente_sem_alarme(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        $titular = User::factory()->create(['is_admin' => true]);
        $dependente = $this->comFoto(User::factory()->create([
            'name' => 'Bruno Dependente',
            'account_owner_id' => $titular->id,
            'is_admin' => false,
        ]));
        $arquivo = $dependente->avatar_path;

        // O modal de editar oferece a opção para quem tem foto.
        $this->actingAs($titular)->get(route('dependentes'))
            ->assertOk()
            ->assertSee('name="remover_foto"', false);

        $this->actingAs($titular)->patch(route('dependentes.update', $dependente), [
            'name' => $dependente->name,
            'email' => $dependente->email,
            'remover_foto' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dependentes'));

        $this->assertNull($dependente->fresh()->avatar_path);
        Storage::disk(User::AVATAR_DISK)->assertMissing($arquivo);
        // Foto não decide quem recupera a conta: nenhum alerta.
        Mail::assertNothingSent();
    }

    public function test_dependente_sem_foto_nao_ganha_a_opcao_no_modal(): void
    {
        $titular = User::factory()->create(['is_admin' => true]);
        User::factory()->create(['account_owner_id' => $titular->id, 'is_admin' => false]);

        $this->actingAs($titular)->get(route('dependentes'))
            ->assertOk()
            ->assertDontSee('name="remover_foto"', false);
    }
}
