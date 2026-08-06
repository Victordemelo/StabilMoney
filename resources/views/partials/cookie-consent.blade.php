{{-- Consentimento de cookies (LGPD). Renderiza escondido; o script revela só se
     ainda não houve consentimento (lembrado em localStorage 'sm-cookie-consent'). --}}
<div class="cookie-bar" id="cookieBar" role="dialog" aria-label="Aviso de cookies" aria-live="polite" hidden>
    <div class="cookie-txt">
        <strong>Cookies por aqui 🍪</strong>
        <p>Usamos cookies essenciais para manter você conectado e lembrar suas preferências.
           <a href="{{ route('privacidade') }}">Saiba mais</a>.</p>
    </div>
    <button type="button" class="btn-primary cookie-accept" id="cookieAccept">Aceitar</button>
</div>
<script nonce="{{ Vite::cspNonce() }}">
    (function () {
        var KEY = 'sm-cookie-consent';
        try { if (localStorage.getItem(KEY) === '1') return; } catch (e) { return; }
        var bar = document.getElementById('cookieBar');
        if (!bar) return;
        bar.hidden = false;
        // Marca o documento enquanto a barra estiver na tela. A barra é
        // `position: fixed` e não empurra nada — no celular ela caía EM CIMA do
        // botão "Entrar" do login (medido: barra 493→656, botão 585→636; o toque
        // no centro do botão acertava o "Aceitar"). Com esta classe, o CSS reserva
        // o espaço embaixo e nada mais fica debaixo dela.
        //
        // Classe no <html> em vez de `:has()`: precisa funcionar no WebView antigo
        // que o TWA da Fase 2 vai usar, e isto é a porta de entrada do app.
        document.documentElement.classList.add('tem-aviso-cookie');
        requestAnimationFrame(function () { bar.classList.add('show'); });
        var aceitar = document.getElementById('cookieAccept');
        if (aceitar) {
            aceitar.addEventListener('click', function () {
                try { localStorage.setItem(KEY, '1'); } catch (e) { /* storage indisponível */ }
                bar.classList.remove('show');
                document.documentElement.classList.remove('tem-aviso-cookie');
                setTimeout(function () { bar.hidden = true; }, 300);
            });
        }
    })();
</script>
