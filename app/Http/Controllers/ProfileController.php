<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Mail\AlertaDeSeguranca;
use App\Mail\ContaDaFamiliaExcluida;
use App\Models\Account;
use App\Models\User;
use App\Services\FixedBillService;
use App\Services\TwoFactorService;
use App\Support\ContextoDeSeguranca;
use App\Support\Mailer;
use App\Support\Notificador;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            //
            // A pendência NÃO é gravada aqui: só depois que o link sair (ver abaixo).
            $user->email = $user->getOriginal('email');
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
            // Grava a nova sem metadados (EXIF/GPS); a antiga só sai depois do save (hook `updated` do User).
            $user->storeAvatar($request->file('avatar'));
        }

        $user->save();

        // Daqui para baixo nome, telefone e foto já estão gravados — saia o link da troca de
        // e-mail ou não. Se ele falhar, a tela precisa dizer que o resto ficou salvo.
        $salvouOResto = $user->wasChanged();

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
            // 🚨 A pendência só existe no banco se o link SAIU. Antes o envio era direto
            // (`Mail::to`, sem proteção): com o SMTP recusando o endereço novo ("550") a
            // tela respondia HTTP 500 e o `pending_email` ficava gravado — uma troca
            // pendente que NENHUM link confirma. Agora o endereço vai só para a memória, o
            // envio passa pelo Notificador, e a pendência é gravada DEPOIS da prova de
            // entrega: se o processo morrer no meio, morre do lado seguro (o mesmo
            // raciocínio do cadastro, em RegisteredUserController::store).
            $user->pending_email = $emailNovo;

            $enviado = Notificador::tentarEnviar(
                $user,
                'link de confirmação da troca de e-mail',
                fn () => $user->sendPendingEmailVerification(),
            );

            if (! $enviado) {
                // Sem link não há pedido: nem este, nem o anterior, se havia. Pedir outra
                // troca sempre invalidou o link do pedido anterior (o `hash` muda), e
                // ressuscitá-lo aqui reabriria justamente o endereço que a pessoa talvez
                // estivesse corrigindo. O estado final é o mais simples de explicar: e-mail
                // de sempre, nenhuma troca em andamento.
                $user->forceFill(['pending_email' => null, 'pending_email_sent_at' => null])->save();

                // Sem o aviso ao endereço atual: ele fala de um pedido, e o pedido não
                // ficou de pé. O endereço digitado volta no campo, para ser conferido.
                return Redirect::route('profile.edit')
                    ->withErrors(['email' => 'Não conseguimos enviar o link de confirmação para '.$emailNovo
                        .', então a troca não foi feita: você continua entrando com '.$antes->email.'. '
                        .'Confira se o endereço está certo e tente de novo; se o erro continuar, fale com '
                        .config('legal.contact_email').'.'
                        .($salvouOResto ? ' As outras alterações do perfil foram salvas.' : '')])
                    ->onlyInput('email');
            }

            $user->forceFill(['pending_email_sent_at' => now()])->save();

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
            // Como erro, no mesmo aviso do caso acima: no `status` ele saía no verde de
            // sucesso, com o ✓, dizendo que o link não funcionou.
            return Redirect::route('profile.edit')->withErrors([
                'confirmacao_email' => 'Este link de confirmação não vale mais: a troca já foi confirmada ou um pedido mais novo tomou o lugar dele.',
            ]);
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
     *
     * **Sendo titular, os dependentes vão junto** (hook `deleting` do User) — o login de
     * cada um some. Por isso o modal os nomeia, a exclusão exige um aceite explícito
     * disso e cada dependente recebe um e-mail depois (item 11 da rodada de 22/09/2026:
     * antes ninguém era avisado, e o dependente descobria ao tentar entrar).
     *
     * **Nada pela metade** (item 12): toda a parte de banco roda numa transação só, e o
     * que não volta atrás — a foto no disco, os e-mails — fica para depois do commit.
     */
    public function destroy(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $user = $request->user();
        $pendencias = self::pendenciasDe($user);
        $dependentes = self::dependentesQuePerdemOAcesso($user);
        $comDoisFatores = $user->temDoisFatores();

        $regras = ['password' => ['required', 'current_password']];

        // O checkbox só existe (e só é exigido) quando há o que avisar — senão
        // seria atrito puro para quem não deve nada.
        if ($pendencias['tem']) {
            $regras['confirmo_pendencias'] = ['accepted'];
        }

        // Mesmo padrão: só existe quando há quem perca o acesso junto.
        if ($dependentes->isNotEmpty()) {
            $regras['confirmo_dependentes'] = ['accepted'];
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
            'confirmo_dependentes.accepted' => 'Confirme que você entendeu que '
                .$dependentes->pluck('name')->join(', ', ' e ')
                .($dependentes->count() === 1 ? ' perde' : ' perdem')
                .' o acesso junto com a sua conta.',
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

        // Os avisos são MONTADOS antes do delete — depois dele não há mais linha de onde
        // tirar nome e endereço —, mas só SAEM depois do commit. Antes o do titular saía
        // antes do delete: uma falha no meio mandava "sua conta foi excluída" para quem
        // continuava com a conta de pé.
        $contexto = ContextoDeSeguranca::doRequest($request);

        // O aviso ao titular NOMEIA quem perdeu o acesso junto: o modal mostrou os nomes, mas
        // o e-mail é o que fica — e, se a exclusão não partiu dele, é por ele que o dono
        // descobre que a família inteira ficou sem login.
        $avisos = [[$user, AlertaDeSeguranca::contaExcluida($user, $contexto, $dependentes->pluck('name')->all())]];

        foreach ($dependentes as $dependente) {
            $avisos[] = [$dependente, new ContaDaFamiliaExcluida($dependente->name, $user->name, $contexto->quando)];
        }

        // O hook `deleting` do User apaga os dependentes um a um e as linhas de `sessions`:
        // são várias escritas, e antes uma falha no meio (erro de banco no 2º dependente)
        // deixava a família pela metade. Numa transação, ou tudo sai, ou nada sai.
        //
        // Sem `attempts`: repetir o closure reusaria models que a tentativa desfeita já
        // marcou como apagados (`exists = false`), e o `delete()` deles viraria no-op.
        try {
            DB::transaction(fn () => $user->delete());
        } catch (\Throwable $e) {
            report($e);

            // A pessoa continua logada (o logout só vem depois do commit) e a mensagem pode
            // dizer "nada foi apagado" porque é verdade: o banco desfez tudo, e o que não
            // volta atrás nem chegou a acontecer.
            throw ValidationException::withMessages([
                'exclusao' => 'Não conseguimos excluir a sua conta agora, e nada foi apagado. Tente de novo em instantes.',
            ])->errorBag('userDeletion');
        }

        // `logoutCurrentDevice`, e não `logout`: o `logout()` recicla o remember token com
        // um `save()` — e `save()` num model apagado é um INSERT, que traria a conta de
        // volta. Aqui não há token a reciclar: a linha que o guardava já não existe.
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // É o último aviso que o titular recebe — se a exclusão não partiu dele, é a única
        // chance de descobrir. E cada dependente fica sabendo por que o login dele sumiu.
        foreach ($avisos as [$destinatario, $email]) {
            Notificador::avisar($destinatario, $email);
        }

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
     * modal (`profile/partials/delete-user-modal`) e para validar o aceite no
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

    /**
     * Quem perde o acesso junto quando ESTA conta é excluída.
     *
     * Excluir o titular apaga cada dependente (hook `deleting` do User): o login, a foto e
     * as sessões. Antes o modal só listava as pendências financeiras, então o titular
     * apagava o acesso da família sem ver nomes — e os dependentes só descobriam ao tentar
     * entrar.
     *
     * Mesma lógica de `pendenciasDe`: UMA fonte para o modal (que nomeia as pessoas e
     * mostra o aceite) e para o `destroy` (que exige o aceite e avisa cada um). Dependente
     * apagando o próprio login não leva ninguém junto: lista vazia, e nada muda para ele.
     *
     * @return EloquentCollection<int, User>
     */
    public static function dependentesQuePerdemOAcesso(User $user): EloquentCollection
    {
        if (! $user->isTitular()) {
            return new EloquentCollection;
        }

        return $user->dependents()->orderBy('name')->get();
    }
}
