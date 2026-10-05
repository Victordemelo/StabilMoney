<!DOCTYPE html>
{{--
    Página inicial PÚBLICA (out/2026) — a raiz do site para quem não entrou
    (`App\Http\Middleware\PaginaInicialParaVisitante`). Apresenta o Stabil Money, o que ele faz,
    como funciona, a segurança, quem fez e as perguntas frequentes; leva ao cadastro e ao login.

    Sempre clara, como o login (tokens do `.inicio` em resources/css/inicio.css). Textos e
    links de autor vêm dos configs (`seo`, `sistema`, `legal`) — nada fixo para ficar para trás.
    O SEO (descrição, canônica, prévia de link, dados do app) vem do `partials.seo`, como nas
    outras páginas públicas; as perguntas frequentes viram FAQPage (schema.org) aqui embaixo.
--}}
@php
    $site = config('seo.site');
    $titulo = config('seo.paginas.dashboard.titulo');
    $autor = config('sistema.autor');
    $partesAutor = preg_split('/\s+/', trim($autor['nome']));
    $iniciaisAutor = mb_strtoupper(mb_substr($partesAutor[0], 0, 1).mb_substr(end($partesAutor), 0, 1));

    $perguntas = [
        ['É grátis mesmo?', 'Sim. O Stabil Money é gratuito. Se um dia isso mudar, os Termos de Uso explicam como você será avisado antes.'],
        ['Preciso conectar minha conta do banco?', 'Não. O app não se conecta a banco nenhum e nunca pede senha de banco. Você lança o que entra e o que sai — e enxerga para onde o dinheiro vai.'],
        ['Funciona no celular?', 'Funciona no navegador do computador e do celular, e pode ser instalado na tela inicial como um aplicativo. Instalado, deixa lançar até sem internet: o lançamento sincroniza quando a conexão volta.'],
        ['Dá para usar com a família?', 'Dá. O titular cadastra os dependentes, cada um com login próprio, e todos veem o mesmo dinheiro da casa — com quanto cada pessoa gastou no mês.'],
        ['Meus dados estão seguros?', 'A senha é guardada só como hash, dá para ligar a verificação em duas etapas, e cada ação fica registrada no histórico da conta. Os detalhes estão na Política de Privacidade.'],
    ];

    $faq = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn ($p) => [
            '@type' => 'Question',
            'name' => $p[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $p[1]],
        ], $perguntas),
    ];
@endphp
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="theme-color" content="#0C3D2B" />
    <title>{{ $titulo }}</title>
    @include('partials.seo', ['seoTitulo' => $titulo])
    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    @include('partials.pwa-head')
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Dados de perguntas frequentes (schema.org/FAQPage). Bloco de DADOS, com o nonce e as
         mesmas flags de escape do @json. --}}
    <script type="application/ld+json" nonce="{{ Vite::cspNonce() }}">{!! json_encode($faq, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
</head>
<body class="inicio-body">
<div class="inicio">

    <header class="in-topo">
        <div class="in-wrap in-topo-in">
            <a class="in-marca" href="{{ url('/') }}" aria-label="{{ $site }} — início">
                <img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" width="34" height="34" />
                <span>Stabil<b>Money</b></span>
            </a>
            <nav class="in-menu" aria-label="Seções da página">
                <a href="#recursos">Recursos</a>
                <a href="#como-funciona">Como funciona</a>
                <a href="#seguranca">Segurança</a>
                <a href="#quem-fez">Quem fez</a>
                <a href="#perguntas">Perguntas</a>
            </nav>
            <div class="in-topo-acoes">
                <a class="in-btn in-btn-claro" href="{{ route('login') }}">Entrar</a>
                <a class="in-btn in-btn-cheio" href="{{ route('register') }}">Criar conta grátis</a>
            </div>
        </div>
    </header>

    <main>
        {{-- ---------- Abertura ---------- --}}
        <section class="in-hero">
            <video class="in-hero-video" autoplay muted loop playsinline preload="metadata"
                   poster="{{ asset('assets/og-stabilmoney.jpg') }}" aria-hidden="true">
                <source src="{{ asset('assets/video_login.mp4') }}" type="video/mp4" />
            </video>
            <div class="in-hero-veu" aria-hidden="true"></div>
            <div class="in-wrap in-hero-in">
                <div class="in-hero-texto">
                    <span class="in-selo">Gratuito · em português · funciona no celular</span>
                    <h1>Seu dinheiro com <em>clareza</em>, controle e crescimento.</h1>
                    <p>{{ config('seo.resumo') }}</p>
                    <div class="in-hero-acoes">
                        <a class="in-btn in-btn-cheio in-btn-grande" href="{{ route('register') }}">Criar conta grátis</a>
                        <a class="in-btn in-btn-vidro in-btn-grande" href="{{ route('login') }}">Já tenho conta</a>
                    </div>
                    <ul class="in-hero-pontos">
                        <li>Sem conectar banco</li>
                        <li>Sem cartão de crédito para começar</li>
                        <li>Para você e para a família</li>
                    </ul>
                </div>

                {{-- Uma amostra da Visão geral, desenhada em HTML (valores ilustrativos). --}}
                <div class="in-amostra" aria-hidden="true">
                    <div class="in-amostra-saldo">
                        <span>Saldo disponível</span>
                        <strong>R$ 4.820<small>,35</small></strong>
                        <em>↗ 12% no mês</em>
                    </div>
                    <div class="in-amostra-linha">
                        <div><span>Receitas</span><b class="pos">R$ 6.200,00</b></div>
                        <div><span>Despesas</span><b class="neg">R$ 3.179,65</b></div>
                    </div>
                    <div class="in-amostra-barras">
                        @foreach ([38, 64, 48, 82, 56, 70, 44] as $altura)
                            <i style="--h: {{ $altura }}%"></i>
                        @endforeach
                    </div>
                    <div class="in-amostra-item"><span class="ic">🛒</span><span>Mercado <small>Cartão · 3x</small></span><b>− R$ 420,00</b></div>
                    <div class="in-amostra-item"><span class="ic">🏠</span><span>Aluguel <small>vence dia 10</small></span><b>− R$ 1.850,00</b></div>
                </div>
            </div>
        </section>

        {{-- ---------- Recursos ---------- --}}
        <section class="in-secao" id="recursos">
            <div class="in-wrap">
                <div class="in-titulo">
                    <span class="in-eyebrow">Recursos</span>
                    <h2>Tudo o que o dinheiro da casa precisa, num lugar só</h2>
                    <p>Pensado para a vida financeira no Brasil: cartão com fatura e parcelas, cheque especial, Pix, TED e as contas que vencem todo mês.</p>
                </div>
                @php
                    $recursos = [
                        ['Visão geral do mês', 'Saldo disponível, receitas, despesas e quanto sobrou, com gráficos por categoria e por período.', '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>'],
                        ['Cartões com fatura', 'Compras à vista, parceladas e recorrentes caem no ciclo certo, e o limite volta quando a fatura é paga.', '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6 15h4"/>'],
                        ['Contas a pagar', 'Aluguel, condomínio, energia: as contas fixas aparecem todo mês, com aviso do que vence e do que venceu.', '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6M9 12h6"/>'],
                        ['Metas', 'Guarde para a viagem ou para a reserva de emergência. O que está guardado sai do disponível, para não ser gasto sem querer.', '<path d="M12 13V2l8 4-8 4"/><path d="M20.6 13.5A9 9 0 1 1 8 3.3"/><path d="M12 13 7.5 8.5"/>'],
                        ['Investimentos', 'CDI, Selic, IPCA+ e prefixado, com projeção de rendimento e estimativa de IR e IOF.', '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>'],
                        ['Funciona no celular', 'Instale na tela inicial e lance até sem internet: o lançamento sincroniza quando a conexão volta.', '<rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M11 18.5h2"/>'],
                    ];
                @endphp
                <div class="in-grade in-grade-3">
                    @foreach ($recursos as [$nome, $texto, $icone])
                        <article class="in-card">
                            <span class="in-icone"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor">{!! $icone !!}</svg></span>
                            <h3>{{ $nome }}</h3>
                            <p>{{ $texto }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ---------- Como funciona ---------- --}}
        <section class="in-secao in-secao-alt" id="como-funciona">
            <div class="in-wrap">
                <div class="in-titulo">
                    <span class="in-eyebrow">Como funciona</span>
                    <h2>Comece em três passos</h2>
                </div>
                <ol class="in-passos">
                    <li><strong>Crie a sua conta</strong><span>É grátis e leva um minuto. Nada de cartão de crédito nem de dados do banco.</span></li>
                    <li><strong>Cadastre contas e cartões</strong><span>A conta corrente, a poupança, os cartões de crédito e débito, o Pix — com o saldo de hoje.</span></li>
                    <li><strong>Lance e acompanhe</strong><span>Cada receita e despesa entra no lugar certo. O app mostra o que sobra, o que vence e para onde o dinheiro vai.</span></li>
                </ol>
            </div>
        </section>

        {{-- ---------- Família ---------- --}}
        <section class="in-secao" id="familia">
            <div class="in-wrap in-dupla">
                <div class="in-titulo in-titulo-esq">
                    <span class="in-eyebrow">Conta-família</span>
                    <h2>O dinheiro da casa, visto por todos</h2>
                    <p>O titular cadastra quem divide as contas com ele. Cada pessoa entra com o próprio login, lança as próprias compras e enxerga o mesmo dinheiro — com quanto cada um gastou no mês.</p>
                </div>
                <ul class="in-lista">
                    <li>Login próprio para cada pessoa da família</li>
                    <li>"Quem fez a compra" em cada lançamento</li>
                    <li>A fatia de cada um no gasto do mês</li>
                    <li>Histórico de atividade: quem mexeu em quê e quando</li>
                </ul>
            </div>
        </section>

        {{-- ---------- Segurança ---------- --}}
        <section class="in-secao in-secao-alt" id="seguranca">
            <div class="in-wrap">
                <div class="in-titulo">
                    <span class="in-eyebrow">Segurança e privacidade</span>
                    <h2>Seu controle, sem entregar a chave do banco</h2>
                    <p>{{ config('seo.o_que_nao_e') }}</p>
                </div>
                <div class="in-grade in-grade-3">
                    <article class="in-card">
                        <h3>Verificação em duas etapas</h3>
                        <p>Ligue um código do app autenticador no login — e, se quiser, confie no seu aparelho por 7 dias.</p>
                    </article>
                    <article class="in-card">
                        <h3>Senha protegida</h3>
                        <p>A senha é guardada só como hash, e senhas que já vazaram na internet são recusadas no cadastro.</p>
                    </article>
                    <article class="in-card">
                        <h3>Transparência (LGPD)</h3>
                        <p>A Política de Privacidade diz o que é coletado, para quê e por quanto tempo — e como pedir a exclusão.</p>
                    </article>
                </div>
            </div>
        </section>

        {{-- ---------- Quem fez ---------- --}}
        <section class="in-secao" id="quem-fez">
            <div class="in-wrap">
                <div class="in-autor">
                    <span class="in-autor-av" aria-hidden="true">{{ $iniciaisAutor }}</span>
                    <div class="in-autor-texto">
                        <span class="in-eyebrow">Quem fez</span>
                        <h2>{{ $autor['nome'] }}</h2>
                        <p class="in-autor-papel">Criador e desenvolvedor do {{ $site }}</p>
                        <p>O {{ $site }} é um projeto independente, desenhado e desenvolvido por {{ $autor['nome'] }}. A ideia é simples: um lugar só para o dinheiro da casa, com as regras que fazem sentido no Brasil — cartão com fatura e parcelas, cheque especial, Pix, contas fixas — e com a família inteira olhando para os mesmos números.</p>
                        <p>O app está em fase de testes, é gratuito, e cada melhoria nasce do uso de verdade. Sugestões e problemas encontrados são bem-vindos pelo contato abaixo.</p>
                        <div class="in-autor-links">
                            <a class="in-btn in-btn-claro" href="{{ $autor['site'] }}" target="_blank" rel="noopener noreferrer">Site</a>
                            <a class="in-btn in-btn-claro" href="{{ $autor['linkedin'] }}" target="_blank" rel="noopener noreferrer">LinkedIn</a>
                            <a class="in-btn in-btn-claro" href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------- Perguntas frequentes ---------- --}}
        <section class="in-secao in-secao-alt" id="perguntas">
            <div class="in-wrap in-estreito">
                <div class="in-titulo">
                    <span class="in-eyebrow">Perguntas frequentes</span>
                    <h2>Antes de começar</h2>
                </div>
                <div class="in-faq">
                    @foreach ($perguntas as [$pergunta, $resposta])
                        <details @if ($loop->first) open @endif>
                            <summary>{{ $pergunta }}</summary>
                            <p>{{ $resposta }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ---------- Chamada final ---------- --}}
        <section class="in-final">
            <div class="in-wrap in-final-in">
                <h2>Comece a organizar o dinheiro da casa hoje</h2>
                <p>Grátis, em português e no seu celular.</p>
                <div class="in-hero-acoes">
                    <a class="in-btn in-btn-cheio in-btn-grande" href="{{ route('register') }}">Criar conta grátis</a>
                    <a class="in-btn in-btn-vidro in-btn-grande" href="{{ route('login') }}">Entrar</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="in-rodape">
        <div class="in-wrap in-rodape-in">
            <a class="in-marca" href="{{ url('/') }}">
                <img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" width="28" height="28" />
                <span>Stabil<b>Money</b></span>
            </a>
            <nav aria-label="Documentos">
                <a href="{{ route('termos') }}">Termos de Uso</a>
                <a href="{{ route('privacidade') }}">Política de Privacidade</a>
                <a href="mailto:{{ config('legal.contact_email') }}">Contato</a>
            </nav>
            <p>© {{ now()->year }} {{ $site }} · versão {{ config('sistema.versao') }} · feito no Brasil por {{ $autor['nome'] }}</p>
        </div>
    </footer>
</div>

@include('partials.cookie-consent')
</body>
</html>
