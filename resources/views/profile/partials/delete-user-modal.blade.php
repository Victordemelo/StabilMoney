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
    // Consentimento informado: o que fica pendente no mundo real quando a conta
    // some. A MESMA regra roda no servidor (ProfileController::pendenciasDe,
    // que exige o aceite quando `tem` é true) — aqui é só a exibição.
    $pendencias = \App\Http\Controllers\ProfileController::pendenciasDe($user ?? auth()->user());
@endphp

{{-- O próprio scrim é o véu e o clique-fora: não há div extra. --}}
<div id="confirm-user-deletion"
     class="modal-scrim {{ $errors->userDeletion->isNotEmpty() ? 'open' : '' }}"
     role="dialog" aria-modal="true" aria-labelledby="confirm-user-deletion-title">

    <div class="modal">
        <div class="modal-head">
            <div class="modal-ico perigo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 9v4.5M12 17h.01"/><path d="M10.3 3.9 2.4 17.1A2 2 0 0 0 4.1 20h15.8a2 2 0 0 0 1.7-2.9L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
            </div>
            <div>
                <h3 id="confirm-user-deletion-title">Excluir sua conta?</h3>
                <p>Isto não pode ser desfeito. Digite sua senha para confirmar.</p>
            </div>
            <button type="button" class="modal-x" data-close-deletion aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <form method="POST" action="{{ route('profile.destroy') }}" class="modal-body">
            @csrf
            @method('delete')

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

            {{-- Senha de confirmação --}}
            <div class="field">
                <label for="delete_confirm_password" class="sr-only">Senha</label>
                <input id="delete_confirm_password" class="input" type="password" name="password"
                       placeholder="Sua senha" autocomplete="current-password" />
                @error('password', 'userDeletion')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            <div class="modal-foot">
                <button type="button" class="btn ghost" data-close-deletion>Cancelar</button>
                <button type="submit" class="btn-danger">Excluir conta</button>
            </div>
        </form>
    </div>
</div>

{{-- JS vanilla mínimo do modal (abre, fecha por botão/véu/Esc e foca a senha) --}}
<script nonce="{{ Vite::cspNonce() }}">
    (function () {
        var modal = document.getElementById('confirm-user-deletion');
        var openBtn = document.getElementById('open-user-deletion');
        if (!modal || !openBtn) return;

        function abrir() {
            modal.classList.add('open');
            var senha = document.getElementById('delete_confirm_password');
            if (senha) senha.focus();
        }

        function fechar() { modal.classList.remove('open'); }

        openBtn.addEventListener('click', abrir);
        // Clique no próprio scrim (fora do .modal) fecha, como nos demais modais.
        modal.addEventListener('click', function (e) { if (e.target === modal) fechar(); });
        modal.querySelectorAll('[data-close-deletion]').forEach(function (el) {
            el.addEventListener('click', fechar);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) fechar();
        });

        // Já veio aberto porque a validação falhou: leva o foco para a senha.
        if (modal.classList.contains('open')) {
            var senha = document.getElementById('delete_confirm_password');
            if (senha) senha.focus();
        }
    })();
</script>
