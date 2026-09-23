{{-- SEO das páginas PÚBLICAS (login, cadastro, termos, privacidade — `config('seo.paginas')`):
     a descrição que o buscador mostra, a URL canônica, a prévia de link (WhatsApp e redes
     sociais, pelo Open Graph) e, no login e no cadastro, os dados estruturados do app.

     Nas demais páginas não sai nada: elas vão com `X-Robots-Tag: noindex`
     (SecurityHeaders), e prévia de uma tela de "redefinir senha" só espalharia o link.

     Todas as URLs saem do APP_URL (`App\Support\Seo`), nunca do Host da requisição.
     Recebe `$seoTitulo`: o mesmo texto do <title> da página. --}}
@php
    $seoPagina = \App\Support\Seo::pagina(request()->route()?->getName());
@endphp
@if ($seoPagina)
    @php
        $seoUrl = \App\Support\Seo::urlCanonica(request());
        $seoImagem = \App\Support\Seo::url(config('seo.imagem'));
    @endphp
    <meta name="description" content="{{ $seoPagina['descricao'] }}" />
    <link rel="canonical" href="{{ $seoUrl }}" />

    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="{{ config('seo.site') }}" />
    <meta property="og:locale" content="pt_BR" />
    <meta property="og:title" content="{{ $seoTitulo }}" />
    <meta property="og:description" content="{{ $seoPagina['descricao'] }}" />
    <meta property="og:url" content="{{ $seoUrl }}" />
    <meta property="og:image" content="{{ $seoImagem }}" />
    <meta property="og:image:type" content="image/jpeg" />
    <meta property="og:image:width" content="{{ config('seo.imagem_largura') }}" />
    <meta property="og:image:height" content="{{ config('seo.imagem_altura') }}" />
    <meta property="og:image:alt" content="{{ config('seo.imagem_alt') }}" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="{{ $seoTitulo }}" />
    <meta name="twitter:description" content="{{ $seoPagina['descricao'] }}" />
    <meta name="twitter:image" content="{{ $seoImagem }}" />

    @if (! empty($seoPagina['aplicativo']))
        {{-- Com o nonce, como todo <script> do app (CspComNonceTest). É um bloco de DADOS, que
             o navegador não executa; as flags de escape são as do @json (sem elas, um texto
             com "</script>" fecharia a tag). --}}
        <script type="application/ld+json" nonce="{{ Vite::cspNonce() }}">{!! json_encode(\App\Support\Seo::dadosEstruturados(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endif
@endif
