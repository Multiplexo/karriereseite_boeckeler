<?php
declare(strict_types=1);

/**
 * Cronjob (z. B. alle 15 Minuten):  php /pfad/zu/karriere/api/queue-worker.php
 * - stellt Bewerbungen erneut zu, deren Versand fehlgeschlagen ist
 * - löscht abgelaufene Download-Dateien (14 Tage) und alte Rate-Limit-Einträge
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib/bootstrap.php';

$cfg = lade_config();
$krypto = new Krypto($cfg['schluessel']);
$speicher = new Speicher($cfg['storage_dir'], $krypto);
$funnel = lade_daten('funnel');
$site = site_mit_url(lade_daten('site'), $cfg);
$zustellung = new Zustellung($cfg, $speicher, new Mailer($cfg), new Vorlagen(new Scoring($funnel), $funnel, $site), $site);

$ok = 0; $offen = 0;
foreach ($speicher->offen() as $id) {
    try {
        if ($zustellung->zustellen($id, 1)) $ok++; else $offen++;
    } catch (Throwable $e) {
        $offen++;
        error_log("[karriere] queue-worker $id: " . $e->getMessage());
    }
}
$geloescht = $speicher->aufraeumen((int)($cfg['download_tage'] ?? 14));
echo date('c') . " zugestellt=$ok offen=$offen aufgeraeumt=$geloescht\n";
