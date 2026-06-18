{{-- Card "Senha": troca a senha exigindo a senha atual, com medidor de força --}}
<div class="card sec-card">
    <div class="card-head">
        <h3>Senha</h3>
        <span class="chip">Segurança</span>
    </div>

    <p class="sec-card-desc">
        Use uma senha longa e única. Trocar a senha não desconecta seus aparelhos —
        para isso, use "Encerrar outras sessões" abaixo.
    </p>

    @if (session('status') === 'password-updated')
        <div class="sec-ok" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
            Senha atualizada com sucesso.
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="sec-form" id="passwordForm">
        @csrf
        @method('put')

        {{-- Senha atual --}}
        <div class="field">
            <label for="current_password">Senha atual</label>
            <div class="input-pw">
                <input id="current_password" class="input" type="password" name="current_password"
                       autocomplete="current-password" />
                <button type="button" class="pw-toggle" data-toggle="current_password" aria-label="Mostrar senha">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            @error('current_password', 'updatePassword')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Nova senha --}}
        <div class="field">
            <label for="new_password">Nova senha</label>
            <div class="input-pw">
                <input id="new_password" class="input" type="password" name="password"
                       autocomplete="new-password" placeholder="Mínimo de 8 caracteres" />
                <button type="button" class="pw-toggle" data-toggle="new_password" aria-label="Mostrar senha">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>

            {{-- Medidor de força (preenchido por security.js) --}}
            <div class="pw-strength" data-strength hidden>
                <div class="pw-bars"><span></span><span></span><span></span><span></span></div>
                <span class="pw-strength-label"></span>
            </div>

            {{-- Sugestões para uma senha forte (marcadas ao vivo) --}}
            <ul class="pw-hints" data-hints hidden>
                <li data-rule="len">Pelo menos 8 caracteres</li>
                <li data-rule="case">Maiúsculas e minúsculas</li>
                <li data-rule="num">Um número</li>
                <li data-rule="sym">Um símbolo (!@#$…)</li>
            </ul>

            @error('password', 'updatePassword')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        {{-- Confirmar nova senha --}}
        <div class="field">
            <label for="new_password_confirmation">Confirmar nova senha</label>
            <div class="input-pw">
                <input id="new_password_confirmation" class="input" type="password" name="password_confirmation"
                       autocomplete="new-password" placeholder="Repita a nova senha" />
                <button type="button" class="pw-toggle" data-toggle="new_password_confirmation" aria-label="Mostrar senha">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
            <p class="pw-match" data-match hidden></p>
            @error('password_confirmation', 'updatePassword')
                <p class="field-error">{{ $message }}</p>
            @enderror
        </div>

        <div class="sec-actions">
            <button type="submit" class="btn-primary">Atualizar senha</button>
        </div>
    </form>
</div>
