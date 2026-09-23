<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\Concerns\LeConfigComAmbiente;
use Tests\TestCase;

/**
 * O envio de e-mail desiste em 10 s, não em um minuto (item 27a da rodada de
 * pré-publicação).
 *
 * O defeito: `config/mail.php` deixava `'timeout' => null` no SMTP, e `null` vale o
 * `default_socket_timeout` do PHP — 60 s. Um servidor de e-mail que DESCARTA pacotes em
 * vez de recusar segurava o cadastro e a troca de senha por um minuto inteiro. Esperar
 * mais não entrega nada: a falha de envio já é tratada (o `Notificador` a engole e o
 * cadastro segue sem exigir a confirmação), então o que importa é ela chegar logo.
 *
 * Nenhum e-mail sai daqui: o "servidor" do último teste é um socket local que aceita a
 * conexão e nunca responde.
 */
class SmtpDesisteEmDezSegundosTest extends TestCase
{
    use LeConfigComAmbiente;

    /** @return array<string, mixed> o mailer `smtp` do arquivo de config, com esse ambiente */
    private function smtpCom(array $ambiente): array
    {
        return $this->configComAmbiente('mail.php', $ambiente)['mailers']['smtp'];
    }

    public function test_sem_mail_timeout_no_env_o_smtp_desiste_em_10_segundos(): void
    {
        $smtp = $this->smtpCom(['MAIL_TIMEOUT' => null]);

        $this->assertSame(10.0, $smtp['timeout']);

        // E o valor chega ao socket que o Laravel monta — não fica só no array.
        config(['mail.mailers.smtp' => ['host' => '127.0.0.1', 'port' => 2525] + $smtp]);
        Mail::purge('smtp');
        $transporte = Mail::mailer('smtp')->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transporte);
        $this->assertInstanceOf(SocketStream::class, $transporte->getStream());
        $this->assertSame(10.0, $transporte->getStream()->getTimeout());
    }

    public function test_mail_timeout_no_env_muda_o_limite(): void
    {
        $this->assertSame(3.0, $this->smtpCom(['MAIL_TIMEOUT' => '3'])['timeout']);
        $this->assertSame(2.5, $this->smtpCom(['MAIL_TIMEOUT' => '2.5'])['timeout']);
    }

    /** Zero, para o socket, é desistir NA HORA: nenhum e-mail sairia. */
    #[DataProvider('limitesInvalidos')]
    public function test_limite_invalido_cai_no_padrao_e_nunca_em_desistir_na_hora(string $valor): void
    {
        $this->assertSame(10.0, $this->smtpCom(['MAIL_TIMEOUT' => $valor])['timeout']);
    }

    public static function limitesInvalidos(): array
    {
        return [
            'vazio' => [''],
            'zero' => ['0'],
            'negativo' => ['-5'],
            'texto' => ['dez'],
        ];
    }

    public function test_o_env_example_documenta_o_limite(): void
    {
        $exemplo = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        $this->assertSame('10', $exemplo['MAIL_TIMEOUT'] ?? null);
    }

    /**
     * O limite chega de verdade ao socket: um servidor que aceita a conexão e nunca
     * responde faz o envio desistir no limite configurado.
     */
    public function test_servidor_mudo_faz_o_envio_desistir_no_limite(): void
    {
        $servidor = stream_socket_server('tcp://127.0.0.1:0', $codigo, $erro);
        $this->assertNotFalse($servidor, "Não deu para abrir o socket local: {$erro}");
        $porta = (int) parse_url('tcp://'.stream_socket_get_name($servidor, false), PHP_URL_PORT);

        config(['mail.mailers.smtp' => $this->smtpCom([
            'MAIL_TIMEOUT' => '1',
            'MAIL_URL' => null,
            'MAIL_SCHEME' => 'smtp',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => (string) $porta,
            'MAIL_USERNAME' => null,
            'MAIL_PASSWORD' => null,
        ])]);
        Mail::purge('smtp');

        // Sem o limite valeria o `default_socket_timeout` do PHP. Baixá-lo aqui faz o teste
        // falhar em segundos (e não em um minuto) se o limite deixar de chegar ao socket.
        $padraoDoPhp = ini_set('default_socket_timeout', '6');
        $inicio = microtime(true);

        try {
            Mail::mailer('smtp')->raw('teste', fn ($mensagem) => $mensagem->to('ninguem@example.test')->subject('teste'));
            $this->fail('O servidor não responde: o envio tinha de desistir.');
        } catch (TransportExceptionInterface $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        } finally {
            ini_set('default_socket_timeout', (string) $padraoDoPhp);
            fclose($servidor);
        }

        $this->assertLessThan(4, microtime(true) - $inicio, 'O envio esperou além do limite configurado.');
    }
}
