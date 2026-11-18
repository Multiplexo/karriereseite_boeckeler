import fs from 'node:fs';
import { site, url, gehaltSichtbar, type Stelle } from './content';

// Quadratisches Logo (mind. 112×112 px) für Google for Jobs: public/img/logo-google.png
const googleLogo = fs.existsSync(new URL('../../public/img/logo-google.png', import.meta.url));

const esc = (s: string) => s.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c]!);

export function jobPosting(s: Stelle, siteUrl: URL | undefined) {
  const abs = (p: string) => new URL(url(p), siteUrl).href;
  const description =
    `<p>${esc(s.kernbotschaft)}</p>` +
    `<p><strong>Umfang:</strong> ${esc(s.stundenumfang)} · <strong>Start:</strong> ${esc(s.startdatum)}</p>` +
    `<h3>Deine Aufgaben</h3><ul>${s.aufgaben.map((a) => `<li>${esc(a)}</li>`).join('')}</ul>` +
    `<h3>Das bringst du mit</h3><ul>${s.anforderungen.map((a) => `<li>${esc(a)}</li>`).join('')}</ul>` +
    `<p>Arbeitszeiten: Mo–Fr bis spätestens 18:00 Uhr, samstags bis 12:30 Uhr, sonntags geschlossen. Bewerbung in 60 Sekunden ohne Anschreiben.</p>`;
  const g = gehaltSichtbar(s);
  const data: Record<string, unknown> = {
    '@context': 'https://schema.org/',
    '@type': 'JobPosting',
    title: s.titel_seo.replace(/ – .*$/, ''),
    description,
    datePosted: s.date_posted,
    validThrough: `${s.valid_through}T23:59:59+01:00`,
    employmentType: s.employment_type,
    hiringOrganization: {
      '@type': 'Organization',
      name: `${site.name} Erwitte`,
      sameAs: site.website,
      ...(googleLogo ? { logo: abs('img/logo-google.png') } : {}),
    },
    jobLocation: {
      '@type': 'Place',
      address: {
        '@type': 'PostalAddress',
        streetAddress: site.adresse.strasse,
        addressLocality: site.adresse.ort,
        postalCode: site.adresse.plz,
        addressRegion: site.adresse.region,
        addressCountry: site.adresse.land,
      },
    },
    directApply: true,
    url: abs(`${s.slug}/`),
  };
  if (g && g.min) {
    data.baseSalary = {
      '@type': 'MonetaryAmount',
      currency: 'EUR',
      value: { '@type': 'QuantitativeValue', minValue: g.min, maxValue: g.max ?? g.min, unitText: g.einheit ?? 'MONTH' },
    };
  }
  return data;
}
