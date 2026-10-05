<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O e-mail e a senha do usuário de dev saíram do .env (out/2026 — pedido do Victor): as chaves
 * SEED_USER_* passaram a ser opcionais e temporárias. Sem elas, o seeder não cria usuário
 * nenhum — antes ele caía num "victor@stabilmoney.test" com a senha "password".
 */
class DatabaseSeederSemCredenciaisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // O seeder só age em ambiente local (trava contra rodar em produção).
        $this->app['env'] = 'local';
    }

    public function test_sem_email_e_senha_nenhum_usuario_e_criado(): void
    {
        config(['seed.usuario' => ['nome' => 'Victor', 'email' => null, 'senha' => null]]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_so_com_o_email_tambem_nao_cria_com_senha_padrao(): void
    {
        config(['seed.usuario' => ['nome' => 'Victor', 'email' => 'dev@stabilmoney.test', 'senha' => '']]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_com_as_duas_chaves_cria_o_usuario_com_a_senha_delas(): void
    {
        config(['seed.usuario' => ['nome' => 'Victor', 'email' => 'dev@stabilmoney.test', 'senha' => 'uma-senha-de-dev-qualquer']]);

        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'dev@stabilmoney.test')->sole();
        $this->assertTrue(Hash::check('uma-senha-de-dev-qualquer', $user->password));
        $this->assertFalse(Hash::check('password', $user->password));
    }

    public function test_fora_do_ambiente_local_nao_faz_nada(): void
    {
        $this->app['env'] = 'production';
        config(['seed.usuario' => ['nome' => 'Victor', 'email' => 'dev@stabilmoney.test', 'senha' => 'uma-senha-de-dev-qualquer']]);

        // Direto, sem o `db:seed`: em produção o comando pede confirmação interativa.
        $this->app->make(DatabaseSeeder::class)->run();

        $this->assertSame(0, User::count());
    }
}
