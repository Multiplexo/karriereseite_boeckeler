import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { pfad, brauchtHinweis, fuerBrowser, aufloesen } from '../src/lib/funnel-logic.js';

const cfg = JSON.parse(fs.readFileSync(new URL('../src/data/funnel.json', import.meta.url)));

test('Stelle vorbelegt → Schritt 1 entfällt', () => {
  assert.equal(pfad(cfg, 'pta', { stelleVorbelegt: true })[0], 'abschluss');
  assert.equal(pfad(cfg, 'pta').length, 9);
});

test('Höchstens 8 Fragen nach der Stellenwahl', () => {
  for (const r of Object.keys(cfg.rollen)) assert.ok(pfad(cfg, r, { stelleVorbelegt: true }).length <= 8, r);
});

test('Ausbildung: ohne Erfahrung/Samstage, mit Schulabschluss/Zeitraum', () => {
  const p = pfad(cfg, 'ausbildung');
  assert.ok(!p.includes('erfahrung') && !p.includes('samstage'));
  assert.ok(p.includes('schulabschluss') && p.includes('zeitraum'));
});

test('Hinweis-Screen nur bei „anderer Abschluss“ und nicht bei Initiativ', () => {
  assert.equal(brauchtHinweis(cfg, 'pta', 'abschluss', 'anders'), true);
  assert.equal(brauchtHinweis(cfg, 'pta', 'abschluss', 'passend'), false);
  assert.equal(brauchtHinweis(cfg, 'initiativ', 'abschluss', 'anders'), false);
});

test('Initiativ: Abschlussfrage wird zu „Was machst du aktuell beruflich?“', () => {
  const f = aufloesen(cfg.fragen.find((f) => f.id === 'abschluss'), 'initiativ');
  assert.match(f.frage, /aktuell beruflich/);
  assert.ok(f.freitext);
});

test('Browser-Konfiguration enthält keine Punkte und keine Schwellen', () => {
  const s = JSON.stringify(fuerBrowser(cfg));
  assert.ok(!s.includes('"punkte"'));
  assert.ok(!s.includes('schwellen'));
  assert.ok(!s.includes('punkte_je_rolle'));
});
