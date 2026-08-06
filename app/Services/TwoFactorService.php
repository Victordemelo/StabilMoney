<?php

namespace App\Services;

use App\Models\User;
use App\Support\RecoveryCodes;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;

/**
 * Verificação em duas etapas (2FA) por app autenticador — TOTP.
 *
 * **É opcional.** Nada aqui é chamado no cadastro nem em qualquer fluxo automático: só a
 * tela de Configurações › Segurança liga, e só o dono da conta pode ligá-la, informando a
 * senha atual. Enquanto ninguém ligar, `users.two_factor_*` fica nulo e o login não muda.
 *
 * O ciclo tem três estados, e a diferença entre os dois primeiros é o que impede o pior
 * defeito possível neste recurso (trancar o usuário fora da própria conta):
 *
 *  1. **Desligado** — tudo nulo.
 *  2. **Pendente** — `iniciar()` gerou o segredo e o QR está na tela, mas o login AINDA
 *     NÃO cobra código. Se o usuário fechar a aba sem escanear, nada acontece com ele.
 *  3. **Ligado** — `confirmar()` recebeu um código válido, o que PROVA que o autenticador
 *     já está com o segredo. Só aqui `two_factor_confirmed_at` é preenchido, e só a
 *     partir daqui o login cobra a segunda etapa.
 *
 * As escritas que gastam algo de uso único (código TOTP e código de recuperação) rodam sob
 * `lockForUpdate`, no mesmo espírito do FundingService: verificar e gravar em passos
 * separados é uma janela de corrida, e aqui a corrida vale o login.
 */
class TwoFactorService
{
    /**
     * Começa a configuração: gera um segredo novo e deixa a conta em estado PENDENTE.
     *
     * Apaga qualquer 2FA anterior (segredo, confirmação, códigos e o passo já gasto) —
     * reconfigurar é justamente o que a pessoa faz ao trocar de celular, e sobrar
     * qualquer resto do aparelho antigo é o que faz "reconfigurei e continua pedindo o
     * código velho".
     */
    public function iniciar(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => Totp::gerarSegredo(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /**
     * Confirma a configuração com o primeiro código do autenticador.
     *
     * @return list<string>|null os códigos de recuperação recém-criados, ou null se o
     *                           código não conferiu (nada muda nesse caso).
     */
    public function confirmar(User $user, string $codigo): ?array
    {
        if (! $user->doisFatoresPendente()) {
            return null;
        }

        $passo = Totp::verificar($user->two_factor_secret, $codigo, $user->two_factor_last_step);

        if ($passo === null) {
            return null;
        }

        $codigos = RecoveryCodes::gerar();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            // O código gasto para confirmar já não serve para entrar: sem isto, quem
            // estivesse olhando a tela por cima do ombro reusaria o mesmo número.
            'two_factor_last_step' => $passo,
            'two_factor_recovery_codes' => $codigos,
        ])->save();

        return $codigos;
    }

    /** Desliga o 2FA e apaga tudo que dependia dele. */
    public function desligar(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    /**
     * Troca a lista de códigos de recuperação por uma nova.
     *
     * Os antigos param de valer na hora — é isso que a pessoa quer quando desconfia que o
     * papel foi visto, e é também a saída de quem simplesmente perdeu a lista.
     *
     * @return list<string>
     */
    public function regerarCodigosDeRecuperacao(User $user): array
    {
        $codigos = RecoveryCodes::gerar();

        $user->forceFill(['two_factor_recovery_codes' => $codigos])->save();

        return $codigos;
    }

    /**
     * O código de 6 dígitos confere? (usado no desafio do login)
     *
     * Grava o passo aceito para o MESMO código não valer uma segunda vez. A leitura, a
     * verificação e a gravação acontecem sob trava, senão dois POSTs simultâneos com o
     * mesmo código passariam os dois — que é exatamente o replay que se quer barrar.
     */
    public function verificarCodigo(User $user, string $codigo): bool
    {
        return DB::transaction(function () use ($user, $codigo) {
            $travado = User::whereKey($user->getKey())->lockForUpdate()->first();

            if ($travado === null || ! $travado->temDoisFatores()) {
                return false;
            }

            $passo = Totp::verificar(
                $travado->two_factor_secret,
                $codigo,
                $travado->two_factor_last_step,
            );

            if ($passo === null) {
                return false;
            }

            $travado->forceFill(['two_factor_last_step' => $passo])->save();

            return true;
        });
    }

    /**
     * Gasta um código de recuperação. Cada um vale UMA vez.
     *
     * Também sob trava: sem ela, dois envios simultâneos do mesmo código gravariam listas
     * concorrentes e um deles voltaria para a lista salva por último.
     */
    public function consumirCodigoDeRecuperacao(User $user, string $codigo): bool
    {
        return DB::transaction(function () use ($user, $codigo) {
            $travado = User::whereKey($user->getKey())->lockForUpdate()->first();

            if ($travado === null || ! $travado->temDoisFatores()) {
                return false;
            }

            $restantes = RecoveryCodes::consumir($travado->two_factor_recovery_codes ?? [], $codigo);

            if ($restantes === null) {
                return false;
            }

            $travado->forceFill(['two_factor_recovery_codes' => $restantes])->save();

            return true;
        });
    }

    /** URI `otpauth://` do segredo atual — o conteúdo do QR e do link "abrir no app". */
    public function uri(User $user): string
    {
        return Totp::uri(
            $user->two_factor_secret,
            $user->email,
            (string) config('app.name'),
        );
    }

    /**
     * QR do setup, como SVG pronto para embutir na página.
     *
     * SVG inline (e não uma rota que serve imagem) por três motivos: não cria um endereço
     * novo por onde o segredo possa vazar, não entra no cache do navegador nem do service
     * worker, e escala sem borrar em tela de celular.
     */
    public function qrCodeSvg(User $user, int $tamanho = 208): string
    {
        $writer = new Writer(new ImageRenderer(
            // Margem 2 no SVG + o respiro branco do card fecham a "zona de silêncio" que
            // o leitor precisa em volta do código.
            new RendererStyle($tamanho, 2),
            new SvgImageBackEnd,
        ));

        $svg = $writer->writeString($this->uri($user));

        // O writer devolve um documento XML completo; embutido no meio de um HTML, a
        // declaração XML da primeira linha é lixo. Fica só a tag <svg> em diante.
        // (Nunca escreva a declaração literal num comentário: o interpretador do PHP
        // encerra o bloco no fecha-tag, mesmo dentro de `//`, e o arquivo deixa de compilar.)
        return trim(substr($svg, (int) strpos($svg, '<svg')));
    }
}
