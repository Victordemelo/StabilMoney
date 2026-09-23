{{--
    Modal de confirmação de exclusão da conta.

    ⚠️ MORA FORA DA `.card` DE PROPÓSITO (ver CLAUDE.md — a mesma armadilha já
    tinha derrubado os modais de dependentes): `.card` tem `overflow: hidden`, e
    transform em ancestral vira o BLOCO DE CONTENÇÃO de um `position: fixed`.

    Usa os primitivos do design system (`.modal-scrim` + `.modal`), como o modal
    de lançar e o de escolha de fonte. A versão anterior montava o modal com
    utilitários Tailwind arbitrários (`w-full max-w-[440px]` num grid
    `place-items-center`): a largura percentual era cíclica dentro de uma coluna
    dimensionada por conteúdo, então o painel colapsava para MIN-CONTENT — ~100px,
    o tamanho da palavra mais longa, com o texto saindo uma palavra por linha.

    Quem inclui: settings/partials/conta.blade.php, como IRMÃO do card.
--}}
@php
    $dono = $user ?? auth()->user();

    // Consentimento informado: o que fica pendente no mundo real quando a conta
    // some. A MESMA regra roda no servidor (ProfileController::pendenciasDe,
    // que exige o aceite quando `tem` é true) — aqui é só a exibição.
    $pendencias = \App\Http\Controllers\ProfileController::pendenciasDe($dono);

    // Quem perde o acesso junto (só existe para o titular). Mesma fonte do servidor,
    // que exige o aceite `confirmo_dependentes` quando a lista não está vazia.
    $dependentes = \App\Http\Controllers\ProfileController::dependentesQuePerdemOAcesso($dono);

    // Com 2FA ligado a senha não basta (ver ProfileController::destroy).
    $comDoisFatores = $dono->temDoisFatores();
@endphp

{{-- O próprio scrim é o véu e o clique-fora: não há div extra. O DIÁLOGO é o painel
     (`.modal`), como em todos os modais do app — o papel estava no véu, e o leitor de
     tela anunciava a tela inteira como a caixa do diálogo. --}}
<div id="confirm-user-deletion"
     class="modal-scrim {{ $errors->userDeletion->isNotEmpty() ? 'open' : '' }}">

    <div class="modal" role="dialog" aria-modal="true"
         aria-labelledby="confirm-user-deletion-title" aria-describedby="confirm-user-deletion-desc">
        <div class="modal-head">
            <div class="modal-ico perigo" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 9v4.5M12 17h.01"/><path d="M10.3 3.9 2.4 17.1A2 2 0 0 0 4.1 20h15.8a2 2 0 0 0 1.7-2.9L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
            </div>
            <div>
                <h3 id="confirm-user-deletion-title">Excluir sua conta?</h3>
                <p id="confirm-user-deletion-desc">
                    Isto não pode ser desfeito. Digite sua senha
                    @if ($comDoisFatores) e o código do seu aplicativo @endif
                    para confirmar.
                </p>
            </div>
            <button type="button" class="modal-x" data-close-deletion aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <form method="POST" action="{{ route('profile.destroy') }}">
            <div class="modal-body">
            @csrf
            @method('delete')

            {{-- A exclusão falhou no servidor (erro de banco no meio do caminho). A
                 transação desfez tudo, então a mensagem pode prometer que nada foi
                 apagado — ver ProfileController::destroy. --}}
            @error('exclusao', 'userDeletion')
                <div class="flash-error" style="margin-bottom: 0;" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                    <div>{{ $message }}</div>
                </div>
            @enderror

            {{-- Pendências financeiras: aparecem ANTES da senha, porque é o que
                 pode fazer a pessoa desistir. Não bloqueiam a exclusão (ver o
                 porquê em ProfileController::pendenciasDe) — mas passam a exigir
                 um segundo aceite, validado no servidor. --}}
            @if ($pendencias['tem'])
                <div class="flash-error" style="margin-bottom: 0;" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor">
                        <path d="M12 9v4.5M12 17h.01"/><path d="M10.3 3.9 2.4 17.1A2 2 0 0 0 4.1 20h15.8a2 2 0 0 0 1.7-2.9L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                    </svg>
                    <div>
                        <strong>Estas pendências continuam existindo depois da exclusão:</strong>
                        <ul style="margin-top: 6px;">
                            @foreach ($pendencias['contasNegativas'] as $conta)
                                <li>{{ $conta['nome'] }}: saldo em conta de @brl($conta['valor'])</li>
                            @endforeach
                            @foreach ($pendencias['faturas'] as $cartao)
                                <li>{{ $cartao['nome'] }}: @brl($cartao['valor']) de fatura em aberto</li>
                            @endforeach
                            @foreach ($pendencias['contasFixas'] as $fixa)
                                <li>{{ $fixa['nome'] }}: @brl($fixa['valor']) vencidos em {{ $fixa['vencimento']->format('d/m/Y') }}</li>
                            @endforeach
                        </ul>
                        <p style="margin-top: 8px; font-weight: 500;">
                            Apagar a conta apaga só os seus registros aqui. O que você deve ao
                            banco, ao cartão ou a quem cobra a conta fixa continua igual.
                        </p>
                    </div>
                </div>

                <label class="check" style="align-items: flex-start;">
                    <input type="checkbox" id="confirmo_pendencias" name="confirmo_pendencias" value="1" required />
                    <span class="box" style="margin-top: 1px;"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
                    <span>Entendi que apagar minha conta não quita nada disso.</span>
                </label>
                @error('confirmo_pendencias', 'userDeletion')
                    <p class="field-error" style="margin-top: -10px;">{{ $message }}</p>
                @enderror
            @endif

            {{-- Dependentes: excluir o titular apaga o login de cada um (hook `deleting`
                 do User). Antes o modal não dizia isso, e a família inteira perdia o
                 acesso sem o titular ver um nome sequer. Nomes e e-mails são do usuário:
                 sempre {{ }}, escapados. O aviso por e-mail só é prometido quando o app
                 consegue enviar — sem mailer, a tela diz a verdade e passa a tarefa. --}}
            @if ($dependentes->isNotEmpty())
                <div class="flash-error" style="margin-bottom: 0;" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M17 6.2a3.2 3.2 0 0 1 0 6M18.5 20a6.4 6.4 0 0 0-2-4.6"/></svg>
                    <div>
                        <strong>
                            {{ $dependentes->count() === 1
                                ? 'Esta pessoa perde o acesso junto com a sua conta:'
                                : 'Estas '.$dependentes->count().' pessoas perdem o acesso junto com a sua conta:' }}
                        </strong>
                        <ul style="margin-top: 6px;">
                            @foreach ($dependentes as $dependente)
                                <li>{{ $dependente->name }} ({{ $dependente->email }})</li>
                            @endforeach
                        </ul>
                        <p style="margin-top: 8px; font-weight: 500;">
                            O login, a foto e os dados pessoais de cada dependente são apagados, sem volta.
                            @if (\App\Support\Mailer::entrega())
                                Vamos avisar cada um por e-mail.
                            @else
                                O app não está enviando e-mails agora: avise cada um você mesmo.
                            @endif
                        </p>
                    </div>
                </div>

                <label class="check" style="align-items: flex-start;">
                    <input type="checkbox" id="confirmo_dependentes" name="confirmo_dependentes" value="1" required />
                    <span class="box" style="margin-top: 1px;"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
                    <span>
                        {{ $dependentes->count() === 1
                            ? 'Entendi que esse dependente perde o acesso e os dados junto com a minha conta.'
                            : 'Entendi que esses dependentes perdem o acesso e os dados junto com a minha conta.' }}
                    </span>
                </label>
                @error('confirmo_dependentes', 'userDeletion')
                    <p class="field-error" style="margin-top: -10px;">{{ $message }}</p>
                @enderror
            @endif

            {{-- Senha de confirmação --}}
            <div class="field">
                <label for="delete_confirm_password" class="sr-only">Senha</label>
                <input id="delete_confirm_password" class="input" type="password" name="password"
                       placeholder="Sua senha" autocomplete="current-password" />
                @error('password', 'userDeletion')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Segundo fator: só aparece para quem ligou o 2FA. A verificação em
                 duas etapas some junto com a conta, e é isso que o aviso diz — quem
                 quer apenas trocar de aparelho deve desligá-la em Configurações,
                 não apagar a conta. --}}
            @if ($comDoisFatores)
                <div class="field">
                    <label for="delete_confirm_code" class="sr-only">Código de verificação</label>
                    <input id="delete_confirm_code" class="input" type="text" name="codigo"
                           placeholder="Código de 6 dígitos" inputmode="numeric"
                           autocomplete="one-time-code" autocorrect="off" spellcheck="false"
                           data-no-money />
                    @error('codigo', 'userDeletion')
                        <p class="field-error">{{ $message }}</p>
                    @enderror

                    <label class="check" style="margin-top: 10px;">
                        <input type="checkbox" name="recuperacao" value="1" />
                        <span class="box"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
                        <span>Não consigo abrir o aplicativo — vou usar um código de recuperação</span>
                    </label>

                    {{-- Tamanho pelo estilo inline, e não por classe nova: `.hint` só
                         define peso e cor, e `forms.css` está com trabalho de outra
                         sessão pendente (regra de ouro do CLAUDE.md). --}}
                    <p class="hint" style="margin-top: 8px; font-size: 12.5px; line-height: 1.45;">
                        Sua verificação em duas etapas será apagada junto com a conta. Para
                        só trocar de aparelho, desative-a em Configurações › 2FA.
                    </p>
                </div>
            @endif

            </div>{{-- /.modal-body — o rodapé é IRMÃO dele, para ficar preso ao
                 fundo do modal em vez de rolar junto com o conteúdo. --}}

            <div class="modal-foot">
                <button type="button" class="btn ghost" data-close-deletion>Cancelar</button>
                <button type="submit" class="btn-danger">Excluir conta</button>
            </div>
        </form>
    </div>
</div>

{{-- JS vanilla mínimo do modal: abre (foco na senha), fecha por botão/véu. Abrir e
     fechar passam pelo utilitário de diálogo (sm/dialogo.js, pela ponte
     `window.smDialogo`): Tab preso, Esc, resto da página inerte e o foco de volta ao
     botão "Excluir minha conta". --}}
<script nonce="{{ Vite::cspNonce() }}">
    (function () {
        var modal = document.getElementById('confirm-user-deletion');
        var openBtn = document.getElementById('open-user-deletion');
        if (!modal || !openBtn) return;

        var senha = function () { return document.getElementById('delete_confirm_password'); };

        // A ponte é lida na HORA: no primeiro carregamento este script roda antes do
        // módulo. Sem ela (módulo que não carregou), abre e fecha pela classe, como antes.
        function abrir() {
            if (window.smDialogo) {
                window.smDialogo.abrir(modal, { foco: senha(), retorno: openBtn });
                return;
            }
            modal.classList.add('open');
            if (senha()) senha().focus();
        }

        function fechar() {
            if (window.smDialogo) window.smDialogo.fechar(modal);
            else modal.classList.remove('open');
        }

        openBtn.addEventListener('click', abrir);
        // Clique no próprio scrim (fora do .modal) fecha, como nos demais modais.
        modal.addEventListener('click', function (e) { if (e.target === modal) fechar(); });
        modal.querySelectorAll('[data-close-deletion]').forEach(function (el) {
            el.addEventListener('click', fechar);
        });

        // Já veio aberto porque a validação falhou: vira diálogo (foco na senha) assim
        // que o módulo existir — ele é avaliado antes do DOMContentLoaded.
        if (modal.classList.contains('open')) {
            if (window.smDialogo || document.readyState !== 'loading') abrir();
            else document.addEventListener('DOMContentLoaded', abrir, { once: true });
        }
    })();
</script>
