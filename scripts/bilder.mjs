// Bereitet Rohfotos aus src/assets/roh/ gemäß src/data/bilder.json auf (Zuschnitt, Farbkorrektur) und
// schreibt das Ergebnis nach src/assets/team/. Fehlt ein Rohfoto, wird es übersprungen.
import fs from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const cfg = JSON.parse(fs.readFileSync(path.join(root, 'src/data/bilder.json'), 'utf8'));
fs.mkdirSync(path.join(root, 'src/assets/team'), { recursive: true });

// Fast-weiße Pixel (auch JPG-Rauschen) transparent machen, mit weicher Kante gegen Treppchen.
async function weissTransparent(buf) {
  const { data, info } = await sharp(buf).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
  for (let i = 0; i < data.length; i += 4) {
    const hell = Math.min(data[i], data[i + 1], data[i + 2]);
    if (hell >= 236) data[i + 3] = 0;
    else if (hell > 200) data[i + 3] = Math.round(data[i + 3] * (236 - hell) / 36);
  }
  return sharp(data, { raw: info }).png().toBuffer();
}

const rohDatei = (name, endungen = ['jpg', 'jpeg', 'png', 'webp']) =>
  endungen.map((e) => path.join(root, 'src/assets/roh', `${name}.${e}`)).find((p) => fs.existsSync(p));

// ---- Logo → Favicon, Apple-Touch-Icon, Header-Zeichen, Google-for-Jobs-Logo ----
const logoCfg = cfg.logo;
const generiert = ['favicon-32.png', 'favicon-192.png', 'apple-touch-icon.png', 'img/logo-zeichen.png', 'img/logo-google.png'];
const logo = logoCfg && rohDatei(logoCfg.quelle, ['svg', 'png', 'jpg', 'jpeg', 'webp']);
if (logo) {
  fs.mkdirSync(path.join(root, 'public/img'), { recursive: true });
  const dichte = logo.endsWith('.svg') ? { density: 600 } : {};
  const meta = await sharp(logo, dichte).metadata();
  const z = logoCfg.zeichen_bereich_prozent;
  // 1) Bereich mit dem A ausschneiden und auf den Inhalt zuschneiden (weißer/transparenter Rand wird entfernt)
  const bereich = await sharp(logo, dichte)
    .extract({ left: 0, top: Math.round((meta.height * z.oben) / 100), width: meta.width, height: Math.round((meta.height * z.hoehe) / 100) })
    .png().toBuffer();
  // Rand entfernen und Weiß transparent machen (kein weißer Kasten auf farbigem Hintergrund)
  const zeichen = await weissTransparent(await sharp(bereich).trim({ threshold: 20 }).png().toBuffer());
  // Quadratisch einpassen; mit Hintergrundfarbe (Apple-Icon, Google) oder transparent (Favicon).
  const quadrat = async (buf, groesse, rand, bg) => {
    const hinten = bg ?? { r: 0, g: 0, b: 0, alpha: 0 };
    const basis = bg ? sharp(buf).flatten({ background: bg }) : sharp(buf); // flatten() ohne Farbe würde schwarz füllen
    const innen = await basis.resize(groesse - 2 * rand, groesse - 2 * rand, { fit: 'contain', background: hinten }).png().toBuffer();
    return sharp(innen).extend({ top: rand, bottom: rand, left: rand, right: rand, background: hinten }).png().toBuffer();
  };
  const weiss = logoCfg.hintergrund ?? '#ffffff';
  fs.writeFileSync(path.join(root, 'public/favicon-32.png'), await quadrat(zeichen, 32, 1));
  fs.writeFileSync(path.join(root, 'public/favicon-192.png'), await quadrat(zeichen, 192, 12));
  fs.writeFileSync(path.join(root, 'public/apple-touch-icon.png'), await quadrat(zeichen, 180, 18, weiss));
  fs.writeFileSync(path.join(root, 'public/img/logo-zeichen.png'), await sharp(zeichen).resize({ height: 96, withoutEnlargement: true }).png().toBuffer());
  // 2) Ganzes Logo quadratisch auf Weiß (Google for Jobs, mind. 112×112)
  const ganz = await sharp(logo, dichte).trim({ threshold: 20 }).png().toBuffer();
  fs.writeFileSync(path.join(root, 'public/img/logo-google.png'), await quadrat(ganz, 300, 24, weiss));
  console.log(`[bilder] logo: Favicon, Apple-Touch-Icon, Header-Zeichen und Google-Logo erzeugt`);
} else {
  for (const f of generiert) fs.rmSync(path.join(root, 'public', f), { force: true });
  console.log('[bilder] logo: kein Logo in src/assets/roh/ – Favicon-Platzhalter wird verwendet');
}

// ---- Nahaufnahmen (Kopf und Schultern, 4:5) aus den Porträts ----
// Der Kopf füllt ca. 42 % der Bildhöhe, darüber 12 % Luft; horizontal auf die Gesichtsmitte zentriert.
for (const [name, p] of Object.entries(cfg.personen ?? {})) {
  if (name.startsWith('_') || !p.kopf) continue;
  const quelle = path.join(root, 'src/assets/team', `${name}.jpg`);
  if (!fs.existsSync(quelle)) continue;
  const m = await sharp(quelle).metadata();
  const kopfH = (p.kopf[1] - p.kopf[0]) / 100;
  let h = Math.min(1, kopfH / 0.42) * m.height;
  let w = h * 0.8;
  if (w > m.width) { w = m.width; h = w / 0.8; }
  let left = (p.fokus[0] / 100) * m.width - w / 2;
  let top = (p.kopf[0] / 100) * m.height - 0.12 * h;
  left = Math.max(0, Math.min(m.width - w, left));
  top = Math.max(0, Math.min(m.height - h, top));
  await sharp(quelle)
    .extract({ left: Math.round(left), top: Math.round(top), width: Math.round(w), height: Math.round(h) })
    .resize({ width: Math.min(1100, Math.round(w)) })
    .jpeg({ quality: 86, mozjpeg: true })
    .toFile(path.join(root, 'src/assets/team', `${name}-nah.jpg`));
  console.log(`[bilder] ${name}: Nahaufnahme ${Math.round(w)}×${Math.round(h)}`);
}

for (const [key, b] of Object.entries(cfg)) {
  if (key.startsWith('_') || key === 'logo' || key === 'personen') continue;
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
