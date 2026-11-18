// Bereitet Rohfotos aus src/assets/roh/ gemäß src/data/bilder.json auf (Zuschnitt, Farbkorrektur) und
// schreibt das Ergebnis nach src/assets/team/. Fehlt ein Rohfoto, wird es übersprungen.
import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const cfg = JSON.parse(fs.readFileSync(path.join(root, 'src/data/bilder.json'), 'utf8'));
fs.mkdirSync(path.join(root, 'src/assets/team'), { recursive: true });

for (const [key, b] of Object.entries(cfg)) {
  if (key.startsWith('_')) continue;
  const roh = ['jpg', 'jpeg', 'png', 'webp'].map((e) => path.join(root, 'src/assets/roh', `${b.quelle}.${e}`)).find((p) => fs.existsSync(p));
  if (!roh) { console.log(`[bilder] ${key}: kein Rohfoto in src/assets/roh/ – übersprungen`); continue; }
  const meta = await sharp(roh).metadata();
  const a = b.ausschnitt_prozent;
  const region = {
    left: Math.round((meta.width * a.links) / 100),
    top: Math.round((meta.height * a.oben) / 100),
    width: Math.round((meta.width * a.breite) / 100),
    height: Math.round((meta.height * a.hoehe) / 100),
  };
  region.width = Math.min(region.width, meta.width - region.left);
  region.height = Math.min(region.height, meta.height - region.top);
  const k = b.kontrast ?? 1;
  const w = b.waerme ?? 0;
  const ziel = path.join(root, 'src/assets/team', `${b.ziel}.jpg`);
  await sharp(roh)
    .rotate()
    .extract(region)
    .modulate({ saturation: b.saettigung ?? 1, brightness: b.helligkeit ?? 1 })
    .linear([k, k, k], [128 * (1 - k) + w, 128 * (1 - k), 128 * (1 - k) - w]) // Kontrast dämpfen (HDR-Look) + leicht wärmer
    .jpeg({ quality: 88, mozjpeg: true })
    .toFile(ziel);
  console.log(`[bilder] ${key}: ${meta.width}×${meta.height} → ${region.width}×${region.height} (${path.relative(root, ziel)})`);
}
