<?php

namespace Tests\Feature;

use App\Mail\AlertaDeSeguranca;
use App\Mail\AlertaDoPainel;
use App\Mail\BemVindoDependente;
use App\Mail\ConfirmarEmailDoCadastro;
use App\Mail\ConfirmarNovoEmail;
use App\Mail\ContaDaFamiliaExcluida;
use App\Mail\LembreteDeVencimento;
use App\Mail\RedefinirSenha;
use App\Models\AdminAuditLog;
use App\Models\User;
use App\Notifications\RedefinicaoDeSenha;
use App\Notifications\VerificacaoDeEmail;
use App\Support\ContextoDeSeguranca;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * A parte TEXTO de todo e-mail sai com os caracteres de verdade (achado E-1 da auditoria de
 * 07/09/2026).
 *
 * O defeito: `emails/layout-texto` imprimia com `{{ }}`, que escapa para HTML — mas o que
 * chegava ali já era texto. Quem lia a parte texto (cliente só-texto, leitor de tela, a prévia
 * de alguns clientes) recebia `Use &quot;Esqueci a senha&quot;` e `Olá, Dependente &lt;Teste&gt;
 * &amp; Cia`; no alerta do painel, escape DUPLO (`&amp;lt;email@x&amp;gt;`), porque ele fazia
 * `strip_tags` sobre o `e()` sem decodificar. E o link saía com `&amp;signature=`: a assinatura
 * não conferia mais.
 *
 * A regra que fica: a parte texto é TEXTO — nenhuma entidade HTML, o dado da pessoa como ela o
 * escreveu —, e a parte HTML continua escapando todo dado do usuário, uma vez só.
 *
 * Percorre TODOS os Mailables de app/Mail e as notificações de app/Notifications, com dado que
 * carrega `"`, `'`, `<`, `>` e `&`. Um Mailable novo sem caso aqui derruba o teste-sentinela, em
 * vez de passar sem ser olhado. O e-mail é enviado de verdade pelo transporte `array`: é a
 * mensagem que iria para o fio, com as duas partes — montar à mão passaria com o layout errado.
 */
class TextoDosEmailsSemEntidadesTest extends TestCase
{
    use RefreshDatabase;

    /** Tudo o que o escape de HTML transforma, num nome que alguém poderia mesmo digitar. */
    private const HOSTIL = 'Ana "Aspas" D\'Ávila <b>&</b> Cia';

    /** Endereço válido com `&` (a RFC permite): aparece no corpo de vários avisos. */
    private const EMAIL_HOSTIL = 'nova&cia@exemplo.test';

    /** Entidade HTML de qualquer forma: nomeada, decimal ou hexadecimal. */
    private const ENTIDADE = '/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]{1,31});/i';

    private ?User $pessoa = null;

    private ?User $titular = null;

    private function pessoa(): User
    {
        return $this->pessoa ??= User::factory()->create([
            'name' => self::HOSTIL,
            'email' => 'ana@exemplo.test',
            'account_owner_id' => $this->titular()->id,
            'is_admin' => false,
        ]);
    }

    private function titular(): User
    {
        return $this->titular ??= User::factory()->create([
            'name' => 'Titular '.self::HOSTIL,
            'email' => 'titular@exemplo.test',
        ]);
    }

    private function contexto(): ContextoDeSeguranca
    {
        return new ContextoDeSeguranca(
            quando: '22 de setembro de 2026, às 10:00',
            ip: '203.0.113.9',
            dispositivo: 'Navegador <estranho> & "cia"',
        );
    }

    /**
     * Cada caso devolve o Mailable (enviado com `Mail::to`) ou a Notification (enviada à
     * pessoa com `notify`, como o app faz).
     *
     * @return array<string, array{Closure(self): (Mailable|Notification)}>
     */
    public static function emails(): array
    {
        $item = fn (int $dias) => ['nome' => self::HOSTIL, 'valor' => 1234.5, 'due' => now()->addDays($dias), 'diasRestantes' => $dias];

        return [
            'alerta: senha alterada' => [fn (self $t) => AlertaDeSeguranca::senhaAlterada($t->pessoa(), $t->contexto())],
            'alerta: senha redefinida' => [fn (self $t) => AlertaDeSeguranca::senhaRedefinida($t->pessoa(), $t->contexto())],
            'alerta: senha trocada pelo titular' => [fn (self $t) => AlertaDeSeguranca::senhaAlteradaPeloTitular($t->pessoa(), $t->titular(), $t->contexto())],
            'alerta: senha e e-mail trocados pelo titular' => [fn (self $t) => AlertaDeSeguranca::senhaAlteradaPeloTitular($t->pessoa(), $t->titular(), $t->contexto(), self::EMAIL_HOSTIL)],
            'alerta: pedido de troca de e-mail' => [fn (self $t) => AlertaDeSeguranca::emailTrocaPedida($t->pessoa(), self::EMAIL_HOSTIL, $t->contexto())],
            'alerta: e-mail trocado' => [fn (self $t) => AlertaDeSeguranca::emailAlterado($t->pessoa(), 'antiga&cia@exemplo.test', self::EMAIL_HOSTIL, $t->contexto())],
            'alerta: e-mail trocado pelo titular' => [fn (self $t) => AlertaDeSeguranca::emailAlteradoPeloTitular($t->pessoa(), $t->titular(), self::EMAIL_HOSTIL, $t->contexto())],
            'alerta: 2FA ativado' => [fn (self $t) => AlertaDeSeguranca::doisFatoresAtivado($t->pessoa(), $t->contexto())],
            'alerta: 2FA desativado' => [fn (self $t) => AlertaDeSeguranca::doisFatoresDesativado($t->pessoa(), $t->contexto())],
            'alerta: códigos de recuperação trocados' => [fn (self $t) => AlertaDeSeguranca::codigosDeRecuperacaoTrocados($t->pessoa(), $t->contexto())],
            'alerta: sessões encerradas' => [fn (self $t) => AlertaDeSeguranca::sessoesEncerradas($t->pessoa(), $t->contexto(), 3)],
            'alerta: conta excluída com dependentes' => [fn (self $t) => AlertaDeSeguranca::contaExcluida($t->pessoa(), $t->contexto(), [self::HOSTIL, 'Bruno & <Cia>'])],
            'alerta: conta excluída pela administração' => [fn (self $t) => AlertaDeSeguranca::contaExcluidaPelaAdministracao($t->pessoa(), '22 de setembro de 2026, às 10:00', [self::HOSTIL, 'Bruno & <Cia>'])],
            'painel: exclusão' => [fn (self $t) => new AlertaDoPainel(AdminAuditLog::EXCLUIU, self::HOSTIL, $t->contexto(), self::HOSTIL.' <ana&cia@exemplo.test>', 'Motivo & "coisa" <x>')],
            'painel: banimento' => [fn (self $t) => new AlertaDoPainel(AdminAuditLog::BANIU, self::HOSTIL, $t->contexto(), self::HOSTIL, self::HOSTIL)],
            'painel: 2FA zerado pelo terminal' => [fn (self $t) => new AlertaDoPainel(AdminAuditLog::ZEROU_2FA, self::HOSTIL, ContextoDeSeguranca::doTerminal(), self::HOSTIL.' <ana&cia@exemplo.test>', 'Motivo & "coisa" <x>')],
            'boas-vindas ao dependente' => [fn (self $t) => new BemVindoDependente($t->pessoa(), $t->titular())],
            'conta-família excluída' => [fn (self $t) => new ContaDaFamiliaExcluida(self::HOSTIL, 'Titular '.self::HOSTIL, '22 de setembro de 2026, às 10:00')],
            'conta-família excluída pela administração' => [fn (self $t) => ContaDaFamiliaExcluida::pelaAdministracao(self::HOSTIL, 'Titular '.self::HOSTIL, '22 de setembro de 2026, às 10:00')],
            'lembrete de vencimento' => [fn (self $t) => new LembreteDeVencimento($t->pessoa(), [$item(-3)], [$item(2)])],
            'confirmar novo e-mail' => [fn (self $t) => new ConfirmarNovoEmail($t->pessoa(), self::EMAIL_HOSTIL, 'https://app.test/confirmar?expires=1&hash=a&signature=b')],
            'confirmar e-mail do cadastro' => [fn (self $t) => new ConfirmarEmailDoCadastro($t->pessoa(), 'https://app.test/verify-email/1/a?expires=1&signature=b', 60)],
            'redefinir senha' => [fn (self $t) => new RedefinirSenha($t->pessoa(), 'https://app.test/reset-password/abc?email=ana%40exemplo.test', 60)],
            'notificação: verificação do cadastro' => [fn (self $t) => new VerificacaoDeEmail],
            'notificação: redefinição de senha' => [fn (self $t) => new RedefinicaoDeSenha('token-de-teste')],
        ];
    }

    /** Envia de verdade (transporte `array`) e devolve a mensagem que sairia. */
    private function enviar(Mailable|Notification $email): Email
    {
        if ($email instanceof Notification) {
            $this->pessoa()->notify($email);
        } else {
            Mail::to('destino@exemplo.test')->send($email);
        }

        return Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    #[DataProvider('emails')]
    public function test_a_parte_texto_sai_sem_entidade_html(Closure $montar): void
    {
        $mensagem = $this->enviar($montar($this));
        $texto = (string) $mensagem->getTextBody();

        $this->assertNotSame('', trim($texto), 'O e-mail saiu só em HTML — sem a parte texto.');

        preg_match_all(self::ENTIDADE, $texto, $achadas);
        $this->assertSame([], $achadas[0], 'A parte texto saiu com entidade HTML: '.implode(' ', array_unique($achadas[0])));

        // O dado da pessoa, como ela o escreveu (sem `&quot;`, sem `&lt;`).
        $this->assertStringContainsString(self::HOSTIL, $texto);

        preg_match_all(self::ENTIDADE, (string) $mensagem->getSubject(), $noAssunto);
        $this->assertSame([], $noAssunto[0], 'O assunto saiu com entidade HTML.');
    }

    #[DataProvider('emails')]
    public function test_a_parte_html_continua_escapando_o_dado_do_usuario_uma_vez_so(Closure $montar): void
    {
        $html = (string) $this->enviar($montar($this))->getHtmlBody();

        // Escapado...
        $this->assertStringNotContainsString('<b>&</b>', $html, 'Dado do usuário virou marcação no e-mail.');
        $this->assertStringContainsString(e(self::HOSTIL), $html);

        // ...uma vez só: o `&` de uma entidade escapado de novo é o escape duplo do painel.
        $this->assertDoesNotMatchRegularExpression('/&amp;(?:#\d+|[a-z]+);/i', $html, 'Escape duplo na parte HTML.');
    }

    /**
     * O link sai inteiro nas duas partes: cru no texto e com `&amp;` no `href` (que é como o
     * HTML escreve `&` num atributo — o navegador devolve o `&`).
     */
    public function test_o_link_da_acao_sai_utilizavel_nas_duas_partes(): void
    {
        $url = 'https://app.test/verify-email/1/a?expires=1&signature=b';

        $mensagem = $this->enviar(new ConfirmarEmailDoCadastro($this->pessoa(), $url, 60));

        $this->assertStringContainsString($url, (string) $mensagem->getTextBody());
        $this->assertStringContainsString('href="'.e($url).'"', (string) $mensagem->getHtmlBody());
    }

    /**
     * Sentinela: todo Mailable e toda Notification do app têm caso aqui. Sem isto, o próximo
     * e-mail entraria sem ninguém conferir a parte texto dele — foi assim que o do painel ficou
     * com escape duplo.
     */
    public function test_todo_email_do_app_tem_caso_neste_teste(): void
    {
        $cobertos = collect(self::emails())
            ->map(fn (array $caso) => get_class($caso[0]($this)))
            ->unique();

        $existentes = collect([...glob(app_path('Mail/*.php')), ...glob(app_path('Notifications/*.php'))])
            ->map(fn (string $arquivo) => str_contains($arquivo, '/Mail/')
                ? 'App\\Mail\\'.basename($arquivo, '.php')
                : 'App\\Notifications\\'.basename($arquivo, '.php'))
            ->filter(fn (string $classe) => is_subclass_of($classe, Mailable::class) || is_subclass_of($classe, Notification::class));

        $this->assertGreaterThanOrEqual(10, $existentes->count(), 'A busca pelos e-mails do app não achou nada.');

        foreach ($existentes as $classe) {
            $this->assertContains($classe, $cobertos, "E-mail sem caso no TextoDosEmailsSemEntidadesTest: {$classe}.");
        }
    }
}
