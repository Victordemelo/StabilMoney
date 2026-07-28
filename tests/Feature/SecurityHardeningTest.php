<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Regressões das correções da "onda 1" do pentest (jul/2026).
 * Cada teste trava um vetor concreto que estava aberto.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O nome da categoria é texto livre do usuário e vai para o JSON do dashboard,
     * que é embutido dentro de <script>. Sem JSON_HEX_TAG, um nome como `<!--<script>`
     * levava o parser HTML ao "double escaped state" e engolia o resto da página.
     */
    public function test_dashboard_json_escapes_html_tags_in_category_names(): void
    {
        $user = User::factory()->create();
        $categoria = Category::factory()->for($user)->expense()->create([
            'name' => '<!--<script></script><img src=x onerror=alert(1)>',
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => $categoria->id,
            'type' => 'expense',
            'amount' => 10,
            'date' => now(),
        ]);

        $json = app(DashboardService::class)->build($user->ownerId())['payloadJson'];

        // Nenhum caractere que abra tag pode sair cru dentro de <script>.
        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
        // Mas o dado continua íntegro depois do parse — não foi mutilado.
        $this->assertSame(
            $categoria->name,
            collect(json_decode($json, true)['cats'])->firstWhere('value', 10.0)['name'],
        );
    }

    /** A página do dashboard não pode servir o nome cru no HTML. */
    public function test_dashboard_page_does_not_emit_raw_script_tag_from_category_name(): void
    {
        $user = User::factory()->create();
        $categoria = Category::factory()->for($user)->expense()->create([
            'name' => '</script><img src=x onerror=alert(1)>',
        ]);
        Transaction::factory()->for($user)->create([
            'category_id' => $categoria->id,
            'type' => 'expense',
            'amount' => 10,
            'date' => now(),
        ]);

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
    }

    /**
     * "Esqueci a senha" não pode revelar se um e-mail tem conta: a resposta para
     * e-mail inexistente deve ser idêntica à de e-mail válido.
     */
    public function test_forgot_password_does_not_reveal_whether_email_exists(): void
    {
        User::factory()->create(['email' => 'existe@example.com']);

        $existente = $this->post(route('password.email'), ['email' => 'existe@example.com']);
        RateLimiter::clear('credencial'); // o limiter é por IP e ambos usam o mesmo
        $inexistente = $this->post(route('password.email'), ['email' => 'nao-existe@example.com']);

        $inexistente->assertSessionHasNoErrors();
        $this->assertSame(
            $existente->getSession()->get('status'),
            $inexistente->getSession()->get('status'),
            'A resposta deve ser idêntica, senão dá para enumerar usuários.',
        );
    }

    /**
     * Endpoints que validam a senha atual precisam de limite: quem tem a sessão mas
     * não a senha teria tentativas ilimitadas para descobri-la.
     */
    public function test_password_endpoints_are_rate_limited(): void
    {
        $user = User::factory()->create(['password' => 'senha-correta']);

        // 6 por minuto é o limite; a 7ª tem de ser barrada com 429.
        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->from(route('settings', 'seguranca'))
                ->delete(route('settings.sessions.destroy'), ['password' => 'chute-'.$i]);
        }

        $this->actingAs($user)
            ->delete(route('settings.sessions.destroy'), ['password' => 'chute-final'])
            ->assertStatus(429);
    }

    /** Registro também é limitado (cada POST custa um argon2id de 64 MiB). */
    public function test_register_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('register'), [
                'name' => 'Teste '.$i,
                'email' => "teste{$i}@example.com",
                'password' => 'senha-de-teste',
                'terms' => '1',
            ]);
            auth()->logout();
        }

        $this->post(route('register'), [
            'name' => 'Excedente',
            'email' => 'excedente@example.com',
            'password' => 'senha-de-teste',
            'terms' => '1',
        ])->assertStatus(429);

        $this->assertDatabaseMissing('users', ['email' => 'excedente@example.com']);
    }

    /**
     * Trocar a senha tem de derrubar as outras sessões — é a ação que a vítima toma
     * ao suspeitar de invasão. Antes, o invasor com o cookie seguia logado.
     */
    public function test_changing_password_destroys_other_sessions(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create(['password' => 'senha-antiga']);

        \DB::table('sessions')->insert([
            'id' => 'sessao-do-invasor',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'PHPUnit',
            'payload' => '',
            'last_activity' => 1750000000,
        ]);

        $this->actingAs($user)
            ->from(route('settings', 'seguranca'))
            ->put(route('password.update'), [
                'current_password' => 'senha-antiga',
                'password' => 'senha-nova-forte',
                'password_confirmation' => 'senha-nova-forte',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'sessao-do-invasor']);
    }
}
