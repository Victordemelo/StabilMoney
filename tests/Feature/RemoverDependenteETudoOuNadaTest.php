<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Remover um dependente é tudo ou nada (item 6 da segunda rodada de 22/09/2026).
 *
 * O defeito: `DependentController::destroy` chamava `$dependent->delete()` fora de transação.
 * O delete são várias escritas — o hook `deleting` do User apaga as sessões dele e os tokens de
 * "esqueci a senha", e só então sai a linha. Uma falha no meio deixava o dependente de pé com
 * as sessões e os tokens já apagados: nem removido, nem inteiro. É a mesma regra que a exclusão
 * de conta já segue (ExclusaoDeContaNaoFicaPelaMetadeTest): numa `DB::transaction`, e o que não
 * volta atrás (a foto no disco) só depois do commit.
 *
 * A falha é simulada com um listener `deleting` registrado DEPOIS do hook do model: ele roda
 * quando as sessões e os tokens já saíram, e antes do DELETE da linha — o meio do caminho.
 */
class RemoverDependenteETudoOuNadaTest extends TestCase
{
    use RefreshDatabase;

    private User $titular;

    private User $dependente;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(User::AVATAR_DISK);

        // `BrowserSessions::purgeForUser` só age com o driver `database` (a suíte usa `array`).
        config(['session.driver' => 'database']);

        $this->titular = User::factory()->create(['is_admin' => true]);
        $this->dependente = User::factory()->create([
            'name' => 'Bruno Dependente',
            'email' => 'bruno@familia.test',
            'account_owner_id' => $this->titular->id,
            'is_admin' => false,
        ]);

        $this->dependente->storeAvatar(UploadedFile::fake()->create('foto.jpg', 12, 'image/jpeg'));
        $this->dependente->save();

        DB::table('sessions')->insert([
            'id' => 'sessao-do-dependente',
            'user_id' => $this->dependente->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => time(),
        ]);

        Password::broker()->createToken($this->dependente);
    }

    private function remover()
    {
        return $this->actingAs($this->titular)->delete(route('dependentes.destroy', $this->dependente));
    }

    public function test_falha_no_meio_da_remocao_nao_apaga_nada(): void
    {
        Exceptions::fake();

        User::deleting(function (User $alvo) {
            if ($alvo->is($this->dependente)) {
                throw new \RuntimeException('Falha simulada no meio da remoção.');
            }
        });

        $this->remover()
            ->assertRedirect(route('dependentes'))
            ->assertSessionHas('erro', 'Não conseguimos remover Bruno Dependente agora, e nada foi apagado. Tente de novo em instantes.');

        // Nada saiu: a linha, as sessões, o token e a foto.
        $this->assertModelExists($this->dependente);
        $this->assertDatabaseHas('sessions', ['id' => 'sessao-do-dependente']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'bruno@familia.test']);
        Storage::disk(User::AVATAR_DISK)->assertExists($this->dependente->avatar_path);

        // E a falha não some: vai para o log de erros, como um 500 iria.
        Exceptions::assertReported(fn (\RuntimeException $e) => str_contains($e->getMessage(), 'Falha simulada'));
    }

    public function test_caminho_feliz_apaga_tudo_inclusive_a_foto(): void
    {
        $this->remover()
            ->assertRedirect(route('dependentes'))
            ->assertSessionHas('status', 'Dependente removido.');

        $this->assertModelMissing($this->dependente);
        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-do-dependente']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'bruno@familia.test']);
        // A foto sai no `afterCommit`: se ele não rodasse, o arquivo ficaria aqui.
        Storage::disk(User::AVATAR_DISK)->assertMissing($this->dependente->avatar_path);

        $this->assertModelExists($this->titular);
    }

    public function test_so_o_titular_da_familia_remove(): void
    {
        $outro = User::factory()->create(['is_admin' => true]);

        // Titular de OUTRA família: o dependente nem existe para ele (o 404 de um id que não existe).
        $this->actingAs($outro)->delete(route('dependentes.destroy', $this->dependente))->assertNotFound();

        $this->assertModelExists($this->dependente);
    }
}
