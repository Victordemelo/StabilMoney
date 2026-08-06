{{--
    Head do PWA: link do manifest + metas de instalação (iOS/Android).
    Incluído em TODOS os layouts (app, auth, legal) para o app ser instalável
    de qualquer tela — inclusive a de login. Aditivo: favicon e theme-color
    seguem no <head> de cada layout (cada tela tem um topo de cor diferente).
    Substitui o apple-touch-icon antigo pelo ícone opaco 180×180 (iOS ignora
    transparência e põe fundo preto atrás).
--}}
<link rel="manifest" href="{{ url('/site.webmanifest') }}" />
<link rel="apple-touch-icon" href="{{ asset('assets/icons/apple-touch-icon.png') }}" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="mobile-web-app-capable" content="yes" />

{{-- Barra de status do iPhone (esta meta só tem efeito no app INSTALADO na tela
     inicial; no Safari comum quem manda é o navegador).

     Era `black-translucent`, que faz duas coisas ruins aqui:
       1. força os glifos (hora, bateria, sinal) a BRANCO — no tema claro, que é
          o padrão do app, eles ficavam brancos sobre um fundo quase branco, ou
          seja, invisíveis;
       2. joga o conteúdo POR BAIXO da barra, e isso só se conserta com
          `env(safe-area-inset-top)` — que o CSS do app não usa em lugar nenhum.
          Ou seja: pagávamos o custo do full-bleed sem colher o benefício.

     `default` = glifos ESCUROS e conteúdo abaixo da barra. É o certo para o tema
     claro e para as telas de auth (cujo topo, no celular, é o painel do vídeo com
     o fundo do body claro por trás). No tema escuro o inline anti-flash do layout
     troca para `black` (glifos claros). A troca precisa morar no inline do <head>,
     e não no bundle: o iOS lê esta meta durante o carregamento da página. --}}
<meta name="apple-mobile-web-app-status-bar-style" content="default" />
<meta name="apple-mobile-web-app-title" content="StabilMoney" />
