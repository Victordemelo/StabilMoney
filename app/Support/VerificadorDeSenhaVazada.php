<?php

namespace App\Support;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Confere se uma senha aparece em vazamentos públicos (Pwned Passwords, do Have I Been
 * Pwned). É quem a regra `uncompromised()` da política de senha (`Password::defaults()`)
 * consulta: cadastro, troca e redefinição de senha, criar e editar dependente, `admin:criar`.
 *
 * Substitui o `NotPwnedVerifier` do framework por UM motivo: quando a consulta falha, ele
 * aceita a senha sem deixar rastro que sirva. Medido contra o HEAD em 17/09/2026:
 *
 *  - HTTP 503, 429 ou um 200 com HTML (portal cativo, proxy): senha aceita, NADA no log;
 *  - falha de conexão: senha aceita e um `report()` genérico ("cURL error 6 ... for
 *    https://api.pwnedpasswords.com/range/5BAA6") que não diz que uma senha passou sem
 *    conferência — e, de quebra, grava no log o começo do SHA-1 da senha.
 *
 * De brinde, corrige o padding da consulta, que o verificador do framework pedia de um
 * jeito que a API ignora (ver `consultar`).
 *
 * A POLÍTICA NÃO MUDA: a falha continua ABERTA (decidido em 17/09/2026).
 * Recusar a senha quando um serviço de terceiro cai tiraria do ar o cadastro, a troca e a
 * redefinição de senha — e a redefinição é o caminho de quem está perdendo a conta. O
 * `min(8)` continua valendo de qualquer jeito. O que muda é que a falha passa a ser VISTA:
 * um aviso por senha aceita sem a checagem, com o motivo, a origem e o tempo gasto, e sem
 * a senha, o hash ou o prefixo do hash.
 *
 * O resto é o protocolo de sempre, por k-anonimato: só os 5 primeiros caracteres do SHA-1
 * saem daqui, e a API devolve todos os sufixos vazados com aquele começo.
 */
final class VerificadorDeSenhaVazada implements UncompromisedVerifier
{
    public const ENDERECO = 'https://api.pwnedpasswords.com/range/';

    /**
     * Segundos até desistir da consulta (conexão incluída).
     *
     * Era 30, o valor do verificador do framework — e 30 s é o que o cadastro, a troca e
     * a redefinição de senha ficavam parados quando o Have I Been Pwned estava lento. Só
     * que a falha já é ABERTA (a senha passa, com aviso no log): esperar mais não compra
     * segurança nenhuma, só prende a pessoa na tela. A API responde em milissegundos
     * quando está bem; 5 s ainda cobrem uma rede ruim, e são um sexto da espera antiga.
     */
    public const TEMPO_LIMITE_EM_SEGUNDOS = 5;

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array{value: mixed, threshold: int}  $data
     * @return bool true = pode usar (não vazou, ou não deu para conferir)
     */
    public function verify($data): bool
    {
        $senha = (string) $data['value'];

        // Mesma regra do framework: senha vazia nunca é "limpa".
        if ($senha === '') {
            return false;
        }

        $hash = strtoupper(sha1($senha));
        $prefixo = substr($hash, 0, 5);

        $vazamentos = $this->consultar($prefixo);

        // Falha ABERTA — e o motivo já está no log (ver `consultar`).
        if ($vazamentos === null) {
            return true;
        }

        return ($vazamentos[substr($hash, 5)] ?? 0) <= (int) $data['threshold'];
    }

    /**
     * Sufixos vazados com aquele prefixo e quantas vezes cada um apareceu — ou null quando
     * a consulta não pôde ser feita (e aí o aviso já foi registrado).
     *
     * @return array<string, int>|null
     */
    private function consultar(string $prefixo): ?array
    {
        $inicio = hrtime(true);

        try {
            $resposta = $this->http
                // Padding: a resposta sai sempre com centenas de linhas a mais, então nem o
                // tamanho dela entrega o prefixo a quem observa a rede. O valor tem de ser
                // a STRING "true": o framework passa o booleano `true`, que chega à API
                // como "1" — e com "1" a API NÃO aplica o padding. Medido em 17/09/2026 no
                // prefixo 00000: 0 linhas de padding com "1", 119 com "true". (O Guzzle
                // 7.11 também já avisa que booleano em header deixa de valer no 8.)
                ->withHeaders(['Add-Padding' => 'true'])
                ->timeout(self::TEMPO_LIMITE_EM_SEGUNDOS)
                ->get(self::ENDERECO.$prefixo);
        } catch (Throwable $e) {
            // A mensagem do cURL traz a URL, e a URL traz o prefixo do hash da senha.
            $this->registrarFalha($e instanceof ConnectionException ? 'conexao' : 'excecao', $inicio, [
                'erro' => $e::class.': '.Str::limit(str_ireplace($prefixo, '*****', $e->getMessage()), 300),
            ]);

            return null;
        }

        if (! $resposta->successful()) {
            $this->registrarFalha('http', $inicio, ['status' => $resposta->status()]);

            return null;
        }

        $vazamentos = [];

        foreach (preg_split('/\R/', trim($resposta->body())) as $linha) {
            if (preg_match('/^([0-9A-F]{35}):(\d+)$/i', trim($linha), $partes) === 1) {
                $vazamentos[strtoupper($partes[1])] = (int) $partes[2];
            }
        }

        // Com o padding, uma resposta legítima NUNCA vem vazia. Um 200 sem nenhuma linha
        // no formato da API é outra coisa respondendo no lugar dela (portal cativo de
        // Wi-Fi, proxy, página de erro com o status errado). Ler isso como "nenhum
        // vazamento" é aprovar a senha em silêncio — o que esta classe existe para evitar.
        if ($vazamentos === []) {
            $this->registrarFalha('resposta_invalida', $inicio, ['status' => $resposta->status()]);

            return null;
        }

        return $vazamentos;
    }

    /**
     * Um aviso por senha aceita sem a checagem. Nunca a senha, o hash nem o prefixo.
     *
     * @param  array<string, mixed>  $detalhes
     */
    private function registrarFalha(string $motivo, int $inicio, array $detalhes): void
    {
        $rota = request()->route();

        Log::warning('Senha aceita SEM a checagem de vazamento: a consulta ao Pwned Passwords falhou.', [
            // conexao | excecao | http | resposta_invalida
            'motivo' => $motivo,
            ...$detalhes,
            // Distingue o serviço fora do ar (erro imediato) do lento (bate no tempo limite).
            'tempo_ms' => intdiv(hrtime(true) - $inicio, 1_000_000),
            // Qual fluxo aceitou a senha. O PADRÃO da rota, e não a URL: nada de ids.
            'origem' => $rota !== null ? request()->method().' '.$rota->uri() : 'fora de requisição HTTP',
            // Guard explícito: numa requisição do painel o guard padrão é o `admin`.
            'user_id' => Auth::guard('web')->id(),
        ]);
    }
}
