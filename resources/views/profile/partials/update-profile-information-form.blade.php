{{-- Card "Perfil": atualiza nome e e-mail do usuário logado --}}
<div class="card-head">
    <h3>Perfil</h3>
    <span class="chip">Informações da conta</span>
</div>

<form method="POST" action="{{ route('profile.update') }}" class="grid gap-4 sm:max-w-[520px]">
    @csrf
    @method('patch')

    {{-- Nome --}}
    <div class="field">
        <label for="name">Nome</label>
        <input id="name" class="input" type="text" name="name" value="{{ old('name', $user->name) }}"
               required autocomplete="name" />
        @error('name')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>

    {{-- E-mail --}}
    <div class="field">
        <label for="email">E-mail</label>
        <input id="email" class="input" type="email" name="email" value="{{ old('email', $user->email) }}"
               required autocomplete="email" inputmode="email" />
        @error('email')
            <p class="field-error">{{ $message }}</p>
        @enderror

        {{-- Aviso de e-mail não verificado (só quando a verificação estiver ativa no model) --}}
        @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
            <p class="mt-2 text-[13px] text-[var(--ink-2)]">
                Seu e-mail ainda não foi verificado.
                <button form="send-verification" type="submit"
                        class="font-semibold text-[var(--brand-600)] hover:underline [[data-theme=dark]_&]:text-[var(--brand-300)]">
                    Clique aqui para reenviar o e-mail de verificação.
                </button>
            </p>
        @endif
    </div>

    {{-- Confirmação de senha: exigida pelo servidor SÓ quando o e-mail muda (o e-mail é
         o que recupera a conta, então trocá-lo sem senha permitiria tomada de conta a
         partir de uma sessão sequestrada). Fica sempre visível de propósito — revelar por
         JS deixaria quem não tem JS sem conseguir trocar o e-mail. --}}
    <div class="field">
        <label for="current_password">Senha atual</label>
        <input id="current_password" class="input" type="password" name="current_password"
               autocomplete="current-password" />
        <span class="field-hint">Necessária apenas se você alterar o e-mail.</span>
        @error('current_password')
            <p class="field-error">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <button type="submit" class="btn-primary">Salvar</button>
    </div>
</form>

@if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
    {{-- Form auxiliar para reenviar o link de verificação (fora do form principal) --}}
    <form id="send-verification" method="POST" action="{{ route('verification.send') }}">
        @csrf
    </form>
@endif
