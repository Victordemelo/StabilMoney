<?php

namespace App\Http\Controllers;

use App\Support\Seo;
use Illuminate\Http\Response;

/**
 * robots.txt e sitemap.xml gerados pelo app (23/09/2026).
 *
 * Gerados, e não arquivos em public/: o conteúdo depende do APP_URL (o endereço do
 * sitemap é absoluto) e do ambiente — fora de produção o robots.txt fecha tudo. Um
 * arquivo estático em public/ seria servido pelo Apache antes de chegar ao Laravel, por
 * isso o public/robots.txt padrão do framework saiu.
 *
 * Sem sessão nem cookie (rotas no mesmo grupo do PWA, em routes/web.php): quem busca é o
 * robô, a cada visita.
 */
class SeoController extends Controller
{
    /** Uma hora de cache: robô e Cloudflare não precisam gerar isto a cada visita. */
    private const CACHE = 'public, max-age=3600';

    public function robots(): Response
    {
        return response(Seo::robotsTxt(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => self::CACHE,
        ]);
    }

    public function sitemap(): Response
    {
        return response(Seo::sitemapXml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => self::CACHE,
        ]);
    }
}
