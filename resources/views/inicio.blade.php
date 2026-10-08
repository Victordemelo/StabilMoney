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

    $perguntas = [
        ['É grátis mesmo?', 'Sim. O Stabil Money é gratuito. Se um dia isso mudar, os Termos de Uso explicam como você será avisado antes.'],
        ['Preciso conectar minha conta do banco?', 'Não. O app não se conecta a banco nenhum e nunca pede senha de banco. Você lança o que entra e o que sai — e enxerga para onde o dinheiro vai.'],
        ['Preciso informar os dados do meu cartão?', 'Não. O Stabil Money nunca pede o número do cartão, o código de segurança (CVV), a validade nem a senha. Para controlar a fatura, você cadastra só um apelido para o cartão, o banco, o limite e os dias de fechamento e de vencimento da fatura — nada que permita usar o cartão.'],
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
    <meta name="theme-color" content="#032628" />
    <title>{{ $titulo }}</title>
    @include('partials.seo', ['seoTitulo' => $titulo])
    <link rel="icon" type="image/png" href="{{ asset('assets/favicon.png') }}" />
    @include('partials.pwa-head')
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    {{-- Fontes da página inicial (out/2026): Bricolage Grotesque 800 nos títulos e Geist no texto.
         Só aqui — o app segue com Sora + Plus Jakarta. O Google Fonts já está na Política e na CSP. --}}
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600..800&family=Geist:wght@400..600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Dados de perguntas frequentes (schema.org/FAQPage). Bloco de DADOS, com o nonce e as
         mesmas flags de escape do @json. --}}
    <script type="application/ld+json" nonce="{{ Vite::cspNonce() }}">{!! json_encode($faq, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
</head>
<body class="inicio-body">
<div class="inicio">

    {{--
        Reformulação de out/2026 (pedido do Victor, com o estilo da Wise como base): verde-petróleo
        profundo (o fundo da ilustração do topo) com UM destaque lima, títulos blocados em Bricolage
        Grotesque 800, texto em Geist, botões em pílula. Faixas alternam papel, creme (as
        ilustrações claras) e o escuro (topo, Segurança, chamada final). Sem rótulo em maiúsculas
        acima dos títulos, sem degradê, sem a grade de cards iguais.
    --}}
    <header class="in-topo">
        <div class="in-wrap in-topo-in">
            <a class="in-marca" href="{{ url('/') }}" aria-label="{{ $site }} — início">
                <img src="{{ asset('assets/stabilmoney-mark.png') }}" alt="" width="34" height="34" />
                <span>Stabil<b>Money</b></span>
            </a>
            <nav class="in-menu" aria-label="Seções da página">
                <a href="#recursos">Recursos</a>
                <a href="#como-funciona">Como funciona</a>
                <a href="#familia">Família</a>
                <a href="#seguranca">Segurança</a>
                <a href="#quem-fez">Quem fez</a>
                <a href="#perguntas">Perguntas</a>
            </nav>
            <div class="in-topo-acoes">
                <a class="in-btn in-btn-contorno" href="{{ route('login') }}">Entrar</a>
                <a class="in-btn in-btn-lima" href="{{ route('register') }}">Criar conta grátis</a>
            </div>
        </div>
    </header>

    <main>
        {{-- ---------- Abertura: o título e uma prévia VIVA do app ---------- --}}
        {{--
            Sem ilustração (a do celular "tinha cara de IA", out/2026): ao lado do título, o app
            em pequeno, desenhado em HTML. A cada poucos segundos entra um lançamento novo e o
            saldo acompanha (`sm/vitrine.js`). Os números são de exemplo. Sem JS, ou com
            "reduzir movimento", fica este estado inicial, parado.
        --}}
        @php
            $vitrine = [
                'corrente' => 3018.30, 'poupanca' => 1200.00, 'receitas' => 5200.00, 'despesas' => 1981.70,
                'itens' => [
                    ['Salário', 'Receita', 5200.00],
                    ['Aluguel', 'Moradia', -1450.00],
                    ['Mercado', 'Alimentação', -347.20],
                ],
            ];
            $num = fn ($v) => number_format(abs($v), 2, ',', '.');
        @endphp
        <section class="in-hero">
            <div class="in-wrap in-hero-in">
                <div class="in-hero-texto">
                    <h1>Seu dinheiro com clareza, controle e crescimento.</h1>
                    <p class="in-hero-lede">Receitas, despesas, cartões com fatura, contas fixas, metas e investimentos — para você e para a família, no computador e no celular.</p>
                    {{-- Sem botões aqui (out/2026): "Criar conta" e "Entrar" já estão no topo e no fim
                         da página — repetidos logo abaixo do texto, ficavam redundantes. --}}
                    <ul class="in-hero-pontos">
                        <li>Grátis</li>
                        <li>Sem conectar banco</li>
                        <li>Para você e para a família</li>
                    </ul>
                    <div class="in-instalar">
                        {{-- "Instalar o app" (PWA, sm/instalar.js): nasce escondido. Aparece quando o
                             navegador oferece a instalação (Chrome/Android, Edge); no iPhone, a instrução;
                             instalado, o aviso. Sem JS, ou em http, nada aparece. --}}
                        <button type="button" class="in-btn in-btn-lima" data-instalar-app hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M12 7v7M9 11l3 3 3-3"/></svg>Instalar o app</button>
                        <p class="in-instalar-dica" data-instalar-ios hidden>No iPhone: toque em <strong>Compartilhar</strong> e depois em <strong>Adicionar à Tela de Início</strong>.</p>
                        <p class="in-instalar-dica" data-instalar-android hidden>No Chrome: toque em <strong>⋮</strong> (no canto de cima) e depois em <strong>Instalar app</strong> ou <strong>Adicionar à tela inicial</strong>.</p>
                        <p class="in-instalar-dica" data-instalado hidden>O app já está instalado neste aparelho.</p>
                    </div>
                </div>

                <figure class="in-vitrine" data-vitrine
                        data-corrente="{{ $vitrine['corrente'] }}" data-poupanca="{{ $vitrine['poupanca'] }}"
                        data-receitas="{{ $vitrine['receitas'] }}" data-despesas="{{ $vitrine['despesas'] }}"
                        role="img" aria-label="Exemplo da Visão geral do app: saldo disponível, gasto do mês, últimos lançamentos e uma meta.">
                    <div class="vt-painel" aria-hidden="true">
                        <div class="vt-topo">
                            <span>Visão geral</span>
                            <span class="vt-mes">{{ ucfirst(now()->translatedFormat('F')) }}</span>
                        </div>
                        <p class="vt-rotulo">Saldo disponível</p>
                        <p class="vt-saldo"><span class="vt-moeda">R$</span> <span data-vt-saldo>{{ $num($vitrine['corrente'] + $vitrine['poupanca']) }}</span></p>
                        <dl class="vt-contas">
                            <div><dt>Conta corrente</dt><dd>R$ <span data-vt-corrente>{{ $num($vitrine['corrente']) }}</span></dd></div>
                            <div><dt>Poupança</dt><dd>R$ {{ $num($vitrine['poupanca']) }}</dd></div>
                        </dl>
                        <div class="vt-gasto">
                            <div class="vt-gasto-linha">
                                <span>Gasto do mês</span>
                                <span>R$ <span data-vt-gasto>{{ $num($vitrine['despesas']) }}</span> de R$ <span data-vt-renda>{{ $num($vitrine['receitas']) }}</span></span>
                            </div>
                            <div class="vt-barra"><i data-vt-barra style="width: {{ round($vitrine['despesas'] / $vitrine['receitas'] * 100, 1) }}%"></i></div>
                        </div>
                        <p class="vt-rotulo vt-rotulo-lista">Últimos lançamentos</p>
                        <ul class="vt-lista" data-vt-lista>
                            @foreach ($vitrine['itens'] as [$nome, $categoria, $valor])
                                <li class="vt-item {{ $valor > 0 ? 'vt-receita' : 'vt-despesa' }}">
                                    <span class="vt-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="{{ $valor > 0 ? 'M17 7 7 17M7 9v8h8' : 'M7 17 17 7M9 7h8v8' }}"/></svg></span>
                                    <span class="vt-txt"><b>{{ $nome }}</b><small>{{ $categoria }}</small></span>
                                    <span class="vt-valor">{{ $valor > 0 ? '+' : '−' }}R$ {{ $num($valor) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="vt-aviso" aria-hidden="true">
                        <span class="vt-sino"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 20a1.9 1.9 0 0 0 3.4 0"/></svg></span>
                        <span><b>Condomínio</b><small>vence em 3 dias</small></span>
                    </div>

                    <div class="vt-meta" aria-hidden="true">
                        <svg class="vt-anel" viewBox="0 0 44 44"><circle cx="22" cy="22" r="18"/><circle class="vt-anel-cheio" cx="22" cy="22" r="18" pathLength="100"/></svg>
                        <span><b>Viagem de férias</b><small>68% de R$ 5.000,00</small></span>
                    </div>
                </figure>
            </div>
        </section>

        {{-- ---------- Equilíbrio: faixa clara com a ilustração da balança ---------- --}}
        <section class="in-equilibrio" aria-labelledby="equilibrio-titulo">
            <div class="in-wrap">
                <div class="in-titulo in-titulo-centro">
                    <h2 id="equilibrio-titulo">Equilíbrio é saber para onde vai cada real</h2>
                    <p>O que entra, o que vence e o que você guarda, na mesma balança — para cada decisão caber no mês.</p>
                </div>
                <img class="in-equilibrio-img"
                     src="{{ asset('assets/equilibrio-1600.jpg') }}"
                     srcset="{{ asset('assets/equilibrio-900.jpg') }} 900w, {{ asset('assets/equilibrio-1600.jpg') }} 1600w"
                     sizes="(max-width: 1120px) 100vw, 1080px"
                     alt="Ilustração de uma carteira equilibrada sobre uma balança de madeira, entre moedas, uma planta e dois painéis com gráficos"
                     width="1600" height="900" loading="lazy" decoding="async">
            </div>
        </section>

        {{-- ---------- Recursos: linhas de ícone + texto (não uma grade de cards iguais) ---------- --}}
        <section class="in-secao" id="recursos">
            <div class="in-wrap">
                <div class="in-titulo">
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
                <div class="in-recursos">
                    @foreach ($recursos as [$nome, $texto, $icone])
                        <article class="in-recurso">
                            <span class="in-icone"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor">{!! $icone !!}</svg></span>
                            <h3>{{ $nome }}</h3>
                            <p>{{ $texto }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ---------- Como funciona: os três passos sobre a ilustração clara ---------- --}}
        <section class="in-passos-faixa" id="como-funciona">
            <picture class="in-passos-arte">
                <source media="(max-width: 860px)" srcset="{{ asset('assets/inicio-contas-960.jpg') }}">
                <img src="{{ asset('assets/inicio-contas-1672.jpg') }}" alt="" width="1672" height="941" loading="lazy" decoding="async">
            </picture>
            <div class="in-wrap in-passos-in">
                <div class="in-titulo">
                    <h2>Comece em três passos</h2>
                </div>
                {{-- Numerados porque SÃO uma sequência. --}}
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
                <div class="in-titulo">
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

        {{-- ---------- Segurança: a faixa escura do meio da página ---------- --}}
        <section class="in-secao in-escura" id="seguranca">
            <div class="in-wrap">
                <div class="in-titulo">
                    <h2>Seu controle, sem entregar a chave do banco</h2>
                    <p>{{ config('seo.o_que_nao_e') }}</p>
                </div>
                <div class="in-garantias">
                    <article>
                        <h3>Verificação em duas etapas</h3>
                        <p>Ligue um código do app autenticador no login — e, se quiser, confie no seu aparelho por 7 dias.</p>
                    </article>
                    <article>
                        <h3>Senha protegida</h3>
                        <p>A senha é guardada só como hash, e senhas que já vazaram na internet são recusadas no cadastro.</p>
                    </article>
                    <article>
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
                    {{-- A foto do autor: retangular, grande e centralizada na altura do texto. --}}
                    <img class="in-autor-foto" src="{{ asset($autor['foto']) }}" alt="Foto de {{ $autor['nome'] }}"
                         width="583" height="600" loading="lazy" decoding="async">
                    <div class="in-autor-texto">
                        <h2>{{ $autor['nome'] }}</h2>
                        <p class="in-autor-papel">Criador e desenvolvedor do {{ $site }}</p>
                        {{-- A história do projeto, contada pelo autor (out/2026). --}}
                        <p>Por muito tempo eu procurei um aplicativo para organizar a minha vida financeira. Os mais completos eram pagos; os gratuitos faziam só um pedaço — anotavam gastos, mas não entendiam a fatura do cartão, as parcelas, as contas fixas do mês, nem a família dividindo o mesmo dinheiro. Nenhum dava conta da gestão inteira da minha conta.</p>
                        <p>Então resolvi construir o meu. O projeto nasceu com o nome MoneyLife, mas o endereço na internet já tinha dono — e a troca acabou dizendo melhor o que eu queria: <strong>Stabil Money</strong>, dinheiro estável. Saber quanto entra, quanto sai, o que vence e o que está guardado, sem susto no fim do mês.</p>
                        <p>Hoje o {{ $site }} é um projeto independente, desenhado e desenvolvido por mim: um lugar só para o dinheiro da casa, com as regras que fazem sentido no Brasil — cartão com fatura e parcelas, cheque especial, Pix, contas fixas — e com a família inteira olhando para os mesmos números.</p>
                        <p>O app está em fase de testes, é gratuito, e cada melhoria nasce do uso de verdade. Sugestões e problemas encontrados são bem-vindos pelo contato abaixo.</p>
                        <div class="in-autor-links">
                            <a class="in-btn in-btn-contorno" href="{{ $autor['site'] }}" target="_blank" rel="noopener noreferrer">Site</a>
                            <a class="in-btn in-btn-contorno" href="{{ $autor['linkedin'] }}" target="_blank" rel="noopener noreferrer">LinkedIn</a>
                            <a class="in-btn in-btn-contorno" href="{{ $autor['github'] }}" target="_blank" rel="noopener noreferrer">GitHub</a>
                            <a class="in-btn in-btn-contorno" href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- ---------- Perguntas frequentes ---------- --}}
        <section class="in-secao in-nevoa" id="perguntas">
            <div class="in-wrap in-estreito">
                <div class="in-titulo">
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

        {{-- ---------- Chamada final: um bloco escuro, a única ação lima da faixa ---------- --}}
        <section class="in-final">
            <div class="in-wrap">
                <div class="in-final-bloco">
                    <h2>Comece a organizar o dinheiro da casa hoje</h2>
                    <p>Grátis, em português e no seu celular.</p>
                    <div class="in-final-acoes">
                        <a class="in-btn in-btn-lima in-btn-grande" href="{{ route('register') }}">Criar conta grátis</a>
                        <a class="in-link" href="{{ route('login') }}">Já tenho conta</a>
                    </div>
                    <div class="in-instalar">
                        {{-- "Instalar o app" (PWA, sm/instalar.js): nasce escondido. Aparece quando o
                             navegador oferece a instalação (Chrome/Android, Edge); no iPhone, a instrução;
                             instalado, o aviso. Sem JS, ou em http, nada aparece. --}}
                        <button type="button" class="in-btn in-btn-contorno" data-instalar-app hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M12 7v7M9 11l3 3 3-3"/></svg>Instalar o app</button>
                        <p class="in-instalar-dica" data-instalar-ios hidden>No iPhone: toque em <strong>Compartilhar</strong> e depois em <strong>Adicionar à Tela de Início</strong>.</p>
                        <p class="in-instalar-dica" data-instalar-android hidden>No Chrome: toque em <strong>⋮</strong> (no canto de cima) e depois em <strong>Instalar app</strong> ou <strong>Adicionar à tela inicial</strong>.</p>
                        <p class="in-instalar-dica" data-instalado hidden>O app já está instalado neste aparelho.</p>
                    </div>
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
            <p>© {{ now()->year }} {{ $site }}, versão {{ config('sistema.versao') }}. Feito no Brasil por {{ $autor['nome'] }}.</p>
        </div>
    </footer>
</div>

@include('partials.cookie-consent')
</body>
</html>
