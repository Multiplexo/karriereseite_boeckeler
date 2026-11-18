import type { ImageMetadata } from 'astro';
import { getImage } from 'astro:assets';

// Echte Fotos liegen in src/assets/team/ und werden beim Build automatisch in AVIF/WebP mit srcset umgewandelt.
// Fehlt eine Datei, zeigen die Komponenten einen neutralen Platzhalter – das Layout bleibt gleich.
const dateien = import.meta.glob<{ default: ImageMetadata }>('../assets/team/*.{jpg,jpeg,png,webp}', { eager: true });

export function foto(name: string | null | undefined): ImageMetadata | null {
  if (!name) return null;
  const key = Object.keys(dateien).find((k) => k.split('/').pop()!.replace(/\.\w+$/, '') === name);
  return key ? dateien[key].default : null;
}

/** Kleine, quadratisch beschnittene Fassung (z. B. für die Danke-Seite im Funnel). */
export async function fotoUrl(name: string | null | undefined, breite = 448, hoehe = 560) {
  const f = foto(name);
  if (!f) return null;
  const img = await getImage({ src: f, width: breite, height: hoehe, format: 'webp', fit: 'cover', position: 'top' });
  return img.src;
}
