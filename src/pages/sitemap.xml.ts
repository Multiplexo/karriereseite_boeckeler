import type { APIRoute } from 'astro';
import { offeneStellen, url } from '../lib/content';

export const GET: APIRoute = ({ site }) => {
  const abs = (p: string) => new URL(url(p), site).href;
  const eintraege = [
    { loc: abs(''), lastmod: undefined as string | undefined },
    ...offeneStellen.map((s) => ({ loc: abs(`${s.slug}/`), lastmod: s.date_posted })),
  ];
  const body = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${eintraege.map((e) => `  <url><loc>${e.loc}</loc>${e.lastmod ? `<lastmod>${e.lastmod}</lastmod>` : ''}</url>`).join('\n')}
</urlset>`;
  return new Response(body, { headers: { 'Content-Type': 'application/xml' } });
};
