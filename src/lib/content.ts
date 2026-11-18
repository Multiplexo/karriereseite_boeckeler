import fs from 'node:fs';
import path from 'node:path';
import site from '../data/site.json';
import stellenData from '../data/stellen.json';
import inhalte from '../data/inhalte.json';

/** Vorschau-Build zeigt auch nicht freigegebene Entwürfe (mit Markierung). */
export const PREVIEW = process.env.PREVIEW === '1' || (site as { vorschau_modus?: boolean }).vorschau_modus === true;

export function sichtbar<T extends { freigegeben?: boolean }>(items: T[]): T[] {
  return items.filter((i) => i.freigegeben !== false || PREVIEW);
}

export type Stelle = (typeof stellenData.stellen)[number] & {
  kurz?: string; einstieg?: string; foto?: string;
  gehalt: null | { text: string; min?: number; max?: number; einheit?: string; freigegeben?: boolean };
};

export const alleStellen = stellenData.stellen as Stelle[];
/** Stellen mit Status "entwurf" sind nur im Vorschau-Build sichtbar. */
export const istOffen = (s: { status: string }) => s.status === 'offen' || (PREVIEW && s.status === 'entwurf');
export const offeneStellen = alleStellen.filter(istOffen);
/** Für Seiten-Generierung: alles außer Entwürfen (außer in der Vorschau). */
export const seitenStellen = alleStellen.filter((s) => s.status !== 'entwurf' || PREVIEW);
export const initiativ = stellenData.initiativ;

export function gehaltSichtbar(s: Stelle) {
  return s.gehalt && (s.gehalt.freigegeben !== false || PREVIEW) ? s.gehalt : null;
}

export { site, inhalte };

const BASE = import.meta.env.BASE_URL.replace(/\/?$/, '/');
/** Pfad innerhalb der Karriereseite, z. B. url('pta/') → /karriere/pta/ */
export const url = (p = '') => BASE + p.replace(/^\//, '');

export const telHref = 'tel:' + site.telefon.replace(/\s+/g, '');
export const waHref = site.whatsapp
  ? `https://wa.me/${site.whatsapp}?text=${encodeURIComponent('Hallo, ich interessiere mich für eine Stelle in der Laurentius Apotheke.')}`
  : null;
export const mailtoBewerbung = (stelle?: string) =>
  `mailto:${site.email_bewerbung}?subject=${encodeURIComponent('Bewerbung' + (stelle ? ' ' + stelle : ''))}`;

/** Existiert eine Datei unter public/? (Build läuft immer im Projektordner) */
export const inPublic = (f: string) => fs.existsSync(path.join(process.cwd(), 'public', f));

export const PRIMARY_CTA = 'In 60 Sekunden bewerben';
