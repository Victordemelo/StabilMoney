<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEO das páginas públicas: quem pode ir para os buscadores, as URLs absolutas e o
 * conteúdo do robots.txt, do sitemap.xml e dos dados estruturados. A lista e os textos
 * moram em config/seo.php; a regra do "noindex por padrão" é aplicada no
 * App\Http\Middleware\SecurityHeaders.
 */
final class Seo
{
    /** O valor do cabeçalho que tira uma resposta dos buscadores. */
    public const NOINDEX = 'noindex, nofollow';

    /**
     * Esta resposta pode aparecer nos buscadores?
     *
     * Só em produção, só uma página da lista (`config('seo.paginas')`) e só quando ela
     * respondeu de fato (200): o redirect do login para quem já entrou, o erro de
     * validação que volta para o cadastro, nada disso é a página pública.
     */
    public static function indexavel(Request $request, Response $response): bool
    {
        // Só para quem NÃO entrou: a raiz serve a página inicial pública ao visitante e a Visão
        // geral (privada) a quem entrou, no mesmo endereço.
        return app()->isProduction()
            && $request->user() === null
            && $response->getStatusCode() === 200
            && self::pagina($request->route()?->getName()) !== null;
    }

    /**
     * O SEO da página pública com este nome de rota — ou null, se ela não é pública.
     *
     * @return array{descricao: string, aplicativo?: bool}|null
     */
    public static function pagina(?string $rota): ?array
    {
        if ($rota === null) {
            return null;
        }

        $pagina = config('seo.paginas')[$rota] ?? null;

        return is_array($pagina) ? $pagina : null;
    }

    /**
     * URL absoluta a partir do APP_URL — nunca do Host da requisição, que quem pede
     * escolhe: com um Host forjado, a URL canônica (e a prévia de link) apontaria para
     * o site de outra pessoa.
     */
    public static function url(string $caminho = ''): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($caminho, '/');
    }

    /** A URL canônica desta página: o caminho, sem a query string (`?recuperacao=1`, UTM…). */
    public static function urlCanonica(Request $request): string
    {
        $caminho = $request->path();

        return self::url($caminho === '/' ? '' : $caminho);
    }

    /**
     * O robots.txt. Em produção abre tudo e aponta o sitemap: quem decide o que fica de
     * fora é o `X-Robots-Tag: noindex` de cada resposta — um robots.txt que proibisse as
     * telas privadas impediria o buscador de ler esse noindex (e listaria os caminhos
     * para qualquer curioso). Fora de produção, fecha tudo.
     */
    public static function robotsTxt(): string
    {
        if (! app()->isProduction()) {
            return "# Ambiente de testes: nada daqui deve ir para os buscadores.\n"
                ."User-agent: *\n"
                ."Disallow: /\n";
        }

        $robosDeIa = implode('', array_map(fn (string $robo) => "User-agent: {$robo}\n", config('seo.robos_de_ia', [])));

        return '# '.config('seo.site').' — '.self::url()."\n"
            ."User-agent: *\n"
            ."Allow: /\n"
            ."\n"
            .'# Assistentes de IA: podem ler e citar as páginas públicas. Resumo do app: '.self::url('llms.txt')."\n"
            .$robosDeIa
            ."Allow: /\n"
            ."\n"
            .'Sitemap: '.self::url('sitemap.xml')."\n";
    }

    /**
     * O sitemap.xml: as páginas públicas, com URL absoluta. Fora de produção sai vazio,
     * coerente com o robots.txt.
     */
    public static function sitemapXml(): string
    {
        $urls = app()->isProduction()
            ? array_map(
                fn (string $rota) => '  <url><loc>'.e(self::url(route($rota, absolute: false))).'</loc>'
                    .'<lastmod>'.e(self::atualizadaEm($rota)).'</lastmod></url>',
                array_keys(config('seo.paginas')),
            )
            : [];

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode("\n", $urls).($urls ? "\n" : '')
            .'</urlset>'."\n";
    }

    /** Data (AAAA-MM-DD) da última mudança da página: a da versão, nos documentos legais. */
    private static function atualizadaEm(string $rota): string
    {
        return in_array($rota, ['termos', 'privacidade'], true)
            ? (string) config('legal.updated_at_iso')
            : (string) config('seo.atualizado_em');
    }

    /**
     * O /llms.txt (llmstxt.org): o resumo do app para assistentes de IA — o que é, o que faz,
     * o que NÃO é, e só as páginas públicas (as do sitemap). Em Markdown, como pede o formato.
     * Fora de produção, só diz que é um ambiente de testes (coerente com o robots.txt).
     */
    public static function llmsTxt(): string
    {
        $site = (string) config('seo.site');

        if (! app()->isProduction()) {
            return "# {$site}\n\n> Ambiente de testes — nada daqui deve ser lido nem citado.\n";
        }

        $nomes = ['dashboard' => 'Página inicial', 'login' => 'Entrar', 'register' => 'Criar conta grátis', 'termos' => 'Termos de Uso', 'privacidade' => 'Política de Privacidade'];
        $paginas = '';
        foreach (config('seo.paginas') as $rota => $pagina) {
            $paginas .= '- ['.($nomes[$rota] ?? $rota).']('.self::url(route($rota, absolute: false)).'): '.$pagina['descricao']."\n";
        }
        $recursos = implode('', array_map(fn (string $r) => "- {$r}\n", config('seo.recursos', [])));

        return "# {$site}\n\n"
            .'> '.config('seo.resumo')."\n\n"
            .config('seo.o_que_nao_e')."\n\n"
            ."## Páginas\n\n".$paginas."\n"
            ."## O que o app faz\n\n".$recursos."\n"
            ."## Contato\n\n"
            .'- E-mail: '.config('legal.contact_email')."\n"
            .'- Autor: '.config('legal.controller').' ('.config('seo.autor_url').")\n";
    }

    /**
     * Dados estruturados (schema.org) do app, para o buscador entender o que ele é:
     * um aplicativo web de finanças, gratuito, em português.
     *
     * @return array<string, mixed>
     */
    public static function dadosEstruturados(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebApplication',
            'name' => config('seo.site'),
            'url' => self::url(),
            'description' => config('seo.paginas.login.descricao'),
            'applicationCategory' => 'FinanceApplication',
            'operatingSystem' => 'Web',
            'inLanguage' => 'pt-BR',
            'isAccessibleForFree' => true,
            'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'BRL'],
            'image' => self::url(config('seo.imagem')),
            'author' => [
                '@type' => 'Person',
                'name' => config('legal.controller'),
                'url' => config('seo.autor_url'),
                'sameAs' => array_values(array_filter([config('sistema.autor.site'), config('sistema.autor.linkedin')])),
            ],
            'featureList' => config('seo.recursos', []),
        ];
    }
}
