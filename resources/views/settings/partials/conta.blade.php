{{--
    Aba "Conta" — Configurações › Conta.

    Dois cards lado a lado: o resumo do que ESTA conta é (à esquerda) e a zona de
    perigo (à direita). Antes a exclusão era a única coisa na tela, o que deixava
    um card destrutivo sozinho no meio do branco — e nada explicando o que se está
    prestes a apagar.

    Espera: $user (SettingsController).
--}}
@php
    $titular = $user->isTitular();
    $dependentes = $titular ? $user->dependents()->count() : 0;
@endphp

<div class="card sec-card span6">
    <div class="card-head">
        <h3>Sua conta</h3>
        <span class="chip">{{ $titular ? 'Titular' : 'Dependente' }}</span>
    </div>

    <p class="sec-card-desc">
        Os dados pessoais (nome, foto e telefone) ficam em
        <a href="{{ route('profile.edit') }}">Meu perfil</a>.
    </p>

    <ul class="conta-resumo">
        <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="m3 7 9 6 9-6"/></svg>
            <div>
                <strong>{{ $user->email }}</strong>
                <span>É por este e-mail que você entra e recupera o acesso.</span>
            </div>
        </li>

        <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="5" width="18" height="16" rx="2.5"/><path d="M8 3v4M16 3v4M3 11h18"/></svg>
            <div>
                <strong>No app desde {{ $user->created_at->translatedFormat('j \d\e F \d\e Y') }}</strong>
                <span>{{ $user->created_at->diffForHumans(null, true) }} de histórico financeiro guardado.</span>
            </div>
        </li>

        @if ($titular)
            <li>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M17 6.2a3.2 3.2 0 0 1 0 6M18.5 20a6.4 6.4 0 0 0-2-4.6"/></svg>
                <div>
                    <strong>
                        @if ($dependentes === 0)
                            Nenhum dependente
                        @else
                            {{ $dependentes }} {{ $dependentes === 1 ? 'dependente' : 'dependentes' }}
                        @endif
                    </strong>
                    <span>
                        @if ($dependentes === 0)
                            Você pode dar acesso a alguém da família em
                            <a href="{{ route('dependentes') }}">Dependentes</a>.
                        @else
                            Compartilham contas, categorias e lançamentos com você.
                            <a href="{{ route('dependentes') }}">Gerenciar</a>.
                        @endif
                    </span>
                </div>
            </li>
        @else
            <li>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="9" cy="8" r="3.2"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M17 6.2a3.2 3.2 0 0 1 0 6M18.5 20a6.4 6.4 0 0 0-2-4.6"/></svg>
                <div>
                    <strong>Dependente de {{ $user->titular?->name }}</strong>
                    <span>Você compartilha contas, categorias e lançamentos com a família.
                        Apagar o seu login não apaga os dados da família.</span>
                </div>
            </li>
        @endif

        <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 3 5 6v5c0 4.2 2.9 7.7 7 9 4.1-1.3 7-4.8 7-9V6l-7-3Z"/><path d="m9.2 12 1.9 1.9 3.7-3.8"/></svg>
            <div>
                <strong>{{ $user->temDoisFatores() ? 'Verificação em duas etapas ativa' : 'Verificação em duas etapas desativada' }}</strong>
                <span>
                    @if ($user->temDoisFatores())
                        Além da senha, o login pede o código do seu autenticador.
                    @else
                        Hoje sua conta é protegida apenas pela senha.
                    @endif
                    <a href="{{ route('settings', '2fa') }}">{{ $user->temDoisFatores() ? 'Gerenciar' : 'Ativar' }}</a>.
                </span>
            </div>
        </li>
    </ul>

    {{-- Lembrete de vencimento por e-mail (comando `lembretes:vencimentos`). A linha
         inteira É o botão: um clique inverte a preferência (hidden leva o valor
         oposto ao atual). Não pede senha — é preferência, não proteção. Só o titular
         recebe, então só ele vê o interruptor. --}}
    @if ($titular)
        <form method="POST" action="{{ route('settings.lembretes') }}" class="lembrete-form">
            @csrf
            @method('PATCH')
            <input type="hidden" name="reminder_emails" value="{{ $user->reminder_emails ? 0 : 1 }}">
            <button type="submit" class="sec-2fa-row lembrete-toggle" role="switch"
                    aria-checked="{{ $user->reminder_emails ? 'true' : 'false' }}">
                <div class="sec-2fa-txt">
                    <strong>Lembretes de vencimento por e-mail</strong>
                    <span>
                        @if ($user->reminder_emails)
                            Aviso às 8h quando uma fatura ou conta fixa vence em 3 dias, amanhã, hoje — e a cada 7 dias de atraso.
                        @else
                            Desligados. Faturas e contas fixas só avisam no sino do aplicativo.
                        @endif
                    </span>
                </div>
                <span class="switch {{ $user->reminder_emails ? 'is-on' : 'is-off' }}" aria-hidden="true"><span class="switch-dot"></span></span>
            </button>
        </form>
    @else
        <div class="sec-2fa-row lembrete-form">
            <div class="sec-2fa-txt">
                <strong>Lembretes de vencimento por e-mail</strong>
                <span>Vão para o titular ({{ $user->titular?->name }}), que responde pelas contas da família.</span>
            </div>
        </div>
    @endif
</div>

<div class="card sec-card span6">
    @include('profile.partials.delete-user-form')
</div>

{{-- FORA do card: a `.card` tem overflow:hidden + animação com transform, e isso
     prende um `position: fixed` filho. Ver o cabeçalho do partial. --}}
@include('profile.partials.delete-user-modal')
