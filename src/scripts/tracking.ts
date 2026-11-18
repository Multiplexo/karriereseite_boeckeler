// Cookieloses Tracking (Plausible) + UTM-/Einstiegsseiten-Erfassung in sessionStorage.
declare global {
  interface Window { plausible?: (event: string, opts?: { props?: Record<string, string | number> }) => void }
}

const UTM_KEY = 'lap_quelle';

function safeGet(store: Storage, k: string) { try { return store.getItem(k); } catch { return null; } }
function safeSet(store: Storage, k: string, v: string) { try { store.setItem(k, v); } catch { /* privat/blockiert */ } }

export function track(event: string, props?: Record<string, string | number>) {
  try { window.plausible?.(event, props ? { props } : undefined); } catch { /* ignorieren */ }
}

export function quelle(): Record<string, string> {
  try { return JSON.parse(safeGet(sessionStorage, UTM_KEY) || '{}'); } catch { return {}; }
}

function merkeQuelle() {
  const p = new URLSearchParams(location.search);
  const vorhanden = quelle();
  const utm: Record<string, string> = {};
  for (const k of ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']) {
    const v = p.get(k);
    if (v) utm[k] = v.slice(0, 120);
  }
  // Erste Einstiegsseite und Referrer nur beim ersten Seitenaufruf der Sitzung merken; neue UTM-Werte überschreiben.
  const neu = { ...vorhanden, ...utm };
  if (!vorhanden.einstieg) {
    neu.einstieg = location.pathname + location.search.slice(0, 200);
    if (document.referrer && !document.referrer.startsWith(location.origin)) neu.referrer = document.referrer.slice(0, 200);
  }
  safeSet(sessionStorage, UTM_KEY, JSON.stringify(neu));
}

export function initTracking() {
  merkeQuelle();
  document.addEventListener('click', (e) => {
    const a = (e.target as Element | null)?.closest<HTMLElement>('[data-track]');
    if (!a) return;
    track(a.dataset.track!, a.dataset.pos ? { position: a.dataset.pos } : undefined);
  });

  // Mobile Bottom-Bar: sichtbar, sobald der Hero-CTA nicht im Bild ist (auch auf kleinen Geräten direkt beim Einstieg).
  const bar = document.getElementById('bottom-bar');
  const heroCta = document.querySelector('[data-hero] .btn--primary');
  if (bar && heroCta && 'IntersectionObserver' in window) {
    new IntersectionObserver(([entry]) => {
      const show = !entry.isIntersecting;
      bar.classList.toggle('is-visible', show);
      bar.setAttribute('aria-hidden', String(!show));
      bar.querySelectorAll('a').forEach((el) => (show ? el.removeAttribute('tabindex') : el.setAttribute('tabindex', '-1')));
    }).observe(heroCta);
  }
}
