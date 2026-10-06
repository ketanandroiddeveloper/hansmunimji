<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class SeoController extends Controller
{
    public function __construct(private Database $db, private Config $config, private Clock $clock)
    {
    }

    public function sitemap(Request $request): Response
    {
        $base = (string) $this->config->get('app.frontend_url');
        $urls = [
            ['/', null, '1.0'], ['/practitioner', null, '0.9'], ['/practice', null, '0.9'],
            ['/library', null, '0.6'], ['/gatherings', null, '0.7'], ['/journal', null, '0.7'],
        ];
        foreach ($this->db->all('SELECT slug, updated_at FROM services WHERE is_published = 1') as $r) {
            $urls[] = ['/practice/' . $r['slug'], $r['updated_at'], '0.8'];
        }
        foreach ($this->db->all("SELECT slug, updated_at FROM events WHERE status = 'published'") as $r) {
            $urls[] = ['/gatherings/' . $r['slug'], $r['updated_at'], '0.6'];
        }
        foreach ($this->db->all("SELECT slug, updated_at FROM blog_posts WHERE status = 'published' AND published_at <= ?", [$this->clock->nowString()]) as $r) {
            $urls[] = ['/journal/' . $r['slug'], $r['updated_at'], '0.6'];
        }
        foreach ($this->db->all("SELECT slug, updated_at FROM pages WHERE type = 'legal' AND status = 'published'") as $r) {
            $urls[] = ['/legal/' . $r['slug'], $r['updated_at'], '0.3'];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$path, $updated, $priority]) {
            $xml .= '  <url><loc>' . htmlspecialchars($base . $path, ENT_XML1) . '</loc>'
                . ($updated ? '<lastmod>' . substr((string) $updated, 0, 10) . '</lastmod>' : '')
                . "<priority>{$priority}</priority></url>\n";
        }
        $xml .= '</urlset>';

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function robots(Request $request): Response
    {
        $base = (string) $this->config->get('app.frontend_url');
        $body = $this->config->isProduction()
            ? "User-agent: *\nDisallow: /admin\nDisallow: /consultation/\nDisallow: /private-access/status\nDisallow: /gatherings/registration/\nDisallow: /privacy/requests\nDisallow: /api/\nAllow: /\n\nSitemap: {$base}/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
