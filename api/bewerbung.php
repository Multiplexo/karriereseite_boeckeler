<?php
declare(strict_types=1);

/**
 * Endpunkt des Bewerberfunnels.
 *   GET  ?token=1  → signiertes Zeitfallen-Token
 *   POST           → multipart/form-data mit "daten" (JSON) und optional "lebenslauf" (Datei)
 * Validierung, Scoring und Mailversand passieren ausschließlich hier.
 */

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function antwort(int $code, array $daten): never
{
    http_response_code($code);
    echo json_encode($daten, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $cfg = lade_config();
    $krypto = new Krypto($cfg['schluessel'] ?? '');
} catch (Throwable $e) {
    error_log('[karriere] Konfiguration: ' . $e->getMessage());
    antwort(500, ['ok' => false, 'meldung' => 'Technischer Fehler auf unserer Seite.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token'])) {
    antwort(200, ['token' => $krypto->token()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    antwort(405, ['ok' => false, 'meldung' => 'Methode nicht erlaubt.']);
}

// Herkunft prüfen (CSRF-Schutz für einen Endpunkt ohne Login)
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
if ($origin && !empty($cfg['erlaubte_origins']) && !in_array($origin, $cfg['erlaubte_origins'], true)) {
    antwort(403, ['ok' => false, 'meldung' => 'Anfrage nicht erlaubt.']);
}

try {
    $site = site_mit_url(lade_daten('site'), $cfg);
    $funnel = lade_daten('funnel');
    $stellen = lade_daten('stellen');
    $speicher = new Speicher($cfg['storage_dir'], $krypto);
} catch (Throwable $e) {
    error_log('[karriere] ' . $e->getMessage());
    antwort(500, ['ok' => false, 'meldung' => 'Technischer Fehler auf unserer Seite.']);
}

// Rate-Limit pro IP (gehasht, nicht im Klartext gespeichert):
//  - max. 30 Anfragen/Stunde insgesamt (bremst Bots, ohne Tippfehler-Korrekturen zu bestrafen)
//  - max. 5 angenommene Bewerbungen/Stunde (gezählt erst nach erfolgreicher Prüfung, s. u.)
$rl = $cfg['rate_limit'] ?? ['max' => 5, 'fenster' => 3600];
$ipHash = $krypto->ipHash(client_ip($cfg));
$zuViele = ['ok' => false, 'meldung' => 'Es wurden gerade sehr viele Bewerbungen von deinem Anschluss gesendet. Bitte versuch es später noch einmal oder ruf uns an.'];
if (!$speicher->rateLimit("$ipHash-anfragen", 30, (int)$rl['fenster'])
    || !$speicher->rateLimit("$ipHash-bewerbungen", (int)$rl['max'], (int)$rl['fenster'], false)
    || !$speicher->rateLimit('global-bewerbungen', (int)($rl['global_max'] ?? 60), (int)$rl['fenster'], false)) {
    antwort(429, $zuViele);
}

$daten = json_decode((string)($_POST['daten'] ?? ''), true);
if (!is_array($daten)) antwort(400, ['ok' => false, 'meldung' => 'Die Anfrage war unvollständig.']);

$botAntwort = ['ok' => true, 'vorname' => mb_substr(trim((string)($daten['kontakt']['vorname'] ?? '')), 0, 40), 'rueckruf_bis' => Werktage::deutsch(Werktage::plus(new DateTimeImmutable(), (int)$site['rueckruf_werktage']))];

// Honeypot: Bots bekommen eine scheinbar erfolgreiche Antwort, es wird nichts versendet.
if (trim((string)($daten['website'] ?? '')) !== '') antwort(200, $botAntwort);

// Zeitfalle: signiertes Token muss mind. N Sekunden alt sein.
$alter = $krypto->tokenAlter((string)($daten['token'] ?? ''));
if ($alter === null || $alter > 86400 * 2) {
    antwort(422, ['ok' => false, 'meldung' => 'Deine Sitzung ist abgelaufen. Bitte tippe noch einmal auf „Bewerbung absenden“.', 'token' => $krypto->token()]);
}
if ($alter < (int)($cfg['zeitfalle_sek'] ?? 5)) {
    // Kein stilles Verwerfen: Ein Mensch bekommt eine sichtbare Bitte, Bots geben hier in der Regel auf.
    antwort(429, ['ok' => false, 'meldung' => 'Das ging gerade sehr schnell. Bitte tippe in ein paar Sekunden noch einmal auf „Bewerbung absenden“.']);
}

// ---- Validierung ----
$scoring = new Scoring($funnel);
$offen = array_column(array_filter($stellen['stellen'], fn($s) => $s['status'] === 'offen'), 'slug');
$offen[] = 'initiativ';
$rolle = (string)($daten['stelle'] ?? '');
if (!in_array($rolle, $offen, true)) antwort(422, ['ok' => false, 'meldung' => 'Diese Stelle ist leider nicht mehr offen. Bitte wähle eine andere Stelle.']);

try {
    $antworten = $scoring->bereinige($rolle, is_array($daten['antworten'] ?? null) ? $daten['antworten'] : []);
} catch (InvalidArgumentException $e) {
    antwort(422, ['ok' => false, 'meldung' => $e->getMessage()]);
}

$txt = fn($v, int $max) => mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? ''), 0, $max);
$einzeilig = fn($v, int $max) => preg_replace('/\s+/u', ' ', $txt($v, $max));
$k = is_array($daten['kontakt'] ?? null) ? $daten['kontakt'] : [];
$kontakt = [
    'vorname' => $einzeilig($k['vorname'] ?? '', 80),
    'nachname' => $einzeilig($k['nachname'] ?? '', 80),
    'telefon' => $einzeilig($k['telefon'] ?? '', 40),
    'email' => $einzeilig($k['email'] ?? '', 120),
    'rueckruf' => $einzeilig($k['rueckruf'] ?? '', 60),
    'nachricht' => $txt($k['nachricht'] ?? '', 2000),
    'datenschutz' => ($k['datenschutz'] ?? false) === true,
    'talentpool' => ($k['talentpool'] ?? false) === true,
];
$fehler = [];
if ($kontakt['vorname'] === '') $fehler['vorname'] = 'Bitte gib deinen Vornamen ein.';
if ($kontakt['nachname'] === '') $fehler['nachname'] = 'Bitte gib deinen Nachnamen ein.';
if (!preg_match('/^[+0-9 ()\/-]+$/', $kontakt['telefon']) || strlen(preg_replace('/\D/', '', $kontakt['telefon'])) < 6) $fehler['telefon'] = 'Bitte prüfe deine Telefonnummer.';
if (!filter_var($kontakt['email'], FILTER_VALIDATE_EMAIL)) $fehler['email'] = 'Bitte prüfe deine E-Mail-Adresse.';
if (!$kontakt['datenschutz']) $fehler['datenschutz'] = 'Bitte bestätige, dass du die Datenschutzhinweise gelesen hast.';
$kontaktFrage = array_values(array_filter($funnel['fragen'], fn($f) => $f['typ'] === 'kontakt'))[0] ?? [];
if ($kontakt['rueckruf'] !== '' && !in_array($kontakt['rueckruf'], $kontaktFrage['rueckrufzeiten'] ?? [], true)) $kontakt['rueckruf'] = '';
if ($fehler) antwort(422, ['ok' => false, 'meldung' => 'Bitte prüfe die markierten Felder.', 'fehler' => $fehler]);

$zusatz = [];
$plz = $einzeilig($daten['zusatz']['plz'] ?? '', 5);
if (preg_match('/^\d{5}$/', $plz)) $zusatz['plz'] = $plz;
if ($rolle === 'initiativ') $zusatz['freitext'] = $txt($daten['zusatz']['freitext'] ?? '', 300);

// ---- Upload ----
$anhang = null;
$datei = $_FILES['lebenslauf'] ?? null;
if ($datei && ($datei['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $dateiFehler = fn(string $m) => antwort(422, ['ok' => false, 'meldung' => $m, 'fehler' => ['datei' => $m]]);
    if ($datei['error'] === UPLOAD_ERR_INI_SIZE || $datei['error'] === UPLOAD_ERR_FORM_SIZE || $datei['size'] > 10 * 1024 * 1024) $dateiFehler('Die Datei ist größer als 10 MB.');
    if ($datei['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($datei['tmp_name'])) $dateiFehler('Die Datei konnte nicht hochgeladen werden. Versuch es noch einmal oder lass sie weg.');
    $ext = strtolower(pathinfo((string)$datei['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($datei['tmp_name']) ?: '';
    $erlaubt = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];
    if (!isset($erlaubt[$ext]) || !in_array($mime, $erlaubt[$ext], true)) $dateiFehler('Dieser Dateityp geht leider nicht. Erlaubt sind PDF, JPG, PNG und DOCX.');
    if ($ext === 'docx') {
        // DOCX = ZIP-Container mit word/document.xml; Dateinamen stehen unkomprimiert im ZIP-Verzeichnis.
        $roh = (string)file_get_contents($datei['tmp_name']);
        $istDocx = str_starts_with($roh, "PK\x03\x04") && str_contains($roh, 'word/document.xml')
            && !str_contains($roh, 'vbaProject.bin'); // keine Makros
        if (!$istDocx) $dateiFehler('Die Word-Datei ist beschädigt oder kein gültiges DOCX.');
        $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }
    if (!empty($cfg['clamav']) && is_executable($cfg['clamav'])) {
        exec(escapeshellarg($cfg['clamav']) . ' --no-summary --fdpass ' . escapeshellarg($datei['tmp_name']) . ' 2>&1', $out, $rc);
        if ($rc === 1) $dateiFehler('Die Datei wurde vom Virenscanner abgelehnt.');
        if ($rc > 1) error_log('[karriere] ClamAV nicht verfügbar: ' . implode(' ', $out));
    }
    $sauberName = preg_replace('/[^A-Za-z0-9._-]+/', '_', mb_substr(trim($kontakt['nachname'] . '_' . $kontakt['vorname']), 0, 60));
    $anhang = ['name' => "Lebenslauf_{$sauberName}.$ext", 'mime' => $mime, 'inhalt' => (string)file_get_contents($datei['tmp_name'])];
}

// ---- Auswertung & Zwischenspeicherung ----
$bewertung = $scoring->bewerte($rolle, $antworten);
$id = Speicher::neueId();
$jetzt = new DateTimeImmutable();
$rueckrufBis = Werktage::deutsch(Werktage::plus($jetzt, (int)$site['rueckruf_werktage']));
$q = is_array($daten['quelle'] ?? null) ? $daten['quelle'] : [];
$quelle = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'einstieg', 'referrer', 'seite'] as $f) {
    if (!empty($q[$f]) && is_string($q[$f])) $quelle[$f] = $einzeilig($q[$f], 200);
}

$anhangDaten = null;
if ($anhang) {
    if (strlen($anhang['inhalt']) > (float)($cfg['anhang_max_mb'] ?? 5) * 1024 * 1024) {
        $tage = (int)($cfg['download_tage'] ?? 14);
        $ablauf = $speicher->dateiAblegen($id, $anhang['name'], $anhang['mime'], $anhang['inhalt'], $tage);
        $link = rtrim($cfg['basis_url'], '/') . '/api/download.php?id=' . $id . '&exp=' . $ablauf . '&sig=' . $krypto->signiere("dl:$id:$ablauf");
        $anhangDaten = ['name' => $anhang['name'], 'link' => $link, 'ablauf_anzeige' => date('d.m.Y', $ablauf)];
    } else {
        $anhangDaten = ['name' => $anhang['name'], 'mime' => $anhang['mime'], 'inhalt' => base64_encode($anhang['inhalt'])];
    }
}

$stelleLabel = $funnel['rollen'][$rolle]['label'] ?? $rolle;
if ($rolle === 'ausbildung' && isset($antworten['art'])) {
    foreach ($scoring->pfad($rolle) as $f) if ($f['id'] === 'art') $stelleLabel = $scoring->option($f, $antworten['art'])['kurz'] ?? $stelleLabel;
}

$bewerbung = [
    'id' => $id,
    'eingang' => $jetzt->format(DATE_ATOM),
    'eingang_anzeige' => $jetzt->format('d.m.Y, H:i') . ' Uhr',
    'stelle' => $rolle,
    'stelle_label' => $stelleLabel,
    'antworten' => $antworten,
    'zusatz' => $zusatz,
    'kontakt' => $kontakt,
    'quelle' => $quelle,
    'bewertung' => $bewertung,
    'rueckruf_bis' => $rueckrufBis,
    'anhang' => $anhangDaten,
];

if (!$speicher->rateLimit("$ipHash-bewerbungen", (int)$rl['max'], (int)$rl['fenster'])) antwort(429, $zuViele);
$speicher->rateLimit('global-bewerbungen', (int)($rl['global_max'] ?? 60), (int)$rl['fenster']);

try {
    $speicher->ablegen($id, $bewerbung);
} catch (Throwable $e) {
    error_log('[karriere] Ablage fehlgeschlagen: ' . $e->getMessage());
    antwort(500, ['ok' => false, 'meldung' => 'Technischer Fehler auf unserer Seite – bitte versuch es gleich noch einmal.']);
}

// Antwort sofort an den Browser, Versand danach (wenn PHP-FPM das unterstützt).
$ok = ['ok' => true, 'vorname' => $kontakt['vorname'], 'rueckruf_bis' => $rueckrufBis];
if (function_exists('fastcgi_finish_request')) {
    http_response_code(200);
    echo json_encode($ok, JSON_UNESCAPED_UNICODE);
    fastcgi_finish_request();
    $sofortGeantwortet = true;
}
ignore_user_abort(true);
set_time_limit(90);

$zustellung = new Zustellung($cfg, $speicher, new Mailer($cfg), new Vorlagen($scoring, $funnel, $site), $site);
try {
    $zustellung->zustellen($id);
} catch (Throwable $e) {
    error_log('[karriere] Zustellung: ' . $e->getMessage());
}

// Status als serverseitiges Analytics-Event (nicht im Browser sichtbar)
$plausible = $cfg['plausible']['domain'] ?? null;
if ($plausible) {
    $ch = curl_init($cfg['plausible']['api'] ?? 'https://plausible.io/api/event');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: ' . ($_SERVER['HTTP_USER_AGENT'] ?? 'karriere'), 'X-Forwarded-For: ' . client_ip($cfg)],
        CURLOPT_POSTFIELDS => json_encode(['name' => 'funnel_status_' . $bewertung['status'], 'domain' => $plausible, 'url' => rtrim($cfg['basis_url'], '/') . '/bewerben/', 'props' => ['stelle' => $rolle]]),
    ]);
    curl_exec($ch);
}

if (empty($sofortGeantwortet)) antwort(200, $ok);
