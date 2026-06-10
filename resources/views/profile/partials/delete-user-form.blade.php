{{-- Card "Excluir conta": ação destrutiva com confirmação por senha em modal --}}
<div class="card-head">
    <h3>Excluir conta</h3>
    <span class="chip">Zona de perigo</span>
</div>

<p class="mb-4 text-[13px] text-[var(--ink-3)]">
    Ao excluir sua conta, todos os dados — contas, categorias e transações — serão apagados
    permanentemente. Antes de continuar, baixe qualquer informação que queira guardar.
</p>

<button type="button" id="open-user-deletion" class="btn-danger">Excluir conta</button>

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
<script>
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
