import type { APIRoute } from 'astro';
import { url } from '../lib/content';

// Hinweis: Läuft die Karriereseite unter /karriere, muss die Sitemap-Zeile zusätzlich in die robots.txt der Hauptdomain.
export const GET: APIRoute = ({ site }) =>
  new Response(
    process.env.PREVIEW === '1'
      ? 'User-agent: *\nDisallow: /\n'
      : `User-agent: *\nDisallow: ${url('bewerben/')}\nDisallow: ${url('api/')}\nSitemap: ${new URL(url('sitemap.xml'), site).href}\n`,
    { headers: { 'Content-Type': 'text/plain' } }
  );
