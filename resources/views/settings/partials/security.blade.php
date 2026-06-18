{{--
    Aba "Segurança" das Configurações.
    Espera: $user (titular/dependente logado) e $sessions (Collection de
    sessões ativas vinda do SettingsController via App\Support\BrowserSessions).
--}}
@php
    $senhaEm = $user->password_changed_at ?? $user->created_at;
@endphp

{{-- Visão geral: e-mail da conta + idade da senha --}}
<div class="sec-overview">
    <div class="sec-ov-item">
        <span class="sec-ov-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg>
        </span>
        <div class="sec-ov-txt">
            <span class="sec-ov-k">E-mail da conta</span>
            <strong class="sec-ov-v" title="{{ $user->email }}">{{ $user->email }}</strong>
        </div>
    </div>
    <div class="sec-ov-item">
        <span class="sec-ov-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 3 5 6v5c0 4.2 2.9 7.7 7 9 4.1-1.3 7-4.8 7-9V6l-7-3Z"/><path d="m9.2 12 1.9 1.9 3.7-3.8"/></svg>
        </span>
        <div class="sec-ov-txt">
            <span class="sec-ov-k">Senha alterada</span>
            <strong class="sec-ov-v">{{ $senhaEm->diffForHumans() }}</strong>
        </div>
    </div>
</div>

{{-- Card "Senha" (troca de senha com medidor de força) --}}
@include('profile.partials.update-password-form')

{{-- Card "Sessões ativas" (dispositivos conectados) --}}
<div class="card sec-card">
    <div class="card-head">
        <h3>Sessões ativas</h3>
        <span class="chip">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
            {{ $sessions->count() ?: 1 }} {{ ($sessions->count() ?: 1) === 1 ? 'dispositivo' : 'dispositivos' }}
        </span>
    </div>
    <p class="sec-card-desc">
        Estes são os navegadores e aparelhos conectados à sua conta. Se algum não for você,
        encerre as outras sessões e troque a senha.
    </p>

    @if (session('status') === 'sessions-cleared')
        <div class="sec-ok" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
            As outras sessões foram encerradas.
        </div>
    @endif

    <ul class="sess-list">
        @forelse ($sessions as $sessao)
            <li class="sess-item {{ $sessao->is_current ? 'is-current' : '' }}">
                <span class="sess-ico">
                    @if ($sessao->device === 'Celular')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/></svg>
                    @else
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
                    @endif
                </span>
                <div class="sess-info">
                    <strong>{{ $sessao->platform }} · {{ $sessao->browser }}</strong>
                    <span>{{ $sessao->ip ?: 'IP desconhecido' }} ·
                        {{ $sessao->is_current ? 'Sessão atual' : 'Ativa ' . $sessao->last_active->diffForHumans() }}</span>
                </div>
                @if ($sessao->is_current)
                    <span class="sess-badge">Atual</span>
                @endif
            </li>
        @empty
            {{-- Sem registro em banco (ex.: ambiente de teste): mostra só a sessão atual --}}
            <li class="sess-item is-current">
                <span class="sess-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>
                </span>
                <div class="sess-info">
                    <strong>Este dispositivo</strong>
                    <span>Sessão atual</span>
                </div>
                <span class="sess-badge">Atual</span>
            </li>
        @endforelse
    </ul>

    {{-- Encerrar outras sessões: revela um campo de senha (confirma a ação) --}}
    <details class="sess-logout" @if ($errors->logoutOtherSessions->isNotEmpty()) open @endif>
        <summary class="sess-logout-trigger">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M15 17l5-5-5-5M20 12H9M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/></svg>
            Encerrar outras sessões
        </summary>
        <form method="POST" action="{{ route('settings.sessions.destroy') }}" class="sess-logout-form">
            @csrf
            @method('delete')
            <p>Digite sua senha para desconectar todos os outros navegadores e dispositivos.</p>
            <div class="field">
                <label for="logout_others_password" class="sr-only">Senha</label>
                <input id="logout_others_password" class="input" type="password" name="password"
                       placeholder="Sua senha" autocomplete="current-password">
                @error('password', 'logoutOtherSessions')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="btn-danger">Encerrar outras sessões</button>
        </form>
    </details>
</div>

{{-- Card "Verificação em duas etapas" (2FA) — em breve --}}
<div class="card sec-card">
    <div class="card-head">
        <h3>Verificação em duas etapas</h3>
        <span class="chip chip-soon">Em breve</span>
    </div>
    <p class="sec-card-desc">
        Uma camada extra de proteção: além da senha, um código do app autenticador
        (Google Authenticator, Authy, etc.) é pedido a cada novo acesso.
    </p>
    <div class="sec-2fa-row">
        <div class="sec-2fa-txt">
            <strong>App autenticador (TOTP)</strong>
            <span>Disponível em uma próxima atualização.</span>
        </div>
        <span class="switch is-off" aria-hidden="true"><span class="switch-dot"></span></span>
    </div>
</div>
