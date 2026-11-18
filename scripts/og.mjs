// Erzeugt Open-Graph-Bilder (1200×630) je Stelle nach public/og/. Aufruf: npm run og
// Liegt src/assets/team/dorothee-boeckeler.jpg vor, wird das Porträt eingebunden.
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright'); // global oder als devDependency installiert

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const { stellen } = JSON.parse(fs.readFileSync(path.join(root, 'src/data/stellen.json'), 'utf8'));
const portraitPfad = ['jpg', 'jpeg', 'png', 'webp'].map((e) => path.join(root, `src/assets/team/dorothee-boeckeler.${e}`)).find(fs.existsSync);
const portrait = portraitPfad ? `data:image/jpeg;base64,${fs.readFileSync(portraitPfad).toString('base64')}` : null;

const html = (titel, zeile) => `<!doctype html><html><head><meta charset="utf-8"><style>
*{margin:0;box-sizing:border-box} body{width:1200px;height:630px;font-family:system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:#faf8f6;display:flex;overflow:hidden}
.l{flex:1;padding:64px 56px;display:flex;flex-direction:column;border-top:14px solid #e2001a}
.brand{color:#e2001a;font-weight:800;letter-spacing:.05em;font-size:30px;line-height:1.05}
.brand small{display:block;color:#5a5552;font-weight:500;letter-spacing:0;font-size:22px;margin-top:6px}
h1{font-size:${titel.length > 22 ? 62 : 76}px;line-height:1.05;color:#1f1b1a;margin-top:auto;letter-spacing:-.02em}
p{font-size:30px;color:#5a5552;margin-top:18px}
.chips{display:flex;gap:10px;margin-top:28px;flex-wrap:wrap}.chips span{background:#fbeceb;color:#a3121f;font-weight:700;font-size:22px;padding:8px 16px;border-radius:99px}
.r{width:${portrait ? 420 : 0}px;background:#fbeceb url(${portrait}) center 18%/cover}
</style></head><body><div class="l"><div class="brand">LAURENTIUS APOTHEKE<small>Karriere · Erwitte</small></div>
<h1>${titel}</h1><p>${zeile}</p><div class="chips"><span>Feierabend spätestens 18 Uhr</span><span>Bewerben in 60 Sekunden</span></div></div>
${portrait ? '<div class="r"></div>' : ''}</body></html>`;

const b = await chromium.launch();
const p = await b.newPage({ viewport: { width: 1200, height: 630 } });
const jobs = [{ slug: 'karriere', titel: 'Wir suchen dich (m/w/d)', zeile: 'PTA · PKA · Apotheker/in · Ausbildung' },
  ...stellen.map((s) => ({ slug: s.slug, titel: `${s.titel} (m/w/d)`, zeile: 'in Erwitte gesucht' }))];
for (const j of jobs) {
  await p.setContent(html(j.titel, j.zeile));
  await p.screenshot({ path: path.join(root, 'public/og', `${j.slug}.png`) });
  console.log('og/' + j.slug + '.png');
}
await b.close();
