@extends('layouts.app')

@section('title', 'Informações do sistema')

{{-- Informações do sistema (out/2026): versão, autor, documentos legais e o aceite de quem está
     vendo. Os valores vêm de config/sistema.php e config/legal.php — nada fixo aqui. Sem versão
     de PHP/Laravel de propósito: dizer a pilha exata só ajuda quem procura uma falha conhecida. --}}
@section('content')
<section class="view settings-view">
    <div class="section-head">
        <h2>Informações do sistema</h2>
        <span class="sub">Versão, autor e documentos</span>
    </div>

    <div class="settings-body">
        <div class="card sec-card span6">
            <div class="card-head">
                <h3>Stabil Money</h3>
                <span class="chip">{{ config('sistema.fase') }}</span>
            </div>
            <div class="sis-marca">
                <img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" width="56" height="56">
                <div>
                    <strong class="sis-versao">Versão {{ config('sistema.versao') }}</strong>
                    <span class="sis-sub">Lançada em {{ config('sistema.lancada_em') }}</span>
                </div>
            </div>
            <dl class="sis-lista">
                <div><dt>O que é</dt><dd>Controle financeiro pessoal e da família: receitas, despesas, cartões, contas fixas, metas e investimentos.</dd></div>
                <div><dt>Preço</dt><dd>Gratuito.</dd></div>
                <div><dt>Funciona offline</dt><dd>Sim, instalado no celular: lançamentos feitos sem internet sincronizam depois.</dd></div>
                <div><dt>Segurança</dt><dd>Senha com verificação de vazamento, verificação em duas etapas opcional e registro de atividade.</dd></div>
            </dl>
        </div>

        <div class="card sec-card span6">
            <div class="card-head">
                <h3>Quem fez</h3>
                <span class="chip">Autor</span>
            </div>
            @php
                $partes = preg_split('/\s+/', trim(config('sistema.autor.nome')));
                $iniciais = mb_strtoupper(mb_substr($partes[0], 0, 1).mb_substr(end($partes), 0, 1));
            @endphp
            <div class="sis-autor-head">
                {{-- A foto do autor (a mesma de "Quem fez" da página inicial); sem ela, as iniciais. --}}
                @if (config('sistema.autor.foto'))
                    <img class="sis-autor-av sis-autor-foto" src="{{ asset(config('sistema.autor.foto')) }}" alt="Foto de {{ config('sistema.autor.nome') }}" width="56" height="56" loading="lazy" decoding="async">
                @else
                    <span class="sis-autor-av" aria-hidden="true">{{ $iniciais }}</span>
                @endif
                <div>
                    <p class="sis-autor">{{ config('sistema.autor.nome') }}</p>
                    <span class="sis-sub">Criador e desenvolvedor do Stabil Money</span>
                </div>
            </div>
            <div class="sis-links">
                <a class="btn-ghost" href="{{ config('sistema.autor.site') }}" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3Z"/></svg>
                    Site
                </a>
                <a class="btn-ghost" href="{{ config('sistema.autor.linkedin') }}" target="_blank" rel="noopener noreferrer">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10.5V16M8 7.8h.01M11.5 16v-3.2a2.3 2.3 0 0 1 4.6 0V16M11.5 10.5V16"/></svg>
                    LinkedIn
                </a>
                @if (config('sistema.autor.github'))
                    <a class="btn-ghost" href="{{ config('sistema.autor.github') }}" target="_blank" rel="noopener noreferrer">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 19c-4.3 1.4-4.3-2.5-6-3m12 5v-3.5c0-1 .1-1.4-.5-2 2.8-.3 5.5-1.4 5.5-6a4.6 4.6 0 0 0-1.3-3.2 4.2 4.2 0 0 0-.1-3.2s-1.1-.3-3.5 1.3a12.3 12.3 0 0 0-6.2 0C6.5 2.8 5.4 3.1 5.4 3.1a4.2 4.2 0 0 0-.1 3.2A4.6 4.6 0 0 0 4 9.5c0 4.6 2.7 5.7 5.5 6-.6.6-.6 1.2-.5 2V21"/></svg>
                        GitHub
                    </a>
                @endif
            </div>
            <dl class="sis-lista">
                <div><dt>Contato</dt><dd><a href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a></dd></div>
                <div><dt>Encontrou um problema?</dt><dd>Escreva para o contato acima contando a tela e o que aconteceu.</dd></div>
                <div><dt>Sugestões</dt><dd>Ideias de melhoria são bem-vindas pelo mesmo contato.</dd></div>
            </dl>
        </div>

        <div class="card sec-card span12">
            <div class="card-head">
                <h3>Documentos</h3>
                <span class="chip">Versão {{ config('legal.version') }}</span>
            </div>
            <dl class="sis-lista sis-docs">
                <div><dt><a href="{{ route('termos') }}">Termos de Uso</a></dt><dd>Versão {{ config('legal.version') }}, atualizada em {{ config('legal.updated_at') }}.</dd></div>
                <div><dt><a href="{{ route('privacidade') }}">Política de Privacidade</a></dt><dd>Versão {{ config('legal.version') }}, atualizada em {{ config('legal.updated_at') }}.</dd></div>
                <div>
                    <dt>Seu aceite</dt>
                    <dd>
                        @if ($usuario->terms_version)
                            Versão {{ $usuario->terms_version }}@if ($usuario->terms_accepted_at), em {{ $usuario->terms_accepted_at->translatedFormat('j \\d\\e F \\d\\e Y') }}@endif.
                        @else
                            Ainda sem registro.
                        @endif
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</section>
@endsection
