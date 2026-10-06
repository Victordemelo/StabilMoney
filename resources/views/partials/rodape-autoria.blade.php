{{--
    Rodapé de autoria (out/2026 — pedido do Victor): no fim de TODA tela do app, dentro do
    `#content` (rola com a página e o pjax o traz junto). Discreto de propósito: assina o
    trabalho sem disputar atenção com o dinheiro.
--}}
@php
    $anoAtual = (int) now()->year;
    $anos = $anoAtual > 2026 ? '2026–'.$anoAtual : '2026';
@endphp
<footer class="rodape-autoria" aria-label="Sobre o Stabil Money">
    <div class="ra-marca">
        <img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" width="22" height="22" loading="lazy" />
        <span class="ra-nome">Stabil<b>Money</b></span>
        <span class="ra-versao">v{{ config('sistema.versao') }}</span>
    </div>
    <p class="ra-assinatura">
        Desenvolvido com <span class="ra-coracao" aria-hidden="true">♥</span><span class="sr-only">carinho</span> por
        <a href="{{ config('sistema.autor.site') }}" target="_blank" rel="noopener noreferrer">{{ config('sistema.autor.nome') }}</a>
        · © {{ $anos }}
    </p>
    <nav class="ra-links" aria-label="Links do rodapé">
        <a href="{{ route('sistema') }}">Sobre o sistema</a>
        <a href="{{ route('termos') }}">Termos</a>
        <a href="{{ route('privacidade') }}">Privacidade</a>
    </nav>
</footer>
