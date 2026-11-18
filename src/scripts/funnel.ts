// Bewerberfunnel: eine Frage pro Screen, Kontaktdaten am Ende.
// Punkte und Status werden ausschließlich serverseitig berechnet (api/bewerbung.php).
import { pfad, aufloesen, brauchtHinweis } from '../lib/funnel-logic.js';
import { track, quelle } from './tracking';

type Option = { wert: string; label: string; kurz?: string; match?: string };
type Frage = {
  id: string; typ: 'einzel' | 'mehrfach' | 'kontakt'; frage: string; hinweis?: string; ermutigung?: string;
  optionen?: Option[]; max?: number; rollen?: string[]; wertung?: string;
  zusatz?: { id: string; label: string; muster: string };
  freitext?: { label: string; max: number };
  rueckrufzeiten?: string[];
  varianten?: Record<string, { frage: string; optionen: Option[]; freitext?: { label: string; max: number } }>;
};
type Config = {
  rollen: Record<string, { label: string; abschluss_hinweis?: string }>;
  fragen: Frage[];
  offen: string[];
  api: string; datenschutz: string; start: string; tel: string; telAnzeige: string; mailto: string;
  inhaberin: { name: string; rolle: string; foto: string | null };
};
type Kontakt = { vorname: string; nachname: string; telefon: string; email: string; rueckruf: string; nachricht: string; datenschutz: boolean; talentpool: boolean };
type State = {
  rolle: string | null;
  vorbelegt: boolean;
  screen: string;              // Fragen-ID, 'hinweis' oder 'danke'
  antworten: Record<string, string | string[]>;
  zusatz: Record<string, string>;
  kontakt: Kontakt;
  token?: string;
  gestartet?: boolean;
  besucht: string[];
};

const KEY = 'lap_funnel_v1';
const MAX_MB = 10;
const ERLAUBT = ['pdf', 'jpg', 'jpeg', 'png', 'docx'];

const esc = (s: string) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
const icon = {
  back: '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>',
  check: '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5 10 17 19 7"/></svg>',
  phone: '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2z"/></svg>',
};

function leererKontakt(): Kontakt {
  return { vorname: '', nachname: '', telefon: '', email: '', rueckruf: '', nachricht: '', datenschutz: false, talentpool: false };
}
function laden(): State | null {
  try { return JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch { return null; }
}
function speichern(s: State) {
  try { sessionStorage.setItem(KEY, JSON.stringify(s)); } catch { /* privat/blockiert: Funnel läuft trotzdem */ }
}
function loeschen() {
  try { sessionStorage.removeItem(KEY); } catch { /* egal */ }
}

export function startFunnel() {
  const root = document.getElementById('funnel');
  const cfgEl = document.getElementById('funnel-config');
  if (!root || !cfgEl) return;
  const cfg: Config = JSON.parse(cfgEl.textContent || '{}');
  const fragen = new Map(cfg.fragen.map((f) => [f.id, f]));

  const param = new URLSearchParams(location.search).get('stelle');
  const preset = param && cfg.offen.includes(param) ? param : null;

  let st: State = laden() ?? { rolle: null, vorbelegt: false, screen: '', antworten: {}, zusatz: {}, kontakt: leererKontakt(), besucht: [] };
  if (st.screen === 'danke') st = { rolle: null, vorbelegt: false, screen: '', antworten: {}, zusatz: {}, kontakt: leererKontakt(), besucht: [] };
  // Neuer Einstieg mit anderer vorbelegter Stelle → neu beginnen.
  if (preset && st.rolle !== preset) {
    st = { rolle: preset, vorbelegt: true, screen: '', antworten: { stelle: preset }, zusatz: {}, kontakt: st.kontakt ?? leererKontakt(), besucht: [], token: st.token };
  }
  if (st.rolle && !cfg.offen.includes(st.rolle)) { st.rolle = null; st.antworten = {}; st.vorbelegt = false; }

  const ladeZeit = Date.now();
  let datei: File | null = null;
  let sendet = false;

  const liste = () => pfad(cfg, st.rolle, { stelleVorbelegt: st.vorbelegt });
  if (!st.screen || (!liste().includes(st.screen) && st.screen !== 'hinweis')) st.screen = liste()[0];

  // Zeitfallen-Token vom Server holen (signierter Zeitstempel).
  async function holeToken() {
    try {
      const r = await fetch(cfg.api + '?token=1', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const j = await r.json();
      if (j.token) { st.token = j.token; speichern(st); }
    } catch { /* wird beim Absenden erneut versucht */ }
  }
  // Vorhandenes Token behalten (z. B. nach Reload), sonst würde die Zeitfalle echte Bewerbungen ausbremsen.
  if (!st.token) holeToken();

  const live = document.createElement('div');
  live.className = 'sr-only';
  live.setAttribute('aria-live', 'polite');
  root.after(live);

  function gehe(screen: string, opts: { zurueck?: boolean } = {}) {
    st.screen = screen;
    if (!opts.zurueck && !st.besucht.includes(screen)) {
      st.besucht.push(screen);
      const idx = liste().indexOf(screen);
      if (idx >= 0) track(`funnel_step_${idx + 1}`, { frage: screen, stelle: st.rolle ?? 'offen' });
    }
    speichern(st);
    render(true);
  }

  function weiter() {
    const l = liste();
    const i = l.indexOf(st.screen);
    if (i >= 0 && i < l.length - 1) gehe(l[i + 1]);
  }

  function zurueck() {
    if (st.screen === 'hinweis') return gehe(abschlussId(), { zurueck: true });
    const l = liste();
    const i = l.indexOf(st.screen);
    if (i > 0) return gehe(l[i - 1], { zurueck: true });
    // Erster Schritt bei vorbelegter Stelle → Stellenauswahl anbieten.
    if (st.vorbelegt) { st.vorbelegt = false; return gehe('stelle', { zurueck: true }); }
    location.href = cfg.start;
  }

  function abschlussId() {
    return liste().find((id) => fragen.get(id)?.wertung === 'abschluss') ?? liste()[0];
  }

  function fortschritt() {
    const l = liste();
    const i = st.screen === 'hinweis' ? l.indexOf(abschlussId()) : l.indexOf(st.screen);
    return { nr: i + 1, gesamt: l.length };
  }

  function rahmen(inhalt: string, { zurueckSichtbar = true } = {}) {
    const { nr, gesamt } = fortschritt();
    const pct = Math.round(((nr - 1) / gesamt) * 100);
    return `
      <div class="funnel__top">
        <button type="button" class="funnel__back" data-act="zurueck" aria-label="Zurück" ${zurueckSichtbar ? '' : 'hidden'}>${icon.back}</button>
        <div class="funnel__progress">
          <div class="funnel__bar" role="progressbar" aria-label="Fortschritt" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}"><span style="width:${Math.max(pct, 4)}%"></span></div>
          <div class="funnel__count">Schritt ${nr} von ${gesamt}</div>
        </div>
      </div>
      ${inhalt}`;
  }

  function render(fokus = false) {
    root!.dataset.state = st.screen;
    if (st.screen === 'hinweis') root!.innerHTML = renderHinweis();
    else if (st.screen === 'danke') return;
    else {
      const f = aufloesen(fragen.get(st.screen)!, st.rolle) as Frage;
      if (f.typ === 'kontakt') root!.innerHTML = renderKontakt(f);
      else root!.innerHTML = renderFrage(f);
      if (f.typ === 'kontakt') bindKontakt();
    }
    if (fokus) {
      const h = root!.querySelector<HTMLElement>('h1');
      h?.focus({ preventScroll: true });
      root!.scrollIntoView({ block: 'start', behavior: 'instant' as ScrollBehavior });
      window.scrollTo({ top: 0 });
      const { nr, gesamt } = fortschritt();
      live.textContent = `Schritt ${nr} von ${gesamt}: ${h?.textContent ?? ''}`;
    }
  }

  function renderFrage(f: Frage) {
    const mehrfach = f.typ === 'mehrfach';
    const wert = st.antworten[f.id];
    const gewaehlt = (w: string) => (mehrfach ? ((wert as string[]) ?? []).includes(w) : wert === w);
    const anzahl = mehrfach ? ((wert as string[]) ?? []).length : 0;
    const voll = mehrfach && f.max != null && anzahl >= f.max;
    const sichtbareOptionen = (f.optionen ?? []).filter((o) => f.id !== 'stelle' || cfg.offen.includes(o.wert));
    const optionen = sichtbareOptionen.map((o) => {
      const an = gewaehlt(o.wert);
      const gesperrt = voll && !an;
      return `<button type="button" class="tile${mehrfach ? ' tile--multi' : ''}" data-wert="${esc(o.wert)}" aria-pressed="${an}" ${gesperrt ? 'aria-disabled="true"' : ''}>
        <span class="tile__check">${an ? icon.check : ''}</span><span>${esc(o.label)}</span></button>`;
    }).join('');
    const zusatz = f.zusatz
      ? `<div class="field"><label for="z-${f.zusatz.id}">${esc(f.zusatz.label)}</label>
         <input id="z-${f.zusatz.id}" type="text" inputmode="numeric" autocomplete="postal-code" maxlength="5" value="${esc(st.zusatz[f.zusatz.id] ?? '')}" aria-describedby="z-err"><div class="field__error" id="z-err"></div></div>`
      : '';
    const freitext = f.freitext
      ? `<div class="field"><label for="z-freitext">${esc(f.freitext.label)}</label>
         <textarea id="z-freitext" maxlength="${f.freitext.max}">${esc(st.zusatz.freitext ?? '')}</textarea></div>`
      : '';
    const manuell = mehrfach || f.zusatz || f.freitext || wert != null;
    const grid = sichtbareOptionen.length >= 4 && !sichtbareOptionen.some((o) => o.label.length > 28);
    return rahmen(`
      ${f.ermutigung ? `<span class="funnel__cheer">${esc(f.ermutigung)}</span>` : ''}
      <h1 tabindex="-1" id="frage-h">${esc(f.frage)}</h1>
      ${f.hinweis ? `<p class="funnel__hint" id="frage-hint">${esc(f.hinweis)}</p>` : ''}
      ${mehrfach ? `<p class="sr-only" aria-live="polite">${anzahl} von ${f.max} ausgewählt</p>` : ''}
      <div class="tiles${grid ? ' tiles--grid' : ''}" role="group" aria-labelledby="frage-h">${optionen}</div>
      ${zusatz}${freitext}
      ${manuell ? `<div class="funnel__actions"><button type="button" class="btn btn--primary" data-act="weiter">${mehrfach && anzahl === 0 ? 'Überspringen' : 'Weiter'}</button></div>` : ''}
    `, { zurueckSichtbar: !(st.screen === liste()[0] && !st.vorbelegt) });
  }

  function renderHinweis() {
    const r = cfg.rollen[st.rolle ?? ''];
    return rahmen(`
      <h1 tabindex="-1">Für diese Stelle brauchen wir ${esc(r?.abschluss_hinweis ?? 'einen passenden Abschluss')} – aber vielleicht passt etwas anderes?</h1>
      <p>Du kannst trotzdem weitermachen – wir schauen uns jede Bewerbung an. Oder du wählst eine andere Stelle bzw. bewirbst dich initiativ.</p>
      <div class="funnel__actions">
        <button type="button" class="btn btn--primary" data-act="andere">Andere Stelle wählen</button>
        <button type="button" class="btn btn--outline" data-act="trotzdem">Trotzdem weiter</button>
      </div>`);
  }

  function renderKontakt(f: Frage) {
    const k = st.kontakt;
    const zeiten = (f.rueckrufzeiten ?? []).map((z) => `<option ${k.rueckruf === z ? 'selected' : ''}>${esc(z)}</option>`).join('');
    return rahmen(`
      <span class="funnel__cheer">Letzter Schritt</span>
      <h1 tabindex="-1">${esc(f.frage)}</h1>
      ${f.hinweis ? `<p class="funnel__hint">${esc(f.hinweis)}</p>` : ''}
      <form id="kontakt" novalidate>
        <div class="alert" id="form-alert" role="alert" tabindex="-1" hidden></div>
        <div class="row2">
          ${feld('vorname', 'Vorname', 'text', 'given-name', k.vorname, true)}
          ${feld('nachname', 'Nachname', 'text', 'family-name', k.nachname, true)}
        </div>
        ${feld('telefon', 'Telefon', 'tel', 'tel', k.telefon, true)}
        ${feld('email', 'E-Mail', 'email', 'email', k.email, true)}
        <div class="field"><label for="f-rueckruf">Beste Rückrufzeit <span class="opt">(optional)</span></label>
          <select id="f-rueckruf" name="rueckruf">${zeiten}</select></div>
        <div class="field"><span id="up-label" style="display:block;font-weight:600;margin-bottom:6px">Lebenslauf <span class="opt">(optional – PDF, JPG, PNG, DOCX, max. ${MAX_MB} MB)</span></span>
          <div class="upload">
            <input id="f-datei" name="lebenslauf" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx,application/pdf,image/jpeg,image/png" aria-labelledby="up-label" aria-describedby="err-datei">
            <div class="upload__progress" hidden><span></span></div>
          </div>
          <div class="field__error" id="err-datei"></div></div>
        <div class="field"><label for="f-nachricht">Nachricht <span class="opt">(optional)</span></label>
          <textarea id="f-nachricht" name="nachricht" maxlength="2000">${esc(k.nachricht)}</textarea></div>
        <div class="hp" aria-hidden="true"><label for="f-website">Website</label><input id="f-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
        <div class="check"><input id="f-datenschutz" name="datenschutz" type="checkbox" ${k.datenschutz ? 'checked' : ''} aria-describedby="err-datenschutz" required>
          <label for="f-datenschutz">Ich habe die <a href="${cfg.datenschutz}" target="_blank" rel="noopener">Datenschutzhinweise für Bewerber/innen</a> gelesen. *</label></div>
        <div class="field__error" id="err-datenschutz" style="margin:-8px 0 12px"></div>
        <div class="check"><input id="f-talentpool" name="talentpool" type="checkbox" ${k.talentpool ? 'checked' : ''}>
          <label for="f-talentpool">Freiwillig: Ihr dürft meine Bewerbung 12 Monate aufbewahren und mich bei passenden Stellen erneut ansprechen (jederzeit widerrufbar).</label></div>
        <button type="submit" class="btn btn--primary btn--block" id="senden">Bewerbung absenden</button>
        <p class="microtext" style="text-align:center">Kein Anschreiben nötig · Wir melden uns innerhalb von 48 h</p>
      </form>`);
  }

  function feld(name: string, label: string, type: string, ac: string, wert: string, pflicht: boolean) {
    return `<div class="field"><label for="f-${name}">${label}${pflicht ? ' *' : ''}</label>
      <input id="f-${name}" name="${name}" type="${type}" autocomplete="${ac}" value="${esc(wert)}" ${pflicht ? 'required aria-required="true"' : ''} aria-describedby="err-${name}" ${type === 'tel' ? 'inputmode="tel"' : ''} maxlength="${type === 'email' ? 120 : 80}">
      <div class="field__error" id="err-${name}"></div></div>`;
  }

  function pruefe(name: string, wert: string | boolean): string {
    switch (name) {
      case 'vorname': return String(wert).trim() ? '' : 'Bitte gib deinen Vornamen ein.';
      case 'nachname': return String(wert).trim() ? '' : 'Bitte gib deinen Nachnamen ein.';
      case 'telefon': {
        const v = String(wert).trim();
        if (!v) return 'Bitte gib deine Telefonnummer ein, damit wir dich zurückrufen können.';
        return /^[+0-9 ()\/-]+$/.test(v) && v.replace(/\D/g, '').length >= 6 ? '' : 'Diese Telefonnummer sieht unvollständig aus – z. B. 0151 2345678.';
      }
      case 'email': {
        const v = String(wert).trim();
        if (!v) return 'Bitte gib deine E-Mail-Adresse ein.';
        return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) ? '' : 'Bitte prüfe deine E-Mail-Adresse – z. B. name@beispiel.de.';
      }
      case 'datenschutz': return wert ? '' : 'Bitte bestätige, dass du die Datenschutzhinweise gelesen hast.';
      default: return '';
    }
  }

  function zeigeFehler(name: string, msg: string) {
    const el = root!.querySelector<HTMLElement>(`#err-${name}`);
    const input = root!.querySelector<HTMLInputElement>(`#f-${name}`);
    if (el) el.textContent = msg;
    if (input) input.setAttribute('aria-invalid', msg ? 'true' : 'false');
  }

  function pruefeDatei(f: File | null): string {
    if (!f) return '';
    const ext = f.name.split('.').pop()?.toLowerCase() ?? '';
    if (!ERLAUBT.includes(ext)) return 'Dieser Dateityp geht leider nicht. Erlaubt sind PDF, JPG, PNG und DOCX.';
    if (f.size > MAX_MB * 1024 * 1024) return `Die Datei ist größer als ${MAX_MB} MB. Du kannst sie auch weglassen – der Lebenslauf ist freiwillig.`;
    return '';
  }

  function bindKontakt() {
    const form = root!.querySelector<HTMLFormElement>('#kontakt')!;
    const sync = () => {
      const fd = new FormData(form);
      st.kontakt = {
        vorname: String(fd.get('vorname') ?? ''), nachname: String(fd.get('nachname') ?? ''),
        telefon: String(fd.get('telefon') ?? ''), email: String(fd.get('email') ?? ''),
        rueckruf: String(fd.get('rueckruf') ?? ''), nachricht: String(fd.get('nachricht') ?? ''),
        datenschutz: fd.get('datenschutz') === 'on', talentpool: fd.get('talentpool') === 'on',
      };
      speichern(st);
    };
    form.addEventListener('input', sync);
    form.addEventListener('change', sync);
    // Inline-Validierung beim Verlassen des Feldes
    form.addEventListener('focusout', (e) => {
      const t = e.target as HTMLInputElement;
      if (!t.name || t.name === 'lebenslauf' || t.name === 'website') return;
      if (t.type === 'checkbox') return;
      if (t.value === '' && !t.dataset.beruehrt) { t.dataset.beruehrt = '1'; return; }
      zeigeFehler(t.name, pruefe(t.name, t.value));
    });
    form.querySelector('#f-datenschutz')!.addEventListener('change', (e) => zeigeFehler('datenschutz', pruefe('datenschutz', (e.target as HTMLInputElement).checked)));
    const dateiInput = form.querySelector<HTMLInputElement>('#f-datei')!;
    if (datei) {
      try { const dt = new DataTransfer(); dt.items.add(datei); dateiInput.files = dt.files; } catch { /* älterer Browser */ }
    }
    dateiInput.addEventListener('change', () => {
      const f = dateiInput.files?.[0] ?? null;
      const msg = pruefeDatei(f);
      zeigeFehler('datei', msg);
      if (msg) { dateiInput.value = ''; datei = null; } else datei = f;
    });
    form.addEventListener('submit', (e) => { e.preventDefault(); sync(); absenden(form); });
  }

  function absenden(form: HTMLFormElement) {
    if (sendet) return;
    const k = st.kontakt;
    const fehler: [string, string][] = (['vorname', 'nachname', 'telefon', 'email', 'datenschutz'] as const)
      .map((n) => [n, pruefe(n, k[n])] as [string, string]);
    fehler.forEach(([n, m]) => zeigeFehler(n, m));
    const ersterFehler = fehler.find(([, m]) => m);
    if (ersterFehler) {
      root!.querySelector<HTMLElement>(`#f-${ersterFehler[0]}`)?.focus();
      return;
    }
    const alert = root!.querySelector<HTMLElement>('#form-alert')!;
    alert.hidden = true;
    const btn = root!.querySelector<HTMLButtonElement>('#senden')!;
    const bar = root!.querySelector<HTMLElement>('.upload__progress')!;
    sendet = true;
    btn.disabled = true;
    btn.textContent = 'Wird gesendet …';

    const daten = {
      stelle: st.rolle,
      antworten: st.antworten,
      zusatz: st.zusatz,
      kontakt: k,
      quelle: { ...quelle(), seite: location.pathname + location.search },
      token: st.token ?? '',
      website: (form.querySelector<HTMLInputElement>('#f-website')?.value ?? ''),
      dauer_ms: Date.now() - ladeZeit,
    };
    const fd = new FormData();
    fd.append('daten', JSON.stringify(daten));
    if (datei) fd.append('lebenslauf', datei, datei.name);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', cfg.api);
    xhr.setRequestHeader('Accept', 'application/json');
    if (datei) {
      bar.hidden = false;
      xhr.upload.onprogress = (ev) => {
        if (ev.lengthComputable) (bar.firstElementChild as HTMLElement).style.width = Math.round((ev.loaded / ev.total) * 100) + '%';
      };
    }
    const fehlschlag = (msg: string) => {
      sendet = false;
      btn.disabled = false;
      btn.textContent = 'Bewerbung absenden';
      alert.innerHTML = msg;
      alert.hidden = false;
      alert.focus?.();
    };
    xhr.onload = () => {
      let j: any = null;
      try { j = JSON.parse(xhr.responseText); } catch { /* s. u. */ }
      if (xhr.status >= 200 && xhr.status < 300 && j?.ok) return danke(j);
      if (j?.fehler) Object.entries(j.fehler as Record<string, string>).forEach(([n, m]) => zeigeFehler(n, m));
      if (j?.token) { st.token = j.token; speichern(st); }
      fehlschlag(esc(j?.meldung ?? 'Das hat leider nicht geklappt.') +
        ` Deine Angaben bleiben erhalten. Du erreichst uns auch telefonisch unter <a href="${cfg.tel}">${esc(cfg.telAnzeige)}</a>.`);
    };
    xhr.onerror = () => fehlschlag(`Keine Verbindung. Bitte prüfe dein Internet und versuche es noch einmal – deine Angaben bleiben erhalten. Oder ruf uns an: <a href="${cfg.tel}">${esc(cfg.telAnzeige)}</a>.`);
    xhr.send(fd);
  }

  function danke(j: { vorname: string; rueckruf_bis: string }) {
    track('funnel_submit', { stelle: st.rolle ?? 'offen' });
    loeschen();
    st.screen = 'danke';
    datei = null;
    const inh = cfg.inhaberin;
    const init = inh.name.split(' ').map((p) => p[0]).join('');
    root!.dataset.state = 'danke';
    root!.innerHTML = `
      <div class="danke">
        ${inh.foto ? `<img class="danke__foto" src="${esc(inh.foto)}" alt="${esc(inh.name)}, ${esc(inh.rolle)}" width="336" height="420">` : `<span class="danke__init" aria-hidden="true">${esc(init)}</span>`}
        <h1 tabindex="-1">Danke, ${esc(j.vorname)}!</h1>
        <p>Deine Bewerbung ist bei uns angekommen. Eine Bestätigung ist auf dem Weg in dein E-Mail-Postfach.</p>
        <div class="danke__next"><strong>So geht es weiter:</strong> Wir rufen dich bis spätestens <strong>${esc(j.rueckruf_bis)}</strong> an.</div>
        <p>Wenn dir bis dahin noch etwas einfällt, ruf gern an.</p>
        <p><a class="btn btn--outline" href="${cfg.tel}" data-track="call_click" data-pos="danke">${icon.phone} ${esc(cfg.telAnzeige)}</a></p>
        <p class="microtext">${esc(inh.name)}, ${esc(inh.rolle)} der Laurentius Apotheke</p>
      </div>`;
    root!.querySelector<HTMLElement>('h1')?.focus();
    window.scrollTo({ top: 0 });
    live.textContent = 'Bewerbung gesendet.';
  }

  // Klicks im Funnel
  root.addEventListener('click', (e) => {
    const t = e.target as HTMLElement;
    const act = t.closest<HTMLElement>('[data-act]')?.dataset.act;
    if (act === 'zurueck') return zurueck();
    if (act === 'andere') {
      st.rolle = null; st.vorbelegt = false;
      st.antworten = {}; st.zusatz = {};
      return gehe('stelle');
    }
    if (act === 'trotzdem') { const l = liste(); return gehe(l[l.indexOf(abschlussId()) + 1]); }
    if (act === 'weiter') {
      const f = fragen.get(st.screen);
      if (f?.zusatz) {
        const v = root.querySelector<HTMLInputElement>(`#z-${f.zusatz.id}`)?.value.trim() ?? '';
        if (v && !new RegExp(f.zusatz.muster).test(v)) {
          root.querySelector('#z-err')!.textContent = 'Bitte gib eine 5-stellige PLZ ein – oder lass das Feld leer.';
          return;
        }
        st.zusatz[f.zusatz.id] = v;
      }
      const ft = root.querySelector<HTMLTextAreaElement>('#z-freitext');
      if (ft) st.zusatz.freitext = ft.value.trim();
      if (f?.typ === 'einzel' && st.antworten[f.id] == null) return;
      return nachAntwort(f!);
    }
    const tile = t.closest<HTMLButtonElement>('.tile');
    if (!tile) return;
    const f = aufloesen(fragen.get(st.screen)!, st.rolle) as Frage;
    const wert = tile.dataset.wert!;
    if (f.typ === 'mehrfach') {
      const arr = ((st.antworten[f.id] as string[]) ?? []).slice();
      const i = arr.indexOf(wert);
      if (i >= 0) arr.splice(i, 1);
      else if (f.max == null || arr.length < f.max) arr.push(wert);
      else return;
      st.antworten[f.id] = arr;
      speichern(st);
      render();
      root.querySelector<HTMLElement>(`.tile[data-wert="${CSS.escape(wert)}"]`)?.focus();
      return;
    }
    // Einzelauswahl
    const vorher = st.antworten[f.id];
    st.antworten[f.id] = wert;
    if (f.id === 'stelle') {
      if (vorher !== wert) { const s = wert; st.antworten = { stelle: s }; st.zusatz = {}; }
      st.rolle = wert;
      if (!st.gestartet) { st.gestartet = true; track('funnel_start', { stelle: wert }); }
    }
    // PLZ/Freitext mitnehmen, falls vorhanden
    if (f.zusatz) st.zusatz[f.zusatz.id] = root.querySelector<HTMLInputElement>(`#z-${f.zusatz.id}`)?.value.trim() ?? '';
    const ft = root.querySelector<HTMLTextAreaElement>('#z-freitext');
    if (ft) st.zusatz.freitext = ft.value.trim();
    speichern(st);
    const auto = !f.zusatz && !f.freitext;
    if (!auto) { render(); root.querySelector<HTMLElement>(`.tile[data-wert="${CSS.escape(wert)}"]`)?.focus(); return; }
    root.querySelectorAll('.tile').forEach((b) => b.setAttribute('aria-pressed', String(b === tile)));
    tile.querySelector('.tile__check')!.innerHTML = icon.check;
    setTimeout(() => nachAntwort(f), 220);
  });

  function nachAntwort(f: Frage) {
    const w = st.antworten[f.id];
    if (typeof w === 'string' && brauchtHinweis(cfg, st.rolle, f.id, w)) return gehe('hinweis');
    weiter();
  }

  // Start
  if (!st.gestartet && st.rolle) { st.gestartet = true; track('funnel_start', { stelle: st.rolle }); }
  if (!st.besucht.includes(st.screen)) gehe(st.screen); else render(false);
  window.addEventListener('pageshow', (ev) => { if ((ev as PageTransitionEvent).persisted && st.screen !== 'danke') render(); });
}
