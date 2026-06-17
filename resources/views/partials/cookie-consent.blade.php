{{-- Consentimento de cookies (LGPD). Renderiza escondido; o script revela só se
     ainda não houve consentimento (lembrado em localStorage 'sm-cookie-consent'). --}}
<div class="cookie-bar" id="cookieBar" role="dialog" aria-label="Aviso de cookies" aria-live="polite" hidden>
    <div class="cookie-txt">
        <strong>Cookies por aqui 🍪</strong>
        <p>Usamos cookies essenciais para manter você conectado e lembrar suas preferências.
           <a href="#">Saiba mais</a>.</p>
    </div>
    <button type="button" class="btn-primary cookie-accept" id="cookieAccept">Aceitar</button>
</div>
<script>
    (function () {
        var KEY = 'sm-cookie-consent';
        try { if (localStorage.getItem(KEY) === '1') return; } catch (e) { return; }
        var bar = document.getElementById('cookieBar');
        if (!bar) return;
        bar.hidden = false;
        requestAnimationFrame(function () { bar.classList.add('show'); });
        var aceitar = document.getElementById('cookieAccept');
        if (aceitar) {
            aceitar.addEventListener('click', function () {
                try { localStorage.setItem(KEY, '1'); } catch (e) { /* storage indisponível */ }
                bar.classList.remove('show');
                setTimeout(function () { bar.hidden = true; }, 300);
            });
        }
    })();
</script>
