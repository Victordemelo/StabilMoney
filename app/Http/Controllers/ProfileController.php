<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Mail\AlertaDeSeguranca;
use App\Models\Account;
use App\Models\User;
use App\Services\FixedBillService;
use App\Services\TwoFactorService;
use App\Support\ContextoDeSeguranca;
use App\Support\Mailer;
use App\Support\Notificador;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        // A conta como ela era ANTES desta edição. Serve só para endereçar os avisos de troca
        // de e-mail (A-7) ao endereço antigo — nunca é salva.
        $antes = clone $user;

        // 'avatar' é arquivo e 'current_password' é só confirmação — nenhum dos dois
        // é coluna do model, então ficam fora do fill().
        $user->fill($request->safe()->except(['avatar', 'current_password']));

        $trocouEmail = $user->isDirty('email');
        $emailNovo = $user->email;

        if ($trocouEmail && Mailer::entrega()) {
            // TROCA EM DUAS ETAPAS: o endereço novo fica pendente e o login continua
            // pelo antigo até o link enviado AO NOVO ser clicado. A senha atual já é
            // exigida (isso barra sessão sequestrada), mas ela não prova que o endereço
            // digitado é seu — e o e-mail é o que recupera a conta. Uma letra errada
            // apontaria a conta para um endereço que não se controla.
            $user->email = $user->getOriginal('email');
            $user->pending_email = $emailNovo;
            $user->pending_email_sent_at = now();
        } elseif ($trocouEmail) {
            // Sem transporte de e-mail (fase de testes) não há como confirmar nada: a
            // troca vale na hora, como sempre valeu. A senha atual segue obrigatória.
            //
            // E o endereço nasce JÁ VERIFICADO, de propósito. Marcar "não verificado"
            // seria semanticamente mais bonito e operacionalmente um desastre: não
            // existe link para mandar, então a pessoa ficaria num estado do qual nada
            // a tira — e no dia em que o middleware `verified` entrasse (item 13 do
            // checklist), viraria uma conta trancada sem saída. É o mesmo invariante
            // que o RegisteredUserController mantém no cadastro: **enquanto o app não
            // consegue enviar e-mail, ninguém fica pendente de confirmação.**
            //
            // Com o SMTP configurado este ramo não roda: cai no `if` acima, que exige
            // a confirmação de verdade no endereço novo.
            $user->email_verified_at = now();
            $user->pending_email = null;
            $user->pending_email_sent_at = null;
        }

        if ($request->hasFile('avatar')) {
            // Apaga a foto antiga e grava a nova sem metadados (EXIF/GPS).
            $user->storeAvatar($request->file('avatar'));
        }

        $user->save();

        if (! $trocouEmail) {
            // Nome, telefone, foto: nada disso decide quem recupera a conta, então ninguém
            // é avisado. Alerta que dispara à toa é alerta que ninguém lê no dia certo.
            return Redirect::route('profile.edit')->with('status', 'profile-updated');
        }

        // A-7 da auditoria de 05/09/2026: a troca de e-mail não avisava o endereço ANTIGO.
        // Quem tem a sessão e a senha trocava em silêncio o canal que recupera a conta — e
        // o endereço antigo é o único que essa pessoa não controla.
        //
        // Os avisos saem para `$antes` (o e-mail de antes da edição), nunca para `$user`:
        // no ramo sem confirmação o `email` já mudou quando chegamos aqui.
        if (Mailer::entrega()) {
            $user->sendPendingEmailVerification();

            // No PEDIDO, e não só na confirmação: é o único momento em que o dono ainda
            // consegue impedir a troca (ver AlertaDeSeguranca::emailTrocaPedida). O texto
            // fala em "pedido" — a troca pode nem acontecer.
            Notificador::avisar($antes, AlertaDeSeguranca::emailTrocaPedida(
                $user,
                $emailNovo,
                ContextoDeSeguranca::doRequest($request),
            ));

            return Redirect::route('profile.edit')->with(
                'status',
                'Enviamos um link de confirmação para '.$emailNovo.'. A troca só vale depois que você abrir esse link '
                    .'(ele expira em 2 horas); até lá, você continua entrando com '.$antes->email.'.',
            );
        }

        // Troca que já valeu na hora (app sem transporte de e-mail). O aviso de "trocado" é o
        // mesmo da confirmação. Hoje ele não sai — sem transporte o Notificador não envia
        // nada, como todo alerta —, mas a regra "toda troca de e-mail avisa o endereço antigo"
        // fica escrita no código, e não na coincidência de este ramo só rodar sem SMTP.
        Notificador::avisar($antes, AlertaDeSeguranca::emailAlterado(
            $user,
            $antes->email,
            $emailNovo,
            ContextoDeSeguranca::doRequest($request),
        ));

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Confirma a troca de e-mail pelo link enviado ao ENDEREÇO NOVO.
     *
     * A rota é assinada e expira em 2h. O `hash` amarra o link ao endereço que estava
     * pendente quando ele foi gerado: se a pessoa pedir outra troca no meio do caminho,
     * o link antigo deixa de valer — senão o primeiro link ainda gravaria um e-mail que
     * já não é o desejado.
     *
     * **O link só vale na sessão da PRÓPRIA conta** (A-12 da auditoria de 05/09/2026). A rota
     * fica no grupo `auth`, mas qualquer conta logada passava: o link da conta A, aberto num
     * navegador em que B estava conectado, gravava o e-mail novo em A e mostrava "e-mail
     * atualizado" para B. Além da confusão, era um atalho para quem perdeu o acesso a A:
     * depois que o dono troca a senha e derruba as sessões, bastava entrar na PRÓPRIA conta
     * e abrir o link para concluir a troca pendente de A.
     *
     * Quem abre deslogado cai no login (`auth`) e, ao entrar, volta para o link pelo
     * `url.intended`: entrando com a conta certa, confirma; com outra, recebe o recado.
     */
    public function confirmEmail(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403, 'Este link expirou ou foi alterado.');

        // ANTES de olhar a pendência: a resposta a quem não é o dono não pode variar com o
        // estado da conta dele (tem troca pendente? o link é o mais novo?).
        if (! $request->user()->is($user)) {
            // Nada é gravado em A, nem a pendência é descartada: o dono ainda pode abrir o
            // mesmo link na sessão certa enquanto ele não expira.
            return Redirect::route('profile.edit')->withErrors([
                'confirmacao_email' => 'Este link confirma a troca de e-mail de outra conta, e a conta aberta neste '
                    .'navegador é '.$request->user()->email.'. Nada foi alterado. Para confirmar, saia desta conta '
                    .'e abra o link de novo, entrando com a conta que pediu a troca.',
            ]);
        }

        if (! $user->temEmailPendente() || ! hash_equals(sha1($user->pending_email), (string) $request->query('hash'))) {
            return Redirect::route('profile.edit')->with(
                'status',
                'Este link de confirmação não vale mais: a troca já foi confirmada ou um pedido mais novo tomou o lugar dele.',
            );
        }

        // Alguém pode ter cadastrado esse endereço no intervalo entre o pedido e o clique.
        $emUso = User::where('email', $user->pending_email)->whereKeyNot($user->getKey())->exists();

        if ($emUso) {
            $user->forceFill(['pending_email' => null, 'pending_email_sent_at' => null])->save();

            return Redirect::route('profile.edit')->with(
                'status',
                'A troca não foi concluída: o endereço novo passou a ser usado por outra conta. Seu e-mail continua o mesmo.',
            );
        }

        // Retrato de antes da troca, só para endereçar o aviso ao e-mail antigo.
        $antes = clone $user;

        $user->forceFill([
            'email' => $user->pending_email,
            'pending_email' => null,
            'pending_email_sent_at' => null,
            // O endereço acabou de provar que é dele — é a definição de verificado.
            'email_verified_at' => now(),
        ])->save();

        // A-7: a troca valeu, e o endereço ANTIGO é avisado — depois de gravar, para nunca
        // anunciar uma troca que não aconteceu. Só aqui, e não nos retornos acima: link
        // velho, endereço tomado ou conta errada não trocam nada, então não avisam nada.
        Notificador::avisar($antes, AlertaDeSeguranca::emailAlterado(
            $user,
            $antes->email,
            $user->email,
            ContextoDeSeguranca::doRequest($request),
        ));

        return Redirect::route('profile.edit')->with(
            'status',
            'E-mail confirmado: a partir de agora você entra com '.$user->email.'.',
        );
    }

    /**
     * Exclui a conta do usuário.
     *
     * Consentimento informado, NÃO bloqueio: quando há saldo negativo, fatura
     * em aberto ou conta fixa vencida, o modal lista tudo e a exclusão passa a
     * exigir um segundo aceite explícito, validado aqui no servidor. Sem
     * pendência nenhuma, o fluxo continua o de sempre (só a senha).
     *
     * **Com 2FA ligado, a senha não basta.** Apagar a conta é a ação mais
     * destrutiva do app e é irreversível — não faria sentido exigir o segundo
     * fator para DESLIGAR a proteção (que é reversível) e dispensá-lo para
     * apagar tudo de uma vez, que é o atalho equivalente. Quem sequestrasse uma
     * sessão faria exatamente isso.
     */
    public function destroy(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $user = $request->user();
        $pendencias = self::pendenciasDe($user);
        $comDoisFatores = $user->temDoisFatores();

        $regras = ['password' => ['required', 'current_password']];

        // O checkbox só existe (e só é exigido) quando há o que avisar — senão
        // seria atrito puro para quem não deve nada.
        if ($pendencias['tem']) {
            $regras['confirmo_pendencias'] = ['accepted'];
        }

        if ($comDoisFatores) {
            $regras['codigo'] = ['required', 'string'];
        }

        // A senha é conferida AQUI, antes do código: um código de recuperação é
        // de uso único, e queimá-lo para depois descobrir que a senha estava
        // errada gastaria uma das poucas voltas para casa de quem perdeu o
        // celular.
        $request->validateWithBag('userDeletion', $regras, [
            'confirmo_pendencias.accepted' => 'Confirme que você entendeu que apagar a conta não quita nenhuma das pendências acima.',
            'codigo.required' => 'Digite o código do seu aplicativo autenticador para confirmar.',
        ]);

        if ($comDoisFatores) {
            $codigo = $request->string('codigo')->trim()->toString();

            // O modo é declarado pelo usuário (caixa "usar código de recuperação"),
            // como no desafio do login: adivinhar pelo formato faria um código de
            // recuperação digitado errado ser conferido contra o relógio do TOTP,
            // com a mensagem de erro apontando para o lugar errado.
            $confere = $request->boolean('recuperacao')
                ? $twoFactor->consumirCodigoDeRecuperacao($user, $codigo)
                : $twoFactor->verificarCodigo($user, $codigo);

            if (! $confere) {
                throw ValidationException::withMessages([
                    'codigo' => $request->boolean('recuperacao')
                        ? 'Código de recuperação inválido ou já utilizado.'
                        : 'Código incorreto ou expirado. Confira o relógio do celular e tente com o código atual.',
                ])->errorBag('userDeletion');
            }
        }

        // ANTES do delete, e não depois: em seguida não existe mais nome nem endereço
        // para quem escrever. É também o último aviso que a pessoa recebe — se a exclusão
        // não partiu dela, é a única chance de descobrir.
        Notificador::avisar($user, AlertaDeSeguranca::contaExcluida(
            $user,
            ContextoDeSeguranca::doRequest($request),
        ));

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * O que fica pendente NO MUNDO REAL quando esta conta é apagada.
     *
     * Por que isto não bloqueia (e a spec de 27/07 dizia "bloquear"): travar a
     * exclusão da conta por causa de um número interno de bookkeeping brigaria
     * com o direito de eliminação (LGPD art. 18) que a nossa própria Política
     * de Privacidade promete, e o app não movimenta dinheiro de verdade —
     * apagar a conta aqui não apaga dívida com banco nenhum. Com bloqueio,
     * R$ 0,01 de cheque especial usado trancaria a pessoa na conta para sempre.
     * O que fazemos, então, é CONTAR o que fica para trás e pedir um aceite.
     *
     * Só o titular vê pendência: as contas, faturas e contas fixas pertencem à
     * família e continuam com ele — um dependente que apaga o próprio login não
     * apaga esse dinheiro.
     *
     * Vive aqui, e não num Service, porque é a MESMA regra usada para exibir no
     * modal (`profile/partials/delete-user-form`) e para validar o aceite no
     * `destroy` — uma fonte só. Se um dia crescer, é candidata natural a
     * Service.
     *
     * @return array{
     *     tem: bool,
     *     contasNegativas: list<array{nome: string, valor: float}>,
     *     faturas: list<array{nome: string, valor: float}>,
     *     contasFixas: list<array{nome: string, valor: float, vencimento: CarbonImmutable}>
     * }
     */
    public static function pendenciasDe(User $user): array
    {
        $vazio = ['tem' => false, 'contasNegativas' => [], 'faturas' => [], 'contasFixas' => []];

        if (! $user->isTitular()) {
            return $vazio;
        }

        $contas = Account::where('user_id', $user->id)->orderBy('name')->get();

        // Regra do CLAUDE.md: nunca ler balance/reserved/committed dentro de um
        // laço — 4 queries agregadas no lugar de ~6 por conta.
        Account::preloadMoney($contas);

        $negativas = [];
        $faturas = [];

        foreach ($contas as $conta) {
            // Saldo em conta = `available` (o bruto é detalhe interno). Só faz
            // sentido em conta de caixa: cartão de débito espelha as vinculadas
            // (contaria o mesmo negativo duas vezes) e crédito não é caixa.
            if ($conta->isCash() && $conta->available < -0.001) {
                $negativas[] = ['nome' => $conta->name, 'valor' => $conta->available];
            }

            // `committed` = tudo que o cartão deve e ainda não foi pago,
            // inclusive parcelas futuras já agendadas. É a dívida inteira que
            // sobrevive à exclusão, não só a fatura deste ciclo.
            if ($conta->isCard() && $conta->committed > 0.001) {
                $faturas[] = ['nome' => $conta->name, 'valor' => $conta->committed];
            }
        }

        $contasFixas = app(FixedBillService::class)->overdue($user->id)
            ->map(fn ($ocorrencia) => [
                'nome' => (string) $ocorrencia['bill']->name,
                'valor' => (float) $ocorrencia['valor'],
                'vencimento' => $ocorrencia['vencimento'],
            ])
            ->all();

        return [
            'tem' => $negativas !== [] || $faturas !== [] || $contasFixas !== [],
            'contasNegativas' => $negativas,
            'faturas' => $faturas,
            'contasFixas' => $contasFixas,
        ];
    }
}
