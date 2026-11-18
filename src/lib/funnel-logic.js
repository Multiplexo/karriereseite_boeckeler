// Reine Pfad-Logik des Funnels. Wird im Browser (Funnel) und in Node-Tests genutzt.
// Die serverseitige Auswertung (Punkte, Status) liegt in api/lib/Scoring.php und folgt denselben Regeln.

/** Gilt die Frage für diese Rolle? */
export function gilt(frage, rolle) {
  if (frage.id === 'stelle') return true;
  if (!frage.rollen) return true;
  return rolle != null && frage.rollen.includes(rolle);
}

/** Löst rollenabhängige Varianten auf (Frage, Optionen, Freitext). */
export function aufloesen(frage, rolle) {
  if (frage.varianten) {
    const v = frage.varianten[rolle];
    if (!v) return null;
    return { ...frage, ...v, varianten: undefined };
  }
  return frage;
}

/** Liste der Fragen-IDs, die eine Person mit dieser Rolle durchläuft. */
export function pfad(config, rolle, { stelleVorbelegt = false } = {}) {
  return config.fragen
    .filter((f) => gilt(f, rolle))
    .filter((f) => !(f.id === 'stelle' && stelleVorbelegt))
    .filter((f) => aufloesen(f, rolle) !== null)
    .map((f) => f.id);
}

/** Zeigt die Abschlussfrage einen Hinweis-Screen (Abschluss passt nicht zur Stelle)? */
export function brauchtHinweis(config, rolle, frageId, wert) {
  if (rolle === 'initiativ' || rolle === 'ausbildung') return false;
  const f = config.fragen.find((x) => x.id === frageId);
  if (!f || f.wertung !== 'abschluss') return false;
  const r = aufloesen(f, rolle);
  const opt = r?.optionen?.find((o) => o.wert === wert);
  return opt?.match === 'anders';
}

/** Entfernt Punkte und Match-Gewichtung, bevor die Konfiguration an den Browser geht. */
export function fuerBrowser(config) {
  const strip = (opts) => opts?.map(({ punkte, ...rest }) => rest);
  return {
    rollen: Object.fromEntries(
      Object.entries(config.rollen).map(([k, v]) => [k, { label: v.label, abschluss_hinweis: v.abschluss_hinweis }])
    ),
    fragen: config.fragen.map((f) => {
      const { punkte_je_rolle, _hinweis, ...rest } = f;
      const out = { ...rest, optionen: strip(f.optionen) };
      if (f.varianten) {
        out.varianten = Object.fromEntries(
          Object.entries(f.varianten).map(([k, v]) => [k, { ...v, optionen: strip(v.optionen) }])
        );
      }
      return out;
    }),
  };
}
