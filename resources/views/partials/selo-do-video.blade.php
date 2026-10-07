{{--
    Selo do Stabil Money sobre a marca d'água do vídeo de fundo (out/2026 — pedido do Victor:
    "ponha a logo do Stabil Money para tampar esse logo do Gemini"). O `video_login.mp4` é
    1280×720 e traz uma estrela no canto, centrada em (1160, 600). O vídeo é desenhado com
    `object-fit: cover`, então a estrela muda de lugar com o tamanho da tela; o CSS do
    `.selo-do-video` refaz a MESMA conta do cover (unidades de container query) e põe o selo
    exatamente em cima dela. Quando o recorte do cover corta a estrela, o selo sai junto.

    Vai DEPOIS do vídeo e do véu, dentro do mesmo bloco posicionado.
--}}
<span class="camada-do-video" aria-hidden="true">
    <span class="selo-do-video"><img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" width="64" height="64"></span>
</span>
