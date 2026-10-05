@extends('layouts.legal')

{{-- Tela de aceite da versão atual dos Termos/Política (ExigeAceiteDaPoliticaAtual). O texto
     daqui NÃO entra na impressão do VersaoDosDocumentosLegaisTest (ela lê termos/privacidade);
     o que mudou vem de `config('legal.mudancas')`. --}}
@section('title', 'Aceite dos Termos e da Política · StabilMoney')

@section('content')
    <div class="aceite">
        <h1>{{ $primeiroAceite ? 'Antes de continuar' : 'Atualizamos os nossos documentos' }}</h1>
        <p class="upd">Versão {{ $versao }} · {{ config('legal.updated_at') }}</p>

        <p>
            @if ($primeiroAceite)
                Para usar o Stabil Money, leia e aceite os <a href="{{ route('termos') }}" target="_blank" rel="noopener">Termos de Uso</a>
                e a <a href="{{ route('privacidade') }}" target="_blank" rel="noopener">Política de Privacidade</a>.
            @else
                Os <a href="{{ route('termos') }}" target="_blank" rel="noopener">Termos de Uso</a> e a
                <a href="{{ route('privacidade') }}" target="_blank" rel="noopener">Política de Privacidade</a>
                mudaram desde a última vez que você os aceitou. Para continuar, leia o que mudou e aceite a nova versão.
            @endif
        </p>

        @if ($mudancas)
            <div class="legal-note">
                <strong>O que mudou na versão {{ $versao }}:</strong>
                <ul>
                    @foreach ($mudancas as $mudanca)
                        <li>{{ $mudanca }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('termos.aceitar') }}" class="aceite-form">
            @csrf
            <label class="check aceite-check">
                <input type="checkbox" name="aceito" value="1" required>
                <span class="box"><svg viewBox="0 0 24 24" fill="none"><path d="M5 12l4 4 10-10"/></svg></span>
                <span>Li e aceito os Termos de Uso e a Política de Privacidade (versão {{ $versao }}).</span>
            </label>
            @error('aceito')<p class="field-error">{{ $message }}</p>@enderror
            <button type="submit" class="btn-primary">Aceitar e continuar</button>
        </form>

        <div class="aceite-sair">
            <p>
                Não concorda? Você pode sair agora, ou
                <a href="{{ route('settings', 'conta') }}">excluir a sua conta</a> — os seus dados são
                apagados como descreve a Política.
            </p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn-ghost">Sair da conta</button>
            </form>
        </div>
    </div>
@endsection
