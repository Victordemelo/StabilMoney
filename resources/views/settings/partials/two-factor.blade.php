{{--
    Aba "Verificação em 2 etapas" (2FA por app autenticador) — Configurações › 2FA.

    Dois cards lado a lado: o da ESQUERDA é onde se age (status + ligar/confirmar/
    desligar) e o da DIREITA explica o recurso e o que são os códigos de recuperação.
    Separar assim tira da frente o texto que só se lê uma vez e deixa a página caber
    sem rolagem — quando tudo era um card só, a explicação empurrava a ação para baixo.

    Espera: $user, $qrCode e $chaveManual (só preenchidos durante a configuração) e
    $semRecuperacaoPorEmail (SettingsController).

    O recurso é OPCIONAL: nasce desligado e só liga por decisão do dono da conta. O card
    tem três estados, e a ordem em que aparecem aqui é a ordem em que a pessoa os vive:
    configurando → ativado → desligar.
--}}
@php
    $ativo = $user->temDoisFatores();
    $configurando = $user->doisFatoresPendente();
    // Lista recém-criada, vinda do flash: aparece UMA vez, logo depois de confirmar
    // (ou de gerar novos códigos).
    $codigosNovos = session('codigosDeRecuperacao');
@endphp

<div class="card sec-card span7" id="verificacao-duas-etapas">
    <div class="card-head">
        <h3>Verificação em duas etapas</h3>
        <span class="chip {{ $ativo ? 'chip-ativo' : '' }}">
            @if ($ativo)
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
                Ativada
            @elseif ($configurando)
                Configurando
            @else
                Desativada
            @endif
        </span>
    </div>

    {{-- Avisos de resultado --}}
    @if (session('status') === 'two-factor-disabled')
        <div class="sec-ok" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
            A verificação em duas etapas foi desativada. Agora só a senha é pedida no login.
        </div>
    @elseif (session('status') === 'two-factor-cancelled')
        <div class="sec-ok" role="status">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 5-5.5"/></svg>
            Configuração cancelada. Nada mudou na sua conta.
        </div>
    @endif

    @error('two_factor', 'twoFactor')
        <div class="flash-error" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
            <span>{{ $message }}</span>
        </div>
    @enderror

    {{-- ============ Lista de códigos de recuperação (aparece uma única vez) ============ --}}
    @if ($codigosNovos)
        <div class="tfa-codigos" role="status">
            <div class="tfa-codigos-head">
                <div>
                    <strong>Guarde seus códigos de recuperação</strong>
                    <span>
                        Cada código serve <b>uma vez</b> e entra no lugar do celular.
                        @if ($semRecuperacaoPorEmail)
                            Como o aplicativo ainda não envia e-mails, <b>esta é a única forma
                            de voltar</b> se você perder o aparelho.
                        @endif
                        Esta lista não aparece de novo.
                    </span>
                </div>
                <button type="button" class="btn-ghost" data-copiar-codigos hidden>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="9" y="9" width="12" height="12" rx="2.5"/><path d="M15 5.5A2.5 2.5 0 0 0 12.5 3H5.5A2.5 2.5 0 0 0 3 5.5v7A2.5 2.5 0 0 0 5.5 15"/></svg>
                    <span data-copiar-rotulo>Copiar</span>
                </button>
            </div>
            <ul class="tfa-codigos-lista">
                @foreach ($codigosNovos as $codigo)
                    <li>{{ $codigo }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ==================== ESTADO 1: configuração em andamento ==================== --}}
    @if ($configurando)
        <ol class="tfa-passos">
            <li>
                <div class="tfa-passo-txt">
                    <strong>1. Escaneie o código com seu aplicativo</strong>
                    <span>Abra o autenticador, toque em "adicionar conta" e aponte a câmera.</span>
                </div>

                <div class="tfa-qr-linha">
                    {{-- SVG gerado pelo servidor a partir do próprio segredo (BaconQrCode).
                         Não há dado do usuário interpolado como texto aqui: o e-mail entra
                         no QR como desenho, não como marcação. --}}
                    <div class="tfa-qr">{!! $qrCode !!}</div>

                    <div class="tfa-chave">
                        <span>Não consegue escanear? Digite esta chave no aplicativo:</span>
                        <code>{{ $chaveManual }}</code>
                    </div>
                </div>
            </li>

            <li>
                <div class="tfa-passo-txt">
                    <strong>2. Digite o código que apareceu</strong>
                    <span>Seis dígitos que mudam a cada 30 segundos. É ele que confirma que
                        seu aparelho está configurado.</span>
                </div>

                <form method="POST" action="{{ route('settings.2fa.confirmar') }}" class="tfa-form-codigo">
                    @csrf
                    <div class="field">
                        <label for="codigo_2fa" class="sr-only">Código de verificação</label>
                        <input id="codigo_2fa" class="input tfa-input-codigo" type="text" name="codigo"
                               inputmode="numeric" autocomplete="one-time-code" placeholder="000000"
                               maxlength="7" data-no-money required />
                        @error('codigo', 'twoFactor')
                            <p class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <button type="submit" class="btn-primary">Confirmar e ativar</button>
                </form>
            </li>
        </ol>

        {{-- Cancelar não pede senha: nada está protegido ainda (o login só cobra o código
             depois da confirmação), e a senha acabou de ser digitada para chegar aqui. --}}
        <form method="POST" action="{{ route('settings.2fa.desativar') }}" class="tfa-cancelar">
            @csrf
            @method('delete')
            <button type="submit" class="btn-ghost">Cancelar configuração</button>
        </form>

    {{-- ========================= ESTADO 2: ativada ========================= --}}
    @elseif ($ativo)
        {{-- Mesmo padrão do estado desligado: o switch é o controle. Aqui ele ABRE a
             confirmação para DESATIVAR — desligar uma proteção nunca acontece num
             clique só; a senha continua obrigatória. --}}
        <details class="tfa-toggle" @if ($errors->twoFactorDesligar->isNotEmpty()) open @endif>
            <summary class="sec-2fa-row" role="button" aria-label="Desativar verificação em duas etapas">
                <div class="sec-2fa-txt">
                    <strong>App autenticador (TOTP)</strong>
                    <span>Ativa desde {{ $user->two_factor_confirmed_at->translatedFormat('j \d\e F \d\e Y') }}
                        · {{ $user->codigosDeRecuperacaoRestantes() }} código(s) de recuperação restante(s)</span>
                </div>
                <span class="switch is-on" aria-hidden="true"><span class="switch-dot"></span></span>
            </summary>

            <form method="POST" action="{{ route('settings.2fa.desativar') }}" class="sess-logout-form">
                @csrf
                @method('delete')
                <p>Sua conta volta a ser protegida só pela senha. Digite a senha atual para confirmar.</p>
                <div class="field">
                    <label for="senha_desativar_2fa" class="sr-only">Senha</label>
                    <input id="senha_desativar_2fa" class="input" type="password" name="password"
                           placeholder="Sua senha" autocomplete="current-password" />
                    @error('password', 'twoFactorDesligar')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="btn-danger">Desativar</button>
            </form>
        </details>

        @if ($user->codigosDeRecuperacaoRestantes() === 0)
            <div class="flash-error" role="alert">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
                <span>Seus códigos de recuperação acabaram. Gere novos agora — sem eles,
                    perder o celular significa perder o acesso à conta.</span>
            </div>
        @endif

        {{-- Gerar novos códigos (exige a senha) --}}
        <details class="sess-logout tfa-acao" @if ($errors->twoFactorCodigos->isNotEmpty()) open @endif>
            <summary class="sess-logout-trigger tfa-trigger">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 12a8 8 0 0 1 13.7-5.6L20 8"/><path d="M20 4v4h-4"/><path d="M20 12a8 8 0 0 1-13.7 5.6L4 16"/><path d="M4 20v-4h4"/></svg>
                Gerar novos códigos de recuperação
            </summary>
            <form method="POST" action="{{ route('settings.2fa.codigos') }}" class="sess-logout-form">
                @csrf
                <p>Os códigos atuais deixam de valer na hora. Use isto se perdeu a lista ou
                    acha que alguém a viu.</p>
                <div class="field">
                    <label for="senha_codigos_2fa" class="sr-only">Senha</label>
                    <input id="senha_codigos_2fa" class="input" type="password" name="password"
                           placeholder="Sua senha" autocomplete="current-password" />
                    @error('password', 'twoFactorCodigos')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="btn-primary">Gerar novos códigos</button>
            </form>
        </details>

    {{-- ========================= ESTADO 3: desativada ========================= --}}
    @else
        {{-- O SWITCH é o controle: clicar nele abre a confirmação por senha logo
             abaixo, dentro do próprio card. O <details>/<summary> envolve a linha
             inteira, então o alvo de clique é a linha toda (e o teclado alcança
             pelo summary, sem JS). --}}
        <details class="tfa-toggle" @if ($errors->twoFactor->isNotEmpty()) open @endif>
            <summary class="sec-2fa-row" role="button" aria-label="Ativar verificação em duas etapas">
                <div class="sec-2fa-txt">
                    <strong>App autenticador (TOTP)</strong>
                    <span>Desativada. Hoje sua conta é protegida apenas pela senha.</span>
                </div>
                <span class="switch is-off" aria-hidden="true"><span class="switch-dot"></span></span>
            </summary>

            <form method="POST" action="{{ route('settings.2fa.ativar') }}" class="sess-logout-form">
                @csrf

                {{-- O aviso vem ANTES do botão, não depois: quem liga o 2FA sem entender
                     que precisa guardar os códigos é exatamente quem perde a conta. --}}
                <div class="tfa-aviso">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 4.5 2.8 20h18.4L12 4.5Z"/><path d="M12 10v4M12 17.2h.01"/></svg>
                    <span>
                        Você vai precisar do celular <b>toda vez</b> que entrar num aparelho novo.
                        Depois de ativar, guarde os códigos de recuperação que aparecerão aqui.
                        @if ($semRecuperacaoPorEmail)
                            <b>Atenção:</b> o aplicativo ainda não envia e-mails, então não há
                            recuperação por e-mail — perder o celular <b>e</b> os códigos
                            significa perder o acesso.
                        @endif
                    </span>
                </div>

                <div class="field">
                    <label for="senha_ativar_2fa" class="sr-only">Senha</label>
                    <input id="senha_ativar_2fa" class="input" type="password" name="password"
                           placeholder="Sua senha" autocomplete="current-password" />
                    @error('password', 'twoFactor')
                        <p class="field-error">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="btn-primary">Continuar</button>
            </form>
        </details>
    @endif

    {{-- ============ Aparelhos com autenticador na família ============ --}}
    @include('settings.partials.two-factor-familia')
</div>

{{-- ================== Card lateral: o que é e como se recupera ================== --}}
<div class="card sec-card span5">
    <div class="card-head">
        <h3>Como funciona</h3>
        <span class="chip">Entenda</span>
    </div>

    <p class="sec-card-desc">
        Além da senha, o app passa a pedir um <strong>código de 6 dígitos</strong> gerado no
        seu celular. Quem descobrir sua senha ainda assim não entra.
    </p>

    <ul class="tfa-info">
        <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M10.5 18.5h3"/></svg>
            <div>
                <strong>Funciona com qualquer autenticador</strong>
                <span>Google Authenticator, Authy, Microsoft Authenticator ou o gerenciador
                    de senhas do seu navegador.</span>
            </div>
        </li>
        <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 3 5 6v5c0 4.2 2.9 7.7 7 9 4.1-1.3 7-4.8 7-9V6l-7-3Z"/></svg>
            <div>
                <strong>O código muda a cada 30 segundos</strong>
                <span>Ele é gerado no aparelho, sem internet — não depende de SMS nem de e-mail.</span>
            </div>
        </li>
        <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 12a8 8 0 0 1 13.7-5.6L20 8"/><path d="M20 4v4h-4"/><path d="M20 12a8 8 0 0 1-13.7 5.6L4 16"/><path d="M4 20v-4h4"/></svg>
            <div>
                <strong>Códigos de recuperação</strong>
                <span>
                    Ao ativar, você recebe uma lista. Cada código serve <b>uma vez</b> e entra
                    no lugar do celular.
                    @if ($semRecuperacaoPorEmail)
                        Como o aplicativo ainda não envia e-mails, <b>é a única forma de voltar</b>
                        se você perder o aparelho — guarde num lugar seguro.
                    @endif
                </span>
            </div>
        </li>
        <li>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4.5 4.5l15 15"/><rect x="4" y="10" width="16" height="11" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 6.9-2.8"/></svg>
            <div>
                <strong>É opcional</strong>
                <span>Nasce desligada, e você liga ou desliga quando quiser — sempre confirmando
                    com a sua senha.</span>
            </div>
        </li>
    </ul>
</div>
