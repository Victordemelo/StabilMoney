{{-- Aba "Instalar no celular" (out/2026): o PWA ganhou aba própria — antes era um card perdido
     no pé da aba Conta. O botão é o mesmo da tela de login (partials/instalar-app): quem decide o
     que aparece é o sm/instalar.js, conforme o navegador. --}}
<div class="card sec-card span6">
    <div class="card-head">
        <h3>Aplicativo no celular</h3>
        <span class="chip">PWA</span>
    </div>
    <p class="sec-card-desc">
        Instale o Stabil Money na tela inicial: ele abre como um aplicativo, em tela cheia, e deixa
        lançar sem internet — os lançamentos sincronizam sozinhos quando a conexão volta.
    </p>
    @include('partials.instalar-app')
    <ul class="celular-vantagens">
        <li>Ícone na tela inicial, sem barra do navegador.</li>
        <li>Lançamento sem internet, guardado no aparelho até sincronizar.</li>
        <li>Mesma conta e mesmos dados do computador — nada a configurar.</li>
    </ul>
</div>

<div class="card sec-card span6">
    <div class="card-head">
        <h3>Como instalar</h3>
        <span class="chip">Passo a passo</span>
    </div>
    <ol class="celular-passos">
        <li><strong>Android (Chrome)</strong><span>Toque em "Instalar o app" aqui ao lado — ou no menu ⋮ do Chrome, em "Instalar app".</span></li>
        <li><strong>iPhone (Safari)</strong><span>Toque em <b>Compartilhar</b> e depois em <b>Adicionar à Tela de Início</b>.</span></li>
        <li><strong>Computador (Chrome ou Edge)</strong><span>Use o ícone de instalar na barra de endereço, ou o botão aqui ao lado.</span></li>
    </ol>
    <p class="sec-card-desc">O navegador só oferece a instalação em conexão segura (https). Se o botão não aparecer, use o passo a passo do seu aparelho.</p>
</div>
