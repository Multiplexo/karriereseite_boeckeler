// @ts-check
import { defineConfig } from 'astro/config';

// Adresse der Karriereseite (Standard: eigene Subdomain).
// Alternative Unterseite der Homepage: SITE_URL=https://www.laurentius-apo-erwitte.de BASE_PATH=/karriere
const base = process.env.BASE_PATH ?? '/';
const site = process.env.SITE_URL ?? 'https://karriere.laurentius-apotheke-erwitte.de';

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
