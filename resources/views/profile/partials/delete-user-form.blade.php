@php
    // Consentimento informado: o que fica pendente no mundo real quando a conta
    // some. A MESMA regra roda no servidor (ProfileController::pendenciasDe,
    // que exige o aceite quando `tem` é true) — aqui é só a exibição.
    $pendencias = \App\Http\Controllers\ProfileController::pendenciasDe($user ?? auth()->user());
@endphp

{{-- Card "Excluir conta": ação destrutiva com confirmação por senha em modal --}}
<div class="card-head">
    <h3>Excluir conta</h3>
    <span class="chip">Zona de perigo</span>
</div>

<p class="sec-card-desc">
    Isto não tem volta. Antes de continuar, salve o que quiser guardar — depois de
    confirmar, não há como recuperar.
</p>

{{-- O que some, item a item. Antes era uma frase corrida com tudo enfileirado; a
     lista deixa o tamanho do estrago visível de relance. --}}
<ul class="conta-resumo perigo">
    <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19"/></svg>
        <div><strong>Contas e cartões</strong><span>Saldos, limites e faturas.</span></div>
    </li>
    <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
        <div><strong>Todo o histórico</strong><span>Lançamentos, categorias, metas e investimentos.</span></div>
    </li>
    <li>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M17 6.2a3.2 3.2 0 0 1 0 6M18.5 20a6.4 6.4 0 0 0-2-4.6"/></svg>
        <div><strong>Os dependentes</strong><span>O login e os dados de quem você cadastrou.</span></div>
    </li>
</ul>

<div class="conta-perigo-acao">
    <button type="button" id="open-user-deletion" class="btn-danger">Excluir minha conta</button>
</div>

{{-- Modal de confirmação (véu .scrim + card do design system) --}}
<div id="confirm-user-deletion"
     class="fixed inset-0 z-[60] {{ $errors->userDeletion->isNotEmpty() ? 'grid' : 'hidden' }} place-items-center p-4"
     role="dialog" aria-modal="true" aria-labelledby="confirm-user-deletion-title">

    {{-- Véu escurecido: clicar fora fecha o modal --}}
    <div class="scrim show" data-close-deletion></div>

    <div class="card relative z-[40] w-full max-w-[440px]">
        <h3 id="confirm-user-deletion-title"
            class="text-[16px] font-bold [font-family:var(--font-head)] tracking-[-0.01em]">
            Tem certeza que deseja excluir sua conta?
        </h3>

        <p class="mt-2 mb-4 text-[13px] text-[var(--ink-3)]">
            Esta ação não pode ser desfeita. Digite sua senha para confirmar que você
            realmente deseja excluir a conta permanentemente.
        </p>

        <form method="POST" action="{{ route('profile.destroy') }}" class="grid gap-4">
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

            <div class="flex items-center justify-end gap-3">
                <button type="button" data-close-deletion
                        class="inline-flex h-[42px] items-center rounded-[13px] border border-[var(--line)] bg-[var(--surface-2)] px-[18px] text-[13.5px] font-bold text-[var(--ink-2)] transition hover:bg-[var(--surface-3)]">
                    Cancelar
                </button>
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
            modal.classList.remove('hidden');
            modal.classList.add('grid');
            var senha = document.getElementById('delete_confirm_password');
            if (senha) senha.focus();
        }

        function fechar() {
            modal.classList.add('hidden');
            modal.classList.remove('grid');
        }

        openBtn.addEventListener('click', abrir);
        modal.querySelectorAll('[data-close-deletion]').forEach(function (el) {
            el.addEventListener('click', fechar);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) fechar();
        });

        // Reabre automaticamente quando a validação da senha falhou (classe "grid" já vem do Blade)
        if (modal.classList.contains('grid')) {
            var senha = document.getElementById('delete_confirm_password');
            if (senha) senha.focus();
        }
    })();
</script>
