<?php
declare(strict_types=1);
// php tests/zustellung_test.php – Zustellung mit Datei-Transport: Empfänger als Liste, CC voll/hinweis, Löschen nach Erfolg.
require __DIR__ . '/../api/vendor/autoload.php';
foreach (['Scoring', 'Werktage', 'Krypto', 'Speicher', 'Mailer', 'Vorlagen', 'Zustellung'] as $k) require __DIR__ . "/../api/lib/$k.php";

$fail = 0; $n = 0;
function check(string $name, $ist, $soll): void {
    global $fail, $n; $n++;
    if ($ist === $soll) { echo "  ok  $name\n"; return; }
    $fail++; echo "  FAIL $name: ist " . var_export($ist, true) . ", soll " . var_export($soll, true) . "\n";
}
$funnel = json_decode(file_get_contents(__DIR__ . '/../src/data/funnel.json'), true);
$site = json_decode(file_get_contents(__DIR__ . '/../src/data/site.json'), true);

foreach (['voll', 'hinweis'] as $modus) {
    $dir = sys_get_temp_dir() . '/karriere-test-' . bin2hex(random_bytes(4));
    $cfg = [
        'empfaenger' => ['info@example.org'], 'cc' => ['chefin@example.org'], 'cc_modus' => $modus,
        'absender' => ['email' => 'karriere@example.org', 'name' => 'Karriere'], 'transport' => 'datei',
        'datei_transport_dir' => "$dir/mails", 'technik_alarm' => '',
    ];
    $krypto = new Krypto(base64_encode(random_bytes(32)));
    $speicher = new Speicher($dir, $krypto);
    $scoring = new Scoring($funnel);
    $antworten = ['abschluss' => 'passend', 'erfahrung' => '3-10', 'stunden' => 'vollzeit', 'start' => 'sofort', 'arbeitsweg' => '<20', 'samstage' => 'ja', 'wichtig' => []];
    $b = ['id' => Speicher::neueId(), 'eingang_anzeige' => 'heute', 'stelle' => 'pta', 'stelle_label' => 'PTA', 'antworten' => $antworten, 'zusatz' => [],
        'kontakt' => ['vorname' => 'Maria', 'nachname' => 'Muster', 'telefon' => '0151 1', 'email' => 'maria@example.org', 'rueckruf' => '', 'nachricht' => '', 'datenschutz' => true, 'talentpool' => false],
        'quelle' => [], 'bewertung' => $scoring->bewerte('pta', $antworten), 'rueckruf_bis' => 'Freitag, 20. November', 'anhang' => null];
    $speicher->ablegen($b['id'], $b);
    $z = new Zustellung($cfg, $speicher, new Mailer($cfg), new Vorlagen($scoring, $funnel, $site), $site);
    check("[$modus] vollständig zugestellt", $z->zustellen($b['id'], 0), true);
    check("[$modus] Warteschlange leer", $speicher->offen(), []);
    $mails = array_map('file_get_contents', glob("$dir/mails/*.eml"));
    $kopf = fn($m, $h) => preg_match("/^$h: (.*)$/mi", $m, $x) ? trim($x[1]) : null;
    $intern = array_values(array_filter($mails, fn($m) => str_contains((string)$kopf($m, 'To'), 'info@')))[0] ?? '';
    $best = array_values(array_filter($mails, fn($m) => str_contains((string)$kopf($m, 'To'), 'maria@')))[0] ?? '';
    check("[$modus] Bestätigung Reply-To = Apotheke", $kopf($best, 'Reply-To'), 'Laurentius Apotheke <info@example.org>');
    if ($modus === 'voll') {
        check('[voll] CC erhält komplette Bewerbung', $kopf($intern, 'Cc'), 'chefin@example.org');
        check('[voll] 2 Mails', count($mails), 2);
    } else {
        check('[hinweis] Eingangsmail ohne CC', $kopf($intern, 'Cc'), null);
        $hinweis = array_values(array_filter($mails, fn($m) => str_contains((string)$kopf($m, 'To'), 'chefin@')))[0] ?? '';
        check('[hinweis] Kurzinfo ohne Telefonnummer/E-Mail', str_contains($hinweis, 'maria@example.org') || str_contains($hinweis, '0151'), false);
        check('[hinweis] 3 Mails', count($mails), 3);
    }
}
echo $fail ? "\n$fail von $n Tests fehlgeschlagen\n" : "\nAlle $n Tests bestanden\n";
exit($fail ? 1 : 0);
