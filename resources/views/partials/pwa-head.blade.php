{{--
    Head do PWA: link do manifest + metas de instalação (iOS/Android).
    Incluído em TODOS os layouts (app, auth) para o app ser instalável
    de qualquer tela — inclusive a de login. Aditivo: favicon e theme-color
    seguem no <head> de cada layout. Substitui o apple-touch-icon antigo pelo
    ícone opaco 180×180 (iOS ignora transparência e põe fundo preto atrás).
--}}
<link rel="manifest" href="{{ url('/site.webmanifest') }}" />
<link rel="apple-touch-icon" href="{{ asset('assets/icons/apple-touch-icon.png') }}" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
<meta name="apple-mobile-web-app-title" content="StabilMoney" />
