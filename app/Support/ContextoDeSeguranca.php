<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Quando e de onde uma ação sensível aconteceu — o miolo dos alertas de segurança.
 *
 * Um e-mail que diz apenas "sua senha foi alterada" deixa a pessoa sem saber o que fazer:
 * ela mesma pode ter trocado cinco minutos antes. Com **data, IP e aparelho** ela consegue
 * decidir em dois segundos se aquilo foi ela — e é essa decisão que transforma o aviso em
 * defesa. Sem os detalhes, o alerta vira ruído e, depois de três, ninguém mais lê.
 *
 * O IP vai em texto no corpo do e-mail de propósito: é o IP do próprio destinatário, indo
 * para o próprio destinatário. (Diferente de `users.terms_accepted_ip`, que fica cifrado
 * porque é dado de terceiro guardado em repouso no nosso banco.)
 */
final class ContextoDeSeguranca
{
    public function __construct(
        public readonly string $quando,
        public readonly ?string $ip,
        public readonly string $dispositivo,
    ) {}

    public static function doRequest(Request $request): self
    {
        return new self(
            quando: self::agoraPorExtenso(),
            ip: $request->ip(),
            dispositivo: BrowserSessions::descrever($request->userAgent()),
        );
    }

    /**
     * Ação feita por um comando no terminal do servidor (ex.: `admin:zerar-2fa`), e não por
     * uma requisição.
     *
     * Sem IP e com o "aparelho" dito como ele é. O `request()` de um comando existe, mas é de
     * mentira (127.0.0.1, user-agent "Symfony"): um alerta com "Navegador desconhecido no
     * Sistema desconhecido" mandaria quem o lê procurar um navegador que nunca existiu.
     */
    public static function doTerminal(): self
    {
        return new self(
            quando: self::agoraPorExtenso(),
            ip: null,
            dispositivo: 'Terminal do servidor (comando artisan)',
        );
    }

    /**
     * "6 de agosto de 2026, às 00:42" — data por extenso porque o e-mail pode ser lido dias
     * depois, quando "ontem às 3h" já não localiza ninguém.
     *
     * Pública para o aviso que só pode levar o QUANDO: numa exclusão feita pelo painel, o IP
     * e o aparelho da requisição são os do administrador — dado de outra pessoa, que não vai
     * para o e-mail de quem perdeu a conta (ver Admin\ModeracaoController::excluir).
     */
    public static function agoraPorExtenso(): string
    {
        return now()->translatedFormat('j \d\e F \d\e Y, \à\s H:i');
    }

    /**
     * Linhas da tabela de detalhes do e-mail.
     *
     * @return array<string, string>
     */
    public function paraDetalhes(): array
    {
        return array_filter([
            'Quando' => $this->quando,
            'Aparelho' => $this->dispositivo,
            'Endereço IP' => $this->ip,
        ]);
    }
}
