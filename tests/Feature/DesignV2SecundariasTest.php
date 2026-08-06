<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sobras do design v2, fechadas em 02/08/2026:
 *
 *  1. As quatro telas SECUNDÁRIAS de auth (esqueci/redefinir/confirmar senha e
 *     verificar e-mail) estavam no `layouts.guest` antigo enquanto login e
 *     cadastro já usavam o split com vídeo — agora todas usam `layouts.auth`.
 *  2. A bottom-nav do celular não tinha "Pagar despesas" nem "Metas": num app
 *     PWA-first, quem usava pelo celular não alcançava a tela de pagar conta.
 *  3. O dashboard formatava dinheiro com `number_format` cru, então um valor
 *     negativo saía "R$ -150,00" em vez de "−R$ 150,00" (regra do projeto:
 *     sinal ANTES do símbolo, com o traço U+2212).
 */
class DesignV2SecundariasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Marcadores exclusivos do `layouts.auth` (o split com vídeo). O
     * `layouts.guest` não tem painel visual nenhum — se um deles sumir da
     * resposta, a tela caiu de volta no layout antigo.
     */
    private function assertUsaLayoutAuthV2(TestResponse $resposta): void
    {
        $resposta->assertOk();
        $resposta->assertSee('<main class="auth">', false);   // shell split
        $resposta->assertSee('class="auth-visual"', false);   // painel do vídeo
        $resposta->assertSee('id="authVideo"', false);        // o vídeo em si
        $resposta->assertSee('class="auth-panel"', false);    // painel do formulário
        $resposta->assertDontSee('class="auth-wrap"', false); // wrapper do layouts.guest
    }

    /* ===================== 1. Telas de auth no layout v2 ===================== */

    public function test_esqueci_a_senha_usa_o_layout_v2_e_preserva_o_formulario(): void
    {
        $resposta = $this->get('/forgot-password');

        $this->assertUsaLayoutAuthV2($resposta);

        // Formulário intacto: mesma action, mesmo campo, CSRF presente
        $resposta->assertSee('action="'.route('password.email').'"', false);
        $resposta->assertSee('name="email"', false);
        $resposta->assertSee('name="_token"', false);
        $resposta->assertSee('Enviar link de redefinição');
        // Caminho de volta para o login (a tela não tem menu)
        $resposta->assertSee(route('login'));
    }

    public function test_redefinir_senha_usa_o_layout_v2_e_preserva_token_e_campos(): void
    {
        $resposta = $this->get('/reset-password/token-de-teste');

        $this->assertUsaLayoutAuthV2($resposta);

        $resposta->assertSee('action="'.route('password.store').'"', false);
        // O token vai no hidden — sem ele o POST não redefine nada
        $resposta->assertSee('name="token" value="token-de-teste"', false);
        $resposta->assertSee('name="email"', false);
        $resposta->assertSee('name="password"', false);
        $resposta->assertSee('name="password_confirmation"', false);
        $resposta->assertSee('Redefinir senha');
    }

    public function test_confirmar_senha_usa_o_layout_v2_e_preserva_o_formulario(): void
    {
        $user = User::factory()->create();

        $resposta = $this->actingAs($user)->get('/confirm-password');

        $this->assertUsaLayoutAuthV2($resposta);

        $resposta->assertSee('action="'.route('password.confirm').'"', false);
        $resposta->assertSee('name="password"', false);
        $resposta->assertSee('Confirmar');
        // Quem está logado precisa de saída: o layout v2 não tem marca clicável
        $resposta->assertSee(route('dashboard'));
    }

    public function test_verificar_email_usa_o_layout_v2_com_reenvio_status_e_sair(): void
    {
        $user = User::factory()->unverified()->create();

        $resposta = $this->actingAs($user)
            ->withSession(['status' => 'verification-link-sent'])
            ->get('/verify-email');

        $this->assertUsaLayoutAuthV2($resposta);

        // Reenvio e logout continuam sendo os dois POSTs da tela
        $resposta->assertSee('action="'.route('verification.send').'"', false);
        $resposta->assertSee('action="'.route('logout').'"', false);
        $resposta->assertSee('Reenviar e-mail de verificação');
        $resposta->assertSee('Sair');

        // O session('status') vira o aviso de "novo link enviado"
        $resposta->assertSee('Um novo link de verificação foi enviado para o e-mail cadastrado.');
        $resposta->assertSee('class="auth-status"', false);
    }

    public function test_esqueci_a_senha_mostra_no_banner_o_aviso_de_email_indisponivel(): void
    {
        // `log` é o mailer de dev: nada chega a ninguém. O controller devolve o
        // aviso em vez de mentir "enviamos o link" — e ele precisa aparecer bem,
        // não espremido como erro de campo.
        config(['mail.default' => 'log']);

        $this->from(route('password.request'))
            ->post('/forgot-password', ['email' => 'alguem@exemplo.com'])
            ->assertRedirect(route('password.request'));

        $resposta = $this->get('/forgot-password');

        $resposta->assertOk();
        $resposta->assertSee('class="auth-error long"', false);
        $resposta->assertSee('não está enviando e-mails');
        $resposta->assertSee(config('legal.contact_email'));
    }

    /* ===================== 2. Bottom-nav do celular ===================== */

    /** Recorta só o bloco da bottom-nav (a sidebar tem os mesmos links). */
    private function bottomNav(string $html): string
    {
        $this->assertMatchesRegularExpression('#<nav class="bottom-nav">.*?</nav>#s', $html);
        preg_match('#<nav class="bottom-nav">.*?</nav>#s', $html, $m);

        return $m[0];
    }

    public function test_bottom_nav_leva_para_pagar_despesas_e_metas(): void
    {
        $user = User::factory()->create();

        $nav = $this->bottomNav($this->actingAs($user)->get('/')->getContent());

        $this->assertStringContainsString(route('faturas.index'), $nav);
        $this->assertStringContainsString(route('metas.index'), $nav);
        $this->assertStringContainsString('Pagar', $nav);
        $this->assertStringContainsString('Metas', $nav);

        // Teto de 4 destinos + FAB: o `.bn-item` tem padding lateral de 14px, e um
        // 5º item estoura a largura num aparelho de 360px (rótulo quebra/espreme).
        $this->assertSame(4, substr_count($nav, 'data-pjax'), 'A barra deve ter 4 destinos + o FAB.');

        // O FAB de lançar não pode ter sido prejudicado
        $this->assertStringContainsString('data-launch-open', $nav);
        $this->assertStringContainsString('bn-item fab', $nav);
    }

    public function test_bottom_nav_marca_o_item_ativo_na_rota_certa(): void
    {
        $user = User::factory()->create();

        // Em /faturas, "Pagar" fica ativo e "Início" não
        $nav = $this->bottomNav($this->actingAs($user)->get(route('faturas.index'))->getContent());
        $this->assertMatchesRegularExpression(
            '#class="bn-item active"[^>]*href="[^"]*'.preg_quote(route('faturas.index'), '#').'"#',
            $nav
        );
        $this->assertDoesNotMatchRegularExpression(
            '#class="bn-item active"[^>]*href="[^"]*'.preg_quote(route('dashboard'), '#').'"#',
            $nav
        );

        // Em /metas, o ativo é "Metas"
        $nav = $this->bottomNav($this->actingAs($user)->get(route('metas.index'))->getContent());
        $this->assertMatchesRegularExpression(
            '#class="bn-item active"[^>]*href="[^"]*'.preg_quote(route('metas.index'), '#').'"#',
            $nav
        );
    }

    /* ===================== 3. Dinheiro negativo no dashboard ===================== */

    public function test_dashboard_mostra_saldo_negativo_no_formato_do_projeto(): void
    {
        $user = User::factory()->create();
        // Conta corrente com cheque especial: gasta mais do que tem e o
        // "disponível" (o que a lista de contas exibe) fica negativo.
        $conta = Account::factory()->for($user)->create([
            'name' => 'Conta Corrente',
            'type' => 'checking',
            'initial_balance' => 100,
            'overdraft_limit' => 500,
        ]);
        Transaction::factory()->for($user)->for($conta)->expense()->create([
            'amount' => 250.00,
            'date' => now()->toDateString(),
        ]);

        $resposta = $this->actingAs($user)->get('/');
        $resposta->assertOk();

        // Recorta a view do dashboard: a sidebar do shell é outro arquivo e tem
        // o próprio formato de patrimônio.
        $html = $resposta->getContent();
        $this->assertMatchesRegularExpression('#<section class="view" id="view-dashboard">.*?</section>#s', $html);
        preg_match('#<section class="view" id="view-dashboard">.*?</section>#s', $html, $m);

        // Sinal ANTES do símbolo, com o traço U+2212 (App\Support\Brl)
        $this->assertStringContainsString('−R$ 150,00', $m[0]);
        $this->assertStringNotContainsString('R$ -150', $m[0]);
    }

    /**
     * O `layouts/auth` tinha `<title>StabilMoney</title>` fixo, então as cinco
     * telas de auth apareciam idênticas na aba do navegador — quem deixa a
     * redefinição de senha aberta numa aba não achava mais qual era.
     */
    #[DataProvider('telasComTitulo')]
    public function test_cada_tela_de_auth_tem_titulo_proprio_na_aba(string $rota, string $esperado): void
    {
        $html = $this->get($rota)->assertOk()->getContent();

        $this->assertStringContainsString('<title>'.$esperado.' · StabilMoney</title>', $html);
    }

    public static function telasComTitulo(): array
    {
        return [
            'login' => ['/login', 'Entrar'],
            'cadastro' => ['/register', 'Criar conta'],
            'esqueci a senha' => ['/forgot-password', 'Recuperar senha'],
        ];
    }

    public function test_o_layout_guest_foi_removido(): void
    {
        // Nenhuma view o estende mais (as secundárias migraram para o layout v2).
        // Deixá-lo no repo convidaria uma tela nova a nascer no visual antigo.
        $this->assertFileDoesNotExist(resource_path('views/layouts/guest.blade.php'));
    }
}
