<?php

namespace Tests\Unit;

use App\Support\RecoveryCodes;
use App\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O algoritmo do 2FA, isolado do resto do app.
 *
 * A parte que importa aqui são os **vetores oficiais do apêndice B da RFC 6238**: se a
 * implementação passar neles, o código que o servidor calcula é bit a bit o mesmo que o
 * Google Authenticator, o Authy, o 1Password e o gerenciador de senhas do navegador
 * mostram. Sem esse ancoramento, um erro de um bit no truncamento produziria códigos
 * plausíveis (6 dígitos, mudando a cada 30 s) que nenhum app do mundo confirmaria — e o
 * defeito só apareceria com o usuário na frente do celular.
 */
class TotpTest extends TestCase
{
    /**
     * Semente dos vetores da RFC: a string ASCII "12345678901234567890" (20 bytes)
     * escrita em base32, que é como o segredo trafega para o autenticador.
     */
    private const SEMENTE_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * Vetores do apêndice B (RFC 6238), variante SHA-1.
     *
     * A RFC publica os códigos com 8 dígitos; o app usa 6, que são os 6 últimos —
     * o truncamento é o mesmo, só muda o módulo.
     *
     * @return array<string, array{int, string}>
     */
    public static function vetoresDaRfc(): array
    {
        return [
            'T = 59 s' => [59, '287082'],
            'T = 1111111109 s' => [1111111109, '081804'],
            'T = 1111111111 s' => [1111111111, '050471'],
            'T = 1234567890 s' => [1234567890, '005924'],
            'T = 2000000000 s' => [2000000000, '279037'],
            'T = 20000000000 s' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('vetoresDaRfc')]
    public function test_gera_os_codigos_dos_vetores_oficiais_da_rfc_6238(int $segundos, string $esperado): void
    {
        $this->assertSame(
            $esperado,
            Totp::codigo(self::SEMENTE_RFC, Totp::passoAtual($segundos)),
            "O código em T={$segundos}s não bate com a RFC 6238 — nenhum autenticador confirmaria."
        );
    }

    public function test_segredo_novo_tem_160_bits_em_base32(): void
    {
        $segredo = Totp::gerarSegredo();

        // 20 bytes = 160 bits = 32 caracteres base32 (o tamanho recomendado pela RFC 4226).
        $this->assertSame(32, strlen($segredo));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $segredo);
        $this->assertNotSame($segredo, Totp::gerarSegredo(), 'Dois segredos seguidos vieram iguais.');
    }

    public function test_aceita_o_codigo_do_passo_atual(): void
    {
        $agora = 1_800_000_000;
        $codigo = Totp::codigo(self::SEMENTE_RFC, Totp::passoAtual($agora));

        $this->assertSame(
            Totp::passoAtual($agora),
            Totp::verificar(self::SEMENTE_RFC, $codigo, agora: $agora),
        );
    }

    /** Relógio do celular adiantado/atrasado em até 30 s ainda entra. */
    public function test_tolera_um_passo_de_diferenca_para_cada_lado(): void
    {
        $agora = 1_800_000_000;
        $passo = Totp::passoAtual($agora);

        $this->assertNotNull(Totp::verificar(self::SEMENTE_RFC, Totp::codigo(self::SEMENTE_RFC, $passo - 1), agora: $agora));
        $this->assertNotNull(Totp::verificar(self::SEMENTE_RFC, Totp::codigo(self::SEMENTE_RFC, $passo + 1), agora: $agora));
    }

    /** Dois passos já é fora da janela — senão a janela de força bruta cresce sem limite. */
    public function test_recusa_dois_passos_de_diferenca(): void
    {
        $agora = 1_800_000_000;
        $passo = Totp::passoAtual($agora);

        $this->assertNull(Totp::verificar(self::SEMENTE_RFC, Totp::codigo(self::SEMENTE_RFC, $passo - 2), agora: $agora));
        $this->assertNull(Totp::verificar(self::SEMENTE_RFC, Totp::codigo(self::SEMENTE_RFC, $passo + 2), agora: $agora));
    }

    /**
     * Replay: o mesmo código não vale duas vezes.
     *
     * Sem isto, quem espia a tela (ou intercepta o POST) tem os 30 s de vida do código
     * para reusá-lo — e a segunda etapa deixa de ser "algo que você TEM".
     */
    public function test_recusa_o_mesmo_passo_uma_segunda_vez(): void
    {
        $agora = 1_800_000_000;
        $passo = Totp::passoAtual($agora);
        $codigo = Totp::codigo(self::SEMENTE_RFC, $passo);

        $this->assertSame($passo, Totp::verificar(self::SEMENTE_RFC, $codigo, agora: $agora));
        $this->assertNull(Totp::verificar(self::SEMENTE_RFC, $codigo, depoisDoPasso: $passo, agora: $agora));
    }

    /** O autenticador mostra "123 456"; copiar com o espaço não pode reprovar. */
    public function test_ignora_espacos_e_recusa_o_que_nao_tem_seis_digitos(): void
    {
        $agora = 1_800_000_000;
        $codigo = Totp::codigo(self::SEMENTE_RFC, Totp::passoAtual($agora));
        $comEspaco = substr($codigo, 0, 3).' '.substr($codigo, 3);

        $this->assertNotNull(Totp::verificar(self::SEMENTE_RFC, $comEspaco, agora: $agora));
        $this->assertNull(Totp::verificar(self::SEMENTE_RFC, '12345', agora: $agora));
        $this->assertNull(Totp::verificar(self::SEMENTE_RFC, '', agora: $agora));
    }

    public function test_uri_do_qr_leva_segredo_emissor_e_parametros(): void
    {
        $uri = Totp::uri(self::SEMENTE_RFC, 'victor@exemplo.com', 'StabilMoney');

        $this->assertStringStartsWith('otpauth://totp/StabilMoney:victor%40exemplo.com?', $uri);
        $this->assertStringContainsString('secret='.self::SEMENTE_RFC, $uri);
        $this->assertStringContainsString('issuer=StabilMoney', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function test_segredo_sai_em_grupos_de_quatro_para_digitacao_manual(): void
    {
        $this->assertSame(
            'GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ',
            Totp::formatarSegredo(self::SEMENTE_RFC),
        );

        // E o que foi formatado continua valendo como segredo (o decode ignora o espaço).
        $this->assertSame(
            Totp::codigo(self::SEMENTE_RFC, 1),
            Totp::codigo(Totp::formatarSegredo(self::SEMENTE_RFC), 1),
        );
    }

    // ---------------------------------------------------------------- recuperação

    public function test_codigos_de_recuperacao_saem_no_formato_anotavel_e_sem_repetir(): void
    {
        $codigos = RecoveryCodes::gerar();

        $this->assertCount(RecoveryCodes::QUANTIDADE, $codigos);
        $this->assertSame($codigos, array_unique($codigos), 'Saíram códigos repetidos na mesma lista.');

        foreach ($codigos as $codigo) {
            // Sem 0/O, 1/I/L e U: são feitos para serem lidos de um papel.
            $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-TVWXYZ]{5}-[2-9A-HJKMNP-TVWXYZ]{5}$/', $codigo);
        }
    }

    public function test_codigo_de_recuperacao_e_de_uso_unico(): void
    {
        $codigos = RecoveryCodes::gerar();
        $usado = $codigos[2];

        $restantes = RecoveryCodes::consumir($codigos, $usado);

        $this->assertNotNull($restantes);
        $this->assertCount(RecoveryCodes::QUANTIDADE - 1, $restantes);
        $this->assertNotContains($usado, $restantes);

        // Segunda tentativa com o mesmo código: já não existe.
        $this->assertNull(RecoveryCodes::consumir($restantes, $usado));
    }

    public function test_codigo_de_recuperacao_aceita_minuscula_e_sem_hifen(): void
    {
        $codigos = RecoveryCodes::gerar();
        $digitado = strtolower(str_replace('-', ' ', $codigos[0]));

        $this->assertNotNull(RecoveryCodes::consumir($codigos, $digitado));
    }

    public function test_codigo_de_recuperacao_errado_ou_vazio_nao_consome_nada(): void
    {
        $codigos = RecoveryCodes::gerar();

        $this->assertNull(RecoveryCodes::consumir($codigos, 'ZZZZZ-ZZZZZ'));
        $this->assertNull(RecoveryCodes::consumir($codigos, ''));
        $this->assertNull(RecoveryCodes::consumir($codigos, '   '));
    }
}
