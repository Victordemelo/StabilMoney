{{-- Card "Senha": troca a senha exigindo a senha atual --}}
<div class="card-head">
    <h3>Senha</h3>
    <span class="chip">Segurança</span>
</div>

<p class="mb-4 text-[13px] text-[var(--ink-3)]">
    Use uma senha longa e aleatória para manter sua conta segura.
</p>

<form method="POST" action="{{ route('password.update') }}" class="grid gap-4">
    @csrf
    @method('put')

    {{-- Senha atual --}}
    <div class="field">
        <label for="update_password_current_password">Senha atual</label>
        <input id="update_password_current_password" class="input" type="password" name="current_password"
               autocomplete="current-password" />
        @error('current_password', 'updatePassword')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Nova senha --}}
    <div class="field">
        <label for="update_password_password">Nova senha</label>
        <input id="update_password_password" class="input" type="password" name="password"
               autocomplete="new-password" placeholder="Mínimo de 8 caracteres" />
        @error('password', 'updatePassword')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- Confirmar nova senha --}}
    <div class="field">
        <label for="update_password_password_confirmation">Confirmar nova senha</label>
        <input id="update_password_password_confirmation" class="input" type="password" name="password_confirmation"
               autocomplete="new-password" placeholder="Repita a nova senha" />
        @error('password_confirmation', 'updatePassword')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <button type="submit" class="btn-primary">Salvar</button>
    </div>
</form>
