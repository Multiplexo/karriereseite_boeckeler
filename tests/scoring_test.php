<?php
declare(strict_types=1);
// php tests/scoring_test.php – prüft Scoring/Statuslogik gegen die echte funnel.json und die Werktage-Berechnung.
require __DIR__ . '/../api/lib/Scoring.php';
require __DIR__ . '/../api/lib/Werktage.php';

$funnel = json_decode(file_get_contents(__DIR__ . '/../src/data/funnel.json'), true);
$s = new Scoring($funnel);
$fail = 0; $n = 0;
function check(string $name, $ist, $soll): void {
    global $fail, $n; $n++;
    if ($ist === $soll) { echo "  ok  $name\n"; return; }
    $fail++; echo "  FAIL $name: ist " . var_export($ist, true) . ", soll " . var_export($soll, true) . "\n";
}
$basis = ['abschluss' => 'passend', 'erfahrung' => '3-10', 'stunden' => 'vollzeit', 'start' => 'sofort', 'arbeitsweg' => '<20', 'samstage' => 'ja', 'wichtig' => ['team']];

$r = $s->bewerte('pta', $basis);
check('PTA ideal → 100 / A', [$r['score'], $r['status']], [100, 'A']);

$r = $s->bewerte('pta', ['abschluss' => 'laufend'] + $basis);
check('PTA in Ausbildung, sonst ideal → B (≥75 ohne passenden Abschluss)', [$r['score'], $r['status']], [80, 'B']);

$r = $s->bewerte('pta', ['abschluss' => 'anders'] + $basis);
check('PTA anderer Abschluss → C', $r['status'], 'C');

$r = $s->bewerte('pka', ['abschluss' => 'passend', 'erfahrung' => 'einsteiger', 'stunden' => 'minijob', 'start' => 'spaeter', 'arbeitsweg' => '<20', 'samstage' => 'nein', 'wichtig' => []]);
check('PKA passend, aber wenig Verfügbarkeit → 64 / B', [$r['score'], $r['status']], [64, 'B']);

$r = $s->bewerte('pta', ['abschluss' => 'laufend', 'erfahrung' => 'einsteiger', 'stunden' => 'minijob', 'start' => 'spaeter', 'arbeitsweg' => '>40', 'samstage' => 'absprache', 'wichtig' => []]);
check('Arbeitsweg allein führt nicht zu C (AGG) → B', [$r['score'], $r['status']], [42, 'B']);

$r = $s->bewerte('pta', ['abschluss' => 'laufend', 'erfahrung' => 'einsteiger', 'stunden' => 'minijob', 'start' => 'spaeter', 'arbeitsweg' => '<20', 'samstage' => 'nein', 'wichtig' => []]);
check('Niedriger Score ohne Arbeitsweg-Effekt → C', [$r['score'], $r['status']], [44, 'C']);

$r = $s->bewerte('initiativ', ['abschluss' => 'anders', 'erfahrung' => 'einsteiger', 'stunden' => 'minijob', 'start' => 'spaeter', 'arbeitsweg' => '>40', 'samstage' => 'nein', 'wichtig' => []]);
check('Initiativ ist immer mindestens B', $r['status'], 'B');

$r = $s->bewerte('initiativ', ['abschluss' => 'pta'] + $basis);
check('Initiativ PTA ideal → A', $r['status'], 'A');

$ids = fn($rolle) => array_column($s->pfad($rolle), 'id');
check('Pfad Ausbildung', $ids('ausbildung'), ['stelle', 'art', 'schulabschluss', 'zeitraum', 'arbeitsweg', 'wichtig', 'kontakt']);
check('Pfad PTA (8 Fragen + Stelle)', count($ids('pta')), 9);
check('Pfad Botendienst', $ids('botendienst'), ['stelle', 'abschluss', 'stunden', 'start', 'arbeitsweg', 'wichtig', 'kontakt']);

$r = $s->bewerte('ausbildung', ['art' => 'pka_ausbildung', 'schulabschluss' => 'mittlere_reife', 'zeitraum' => 'sommer', 'arbeitsweg' => '20-40', 'wichtig' => []]);
check('Ausbildung normiert auf 100', [$r['max'], $r['score'], $r['status']], [60, 95, 'A']);

$r = $s->bewerte('botendienst', ['abschluss' => 'passend', 'stunden' => 'minijob', 'start' => 'sofort', 'arbeitsweg' => '<20', 'wichtig' => []]);
check('Botendienst Minijob nutzt punkte_je_rolle → A', [$r['score'], $r['status']], [100, 'A']);

try { $s->bereinige('pta', ['abschluss' => 'hacker'] + $basis); check('Ungültige Antwort wird abgelehnt', false, true); }
catch (InvalidArgumentException) { check('Ungültige Antwort wird abgelehnt', true, true); }
try { $s->bereinige('pta', ['wichtig' => ['gehalt', 'team', 'weg', 'fortbildung']] + $basis); check('Mehr als 3 Prioritäten abgelehnt', false, true); }
catch (InvalidArgumentException) { check('Mehr als 3 Prioritäten abgelehnt', true, true); }
check('Nur Pfad-Fragen werden übernommen', array_keys($s->bereinige('pta', $basis + ['zeitraum' => 'sommer'])), ['abschluss', 'erfahrung', 'stunden', 'start', 'arbeitsweg', 'samstage', 'wichtig']);

$tz = new DateTimeZone('Europe/Berlin');
$d = fn($x) => Werktage::plus(new DateTimeImmutable($x, $tz), 2)->format('Y-m-d');
check('Mi + 2 Werktage = Fr', $d('2026-11-18'), '2026-11-20');
check('Fr + 2 Werktage = Di', $d('2026-11-20'), '2026-11-24');
check('Weihnachten übersprungen (24.12. ist Werktag)', $d('2026-12-23'), '2026-12-28');
check('Karfreitag/Ostermontag 2027 übersprungen', $d('2027-03-25'), '2027-03-31');
check('Fronleichnam 2027 (NRW)', $d('2027-05-26'), '2027-05-31');
check('Deutsches Datum', Werktage::deutsch(new DateTimeImmutable('2026-11-20', $tz)), 'Freitag, 20. November');

echo $fail ? "\n$fail von $n Tests fehlgeschlagen\n" : "\nAlle $n Tests bestanden\n";
exit($fail ? 1 : 0);
