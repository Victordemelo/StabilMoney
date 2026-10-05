<?php

/*
|--------------------------------------------------------------------------
| SEO das páginas públicas (23/09/2026)
|--------------------------------------------------------------------------
|
| O app é quase todo privado. Para quem não entrou só existem o login, o cadastro e
| os documentos legais — e são SÓ essas as páginas que podem aparecer nos buscadores
| e nas prévias de link (WhatsApp, redes sociais). Todo o resto sai com
| `X-Robots-Tag: noindex` (App\Http\Middleware\SecurityHeaders): página nova nasce
| fora dos buscadores sem ninguém precisar lembrar, e o painel administrativo nunca é
| citado em lugar nenhum (nem no robots.txt, que qualquer um lê).
|
| FORA DE PRODUÇÃO nada é indexável e o robots.txt fecha tudo: um ambiente de testes
| publicado por engano não entra no Google concorrendo com o site de verdade.
|
| As URLs (canônica, og:url, sitemap, robots) saem do APP_URL, nunca do Host da
| requisição: um Host forjado não pode virar a URL canônica de ninguém.
|
*/

return [

    // Nome da marca nas buscas e nas prévias de link.
    'site' => 'Stabil Money',

    // Imagem das prévias de link: 1200×630, abaixo de 300 KB (o WhatsApp costuma não
    // mostrar prévia de imagem maior). É o painel visual do login do design v2; o fonte
    // e o comando para gerar de novo estão em resources/og/og-stabilmoney.html.
    'imagem' => 'assets/og-stabilmoney.jpg',
    'imagem_largura' => 1200,
    'imagem_altura' => 630,
    'imagem_alt' => 'Stabil Money — seu dinheiro com clareza, controle e crescimento.',

    // Autor do app nos dados estruturados. O nome é o do controlador dos dados
    // (config/legal.php); a URL é o portfólio.
    'autor_url' => 'https://victordemelo.com.br',

    // As páginas públicas, por NOME DE ROTA — e a descrição de cada uma (a frase que o
    // buscador mostra embaixo do título; ~155 caracteres no máximo). `aplicativo`
    // marca as páginas que levam os dados estruturados do app (schema.org).
    //
    // Página fora desta lista não recebe meta de prévia e sai com noindex. Para abrir
    // uma página nova aos buscadores, é aqui — e ela entra sozinha no sitemap.xml.
    //
    // `titulo` (opcional) é o <title> INTEIRO da página para o buscador e a prévia de link —
    // o login é a porta de entrada do domínio, e "Entrar · StabilMoney" não dizia o que o
    // app é. Sem `titulo`, vale o título da própria tela.
    'paginas' => [
        // A raiz (`/`, rota `dashboard`): para quem NÃO entrou é a página inicial pública
        // (`PaginaInicialParaVisitante`); para quem entrou, a Visão geral — que nunca é
        // indexável (`Seo::indexavel` exige visitante sem sessão).
        'dashboard' => [
            'titulo' => 'Stabil Money — controle financeiro pessoal e da família, grátis',
            'descricao' => 'Organize receitas, despesas, cartões com fatura, contas fixas, metas e investimentos — sozinho ou em família. Gratuito, em português e funciona no celular.',
            'aplicativo' => true,
        ],
        'login' => [
            'titulo' => 'Entrar no Stabil Money — controle financeiro da família',
            'descricao' => 'Controle financeiro pessoal e da família: receitas, despesas, cartões, contas fixas, metas e investimentos num só lugar. Gratuito.',
            'aplicativo' => true,
        ],
        'register' => [
            'titulo' => 'Criar conta grátis no Stabil Money — organize o dinheiro da família',
            'descricao' => 'Crie sua conta grátis no Stabil Money e organize o dinheiro da família: cartões com fatura, contas fixas, metas e investimentos.',
            'aplicativo' => true,
        ],
        'termos' => [
            'descricao' => 'Termos de Uso do Stabil Money: o que o app faz, o que não faz e as regras da conta-família.',
        ],
        'privacidade' => [
            'descricao' => 'Política de Privacidade do Stabil Money: quais dados coletamos, para quê, por quanto tempo e como exercer seus direitos pela LGPD.',
        ],
    ],

    // Data da última mudança das páginas do app (login/cadastro) no sitemap.xml. Os
    // documentos legais usam a data da versão deles (config/legal.php, `updated_at_iso`).
    'atualizado_em' => '2026-10-05',

    // Robôs de busca e citação das IAs (ChatGPT, Claude, Perplexity, Gemini, Apple). O
    // `User-agent: *` já libera todo mundo; o grupo explícito no robots.txt deixa a escolha
    // clara — e um bloqueio genérico adicionado no futuro não os pega por engano. O resumo
    // que eles leem é o /llms.txt.
    'robos_de_ia' => [
        'GPTBot', 'OAI-SearchBot', 'ChatGPT-User',
        'ClaudeBot', 'Claude-SearchBot', 'Claude-User',
        'PerplexityBot', 'Perplexity-User',
        'Google-Extended', 'Applebot-Extended',
    ],

    // Verificação de posse no Google Search Console e no Bing Webmaster Tools (meta tag nas
    // páginas públicas). Opcional: o registro TXT no DNS da Cloudflare também serve.
    'verificacao' => [
        'google' => env('SEO_GOOGLE_VERIFICACAO'),
        'bing' => env('SEO_BING_VERIFICACAO'),
    ],

    // O que o /llms.txt conta sobre o app (o resumo para assistentes de IA).
    'resumo' => 'Controle financeiro pessoal e da família, gratuito e em português: receitas, despesas, cartões de crédito com fatura, contas fixas, metas e investimentos — num app web que também funciona instalado no celular, inclusive sem internet.',
    'recursos' => [
        'Visão geral do mês: saldo disponível, receitas, despesas e quanto sobrou, com gráficos por categoria.',
        'Cartões de crédito com ciclo e fatura: compras à vista, parceladas e recorrentes, e o limite que volta ao pagar.',
        'Contas fixas mensais (aluguel, condomínio, energia) com aviso de vencimento.',
        'Metas de economia e investimentos (CDI, Selic, IPCA+, prefixado) com projeção e estimativa de IR e IOF.',
        'Conta-família: dependentes com login próprio, vendo o mesmo dinheiro, e quanto cada um gastou no mês.',
        'Cheque especial, transferência entre contas e escolha de onde sai o dinheiro quando o saldo acaba.',
        'Segurança: verificação em duas etapas opcional, checagem de senha vazada e registro de atividade.',
    ],
    'o_que_nao_e' => 'O Stabil Money não é banco nem instituição financeira: não movimenta dinheiro, não se conecta à sua conta bancária e nunca pede senha de banco. Os valores são os que a própria pessoa lança.',

];
