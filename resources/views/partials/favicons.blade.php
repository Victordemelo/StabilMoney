{{-- Ícones da aba e dos buscadores (15/10/2026): o mesmo em TODAS as páginas. O Google pede um
     ícone quadrado em múltiplo de 48 px e costuma ir direto no /favicon.ico — que era o arquivo
     vazio do esqueleto do Laravel, e o site aparecia sem ícone na Busca. Gerados por
     resources/icones/gerar-favicons.py; trocou o ícone, suba o `?v=`. --}}
<link rel="icon" href="{{ asset('favicon.ico') }}?v=2" sizes="48x48" />
<link rel="icon" type="image/png" sizes="96x96" href="{{ asset('assets/icons/favicon-96.png') }}?v=2" />
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('assets/icons/icon-192.png') }}" />
