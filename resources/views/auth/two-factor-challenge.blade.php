@extends('layouts.auth')

@section('title', 'Verificação em duas etapas')

{{-- Painel visual (mesma linguagem das demais telas de auth v2) --}}
@section('eyebrow')
    Segurança da conta
@endsection

@section('headline')
    Falta só <em>um passo</em> para entrar.
@endsection

@section('sub')
    Sua conta está protegida por verificação em duas etapas. Além da senha, pedimos o código
    que muda a cada 30 segundos no seu aplicativo autenticador.
@endsection

@section('features')
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/></svg></span>O código vive no seu celular, não na internet</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 7v5.2l3.2 2"/></svg></span>Cada código vale uma vez e por poucos segundos</div>
    <div class="av-feat"><span class="fi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M12 3 5 6v5c0 4.2 2.9 7.7 7 9 4.1-1.3 7-4.8 7-9V6l-7-3Z"/><path d="m9.2 12 1.9 1.9 3.7-3.8"/></svg></span>Nem quem descobrir sua senha entra sem ele</div>
@endsection

{{-- Card do formulário --}}
@section('card')
    <div class="ac-head">
        <h1>Verificação em duas etapas</h1>
        <p>
            @if ($recuperacao)
                Digite um dos códigos de recuperação que você guardou quando ativou a verificação.
            @else
                Abra seu aplicativo autenticador e digite o código de 6 dígitos que ele mostra agora.
            @endif
        </p>
    </div>

    @error('codigo')
        <div class="auth-error long" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
            <span>{{ $message }}</span>
        </div>
    @enderror

    {{-- Sem códigos sobrando: avisar ANTES de a pessoa procurar o papel é mais honesto
         do que deixá-la digitar e receber "código inválido". --}}
    @if ($recuperacao && $codigosRestantes === 0)
        <div class="auth-error long" role="alert">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 15.8h.01"/></svg>
            <span>Não há mais códigos de recuperação nesta conta — todos já foram usados.
                Para recuperar o acesso, fale com {{ config('legal.contact_email') }}.</span>
        </div>
    @endif

    <form method="POST" action="{{ route('two-factor.login') }}">
        @csrf

        @if ($recuperacao)
            <input type="hidden" name="recuperacao" value="1" />
        @endif

        <div class="field">
            <label for="codigo">{{ $recuperacao ? 'Código de recuperação' : 'Código de verificação' }}</label>
            <div class="input">
                @if ($recuperacao)
                    <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M15 7a4 4 0 1 1-3.4 6.1L9 15.7l-1.6.3-.3 1.6-1.6.3-.3 1.6H3v-2.6l5.9-5.9A4 4 0 0 1 15 7Z"/></svg>
                    {{-- autocapitalize: no celular o teclado começa minúsculo e o código é
                         maiúsculo. O servidor normaliza de qualquer jeito, mas ver o que se
                         digita igual ao que está no papel evita a sensação de erro. --}}
                    <input type="text" id="codigo" name="codigo" class="otp otp-wide"
                           placeholder="XXXXX-XXXXX" maxlength="13" required autofocus
                           autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" />
                @else
                    <svg class="lead" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="7" y="2.5" width="10" height="19" rx="2.5"/><path d="M11 18.5h2"/></svg>
                    {{-- `one-time-code` é o que faz o iOS/Android oferecerem o código do
                         autenticador direto no teclado. --}}
                    <input type="text" id="codigo" name="codigo" class="otp"
                           placeholder="000000" maxlength="7" required autofocus
                           inputmode="numeric" autocomplete="one-time-code" />
                @endif
            </div>
        </div>

        <button type="submit" class="btn-primary spaced">
            Entrar
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>

    <p class="ac-alt">
        @if ($recuperacao)
            <a href="{{ route('two-factor.login') }}">Voltar e usar o aplicativo autenticador</a>
        @else
            <a href="{{ route('two-factor.login', ['recuperacao' => 1]) }}">Não consigo acessar o aplicativo</a>
        @endif
    </p>

    {{-- Saída: descarta o login pendente. Formulário (POST) e não link, porque muda
         estado da sessão. --}}
    <form method="POST" action="{{ route('two-factor.cancel') }}" class="tfa-sair">
        @csrf
        <button type="submit" class="btn-quiet">Entrar com outra conta</button>
    </form>
@endsection
