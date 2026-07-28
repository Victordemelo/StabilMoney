<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BrowserSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A Política de Privacidade promete que excluir a conta remove os dados associados.
 * O `cascadeOnDelete` das FKs cobre as tabelas, mas NÃO cobre o arquivo da foto no
 * disco nem as linhas de `sessions` (que guardam IP e user-agent) — é isso que o
 * hook `deleting` do User faz, e é isso que estes testes travam.
 */
class AccountDeletionPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_account_removes_the_avatar_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar_path' => UploadedFile::fake()->create('eu.jpg', 12)->store('avatars', 'public'),
        ]);

        Storage::disk('public')->assertExists($user->avatar_path);

        $path = $user->avatar_path;
        $user->delete();

        Storage::disk('public')->assertMissing($path);
    }

    /**
     * O caso que o banco não resolve: o cascade da FK `account_owner_id` apaga a LINHA
     * do dependente sem disparar eventos do Eloquent, então a foto dele ficaria órfã
     * no disco — servida publicamente pelo symlink de storage/.
     */
    public function test_deleting_titular_removes_dependent_avatar_files_too(): void
    {
        Storage::fake('public');

        $titular = User::factory()->create([
            'avatar_path' => UploadedFile::fake()->create('titular.jpg', 12)->store('avatars', 'public'),
        ]);
        $dependente = User::factory()->create([
            'account_owner_id' => $titular->id,
            'is_admin' => false,
            'avatar_path' => UploadedFile::fake()->create('dependente.jpg', 12)->store('avatars', 'public'),
        ]);

        $paths = [$titular->avatar_path, $dependente->avatar_path];

        $titular->delete();

        foreach ($paths as $path) {
            Storage::disk('public')->assertMissing($path);
        }

        $this->assertDatabaseMissing('users', ['id' => $dependente->id]);
    }

    /** Conta sem foto não pode explodir na exclusão. */
    public function test_deleting_account_without_avatar_works(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['avatar_path' => null]);

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /**
     * As linhas de `sessions` não têm FK com cascade — sem a limpeza explícita, IP e
     * user-agent do usuário ficariam na tabela depois da conta deixar de existir.
     */
    public function test_deleting_account_removes_session_rows(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        $outro = User::factory()->create();

        foreach ([$user, $outro] as $i => $dono) {
            DB::table('sessions')->insert([
                'id' => 'sessao-'.$i,
                'user_id' => $dono->id,
                'ip_address' => '203.0.113.'.$i,
                'user_agent' => 'PHPUnit',
                'payload' => '',
                'last_activity' => 1750000000,
            ]);
        }

        $user->delete();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        // A sessão de outra pessoa não pode ser afetada.
        $this->assertDatabaseHas('sessions', ['user_id' => $outro->id]);
    }

    /** O purge preserva a sessão indicada (usado por "encerrar as outras sessões"). */
    public function test_purge_can_preserve_the_current_session(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();

        foreach (['atual', 'antiga'] as $id) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $user->id,
                'ip_address' => '203.0.113.9',
                'user_agent' => 'PHPUnit',
                'payload' => '',
                'last_activity' => 1750000000,
            ]);
        }

        $removidas = BrowserSessions::purgeForUser($user->id, exceptSessionId: 'atual');

        $this->assertSame(1, $removidas);
        $this->assertDatabaseHas('sessions', ['id' => 'atual']);
        $this->assertDatabaseMissing('sessions', ['id' => 'antiga']);
    }

    /** Com driver de sessão não-database (ex.: array nos testes), o purge é inócuo. */
    public function test_purge_is_a_no_op_when_session_driver_is_not_database(): void
    {
        config(['session.driver' => 'array']);

        $this->assertSame(0, BrowserSessions::purgeForUser(1));
    }

    /**
     * O fluxo real: excluir a conta pela tela de perfil também limpa o disco.
     */
    public function test_profile_deletion_endpoint_purges_the_avatar(): void
    {
        Storage::fake('public');

        // Sem bcrypt() manual: o cast `hashed` do model aplica o argon2id configurado.
        $user = User::factory()->create([
            'password' => 'senha-secreta',
            'avatar_path' => UploadedFile::fake()->create('eu.jpg', 12)->store('avatars', 'public'),
        ]);
        $path = $user->avatar_path;

        $this->actingAs($user)
            ->delete(route('profile.destroy'), ['password' => 'senha-secreta'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        Storage::disk('public')->assertMissing($path);
    }
}
