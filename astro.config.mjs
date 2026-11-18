// @ts-check
import { defineConfig } from 'astro/config';

// Pfad, unter dem die Karriereseite läuft.
//   /karriere  → laurentius-apo-erwitte.de/karriere (Standard)
//   /          → karriere.laurentius-apo-erwitte.de (Subdomain)
const base = process.env.BASE_PATH ?? '/karriere';
const site = process.env.SITE_URL ?? 'https://www.laurentius-apo-erwitte.de';

export default defineConfig({
  site,
  base,
  trailingSlash: 'always',
  build: {
    format: 'directory',
    inlineStylesheets: 'always',
  },
  compressHTML: true,
  devToolbar: { enabled: false },
});
