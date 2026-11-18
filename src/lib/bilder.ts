import type { ImageMetadata } from 'astro';
import { getImage } from 'astro:assets';
import bilderCfg from '../data/bilder.json';

// Echte Fotos liegen in src/assets/team/ und werden beim Build automatisch in AVIF/WebP mit srcset umgewandelt.
// <name>-nah.jpg sind die automatisch erzeugten Nahaufnahmen (scripts/bilder.mjs).
// Fehlt eine Datei, zeigen die Komponenten einen neutralen Platzhalter – das Layout bleibt gleich.
const dateien = import.meta.glob<{ default: ImageMetadata }>('../assets/team/*.{jpg,jpeg,png,webp}', { eager: true });
const personen = (bilderCfg as { personen?: Record<string, { fokus?: number[] }> }).personen ?? {};

function finde(name: string): ImageMetadata | null {
  const key = Object.keys(dateien).find((k) => k.split('/').pop()!.replace(/\.\w+$/, '') === name);
  return key ? dateien[key].default : null;
}

/** Foto laden; mit nah=true die Nahaufnahme (Kopf und Schultern), falls vorhanden. */
export function foto(name: string | null | undefined, nah = false): ImageMetadata | null {
  if (!name) return null;
  return (nah && finde(`${name}-nah`)) || finde(name);
}

/**
 * object-position, damit das Gesicht in einem Rahmen mit dem Seitenverhältnis `rahmen` (Breite/Höhe)
 * horizontal mittig und vertikal bei ca. 36 % sitzt (klassische Porträt-Kopfhöhe).
 * Bei Nahaufnahmen ist das Gesicht schon passend platziert.
 */
export function position(name: string | null | undefined, rahmen: number, nah = false): string {
  const bild = foto(name, nah);
  const fokus = name ? personen[name]?.fokus : undefined;
  if (!bild || !fokus || (nah && finde(`${name}-nah`))) return '50% 30%';
  const bildVerh = bild.width / bild.height;
  const [fx, fy] = [fokus[0] / 100, fokus[1] / 100];
  const prozent = (v: number) => `${Math.round(Math.max(0, Math.min(1, v)) * 100)}%`;
  if (rahmen >= bildVerh) {
    const sichtbar = bildVerh / rahmen; // sichtbarer Anteil der Bildhöhe
    if (sichtbar >= 1) return '50% 50%';
    const oben = Math.max(0, Math.min(1 - sichtbar, fy - 0.36 * sichtbar));
    return `50% ${prozent(oben / (1 - sichtbar))}`;
  }
  const sichtbar = rahmen / bildVerh; // sichtbarer Anteil der Bildbreite
  const links = Math.max(0, Math.min(1 - sichtbar, fx - 0.5 * sichtbar));
  return `${prozent(links / (1 - sichtbar))} 50%`;
}

/** Nahaufnahme als feste Datei (z. B. für die Danke-Seite im Funnel). */
export async function fotoUrl(name: string | null | undefined, breite = 448, hoehe = 560) {
  const f = foto(name, true);
  if (!f) return null;
  const img = await getImage({ src: f, width: breite, height: hoehe, format: 'webp', fit: 'cover', position: 'top' });
  return img.src;
}
