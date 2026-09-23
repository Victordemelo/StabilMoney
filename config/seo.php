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
    'paginas' => [
        'login' => [
            'descricao' => 'Controle financeiro pessoal e da família: receitas, despesas, cartões, contas fixas, metas e investimentos num só lugar. Gratuito.',
            'aplicativo' => true,
        ],
        'register' => [
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

];
