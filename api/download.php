<?php
declare(strict_types=1);

/** Signierter, zeitlich begrenzter Download eines großen Lebenslaufs (Link steht nur in der Eingangsmail). */
require __DIR__ . '/lib/bootstrap.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

try {
    $cfg = lade_config();
    $krypto = new Krypto($cfg['schluessel']);
    $speicher = new Speicher($cfg['storage_dir'], $krypto);
} catch (Throwable $e) {
    error_log('[karriere] download: ' . $e->getMessage());
    http_response_code(500); exit('Technischer Fehler.');
}

$id = (string)($_GET['id'] ?? '');
$exp = (int)($_GET['exp'] ?? 0);
$sig = (string)($_GET['sig'] ?? '');
if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $id) || $exp < time() || !$krypto->pruefe("dl:$id:$exp", $sig)) {
    http_response_code(410); exit('Dieser Link ist ungültig oder abgelaufen.');
}
$d = $speicher->dateiLesen($id);
if (!$d) { http_response_code(410); exit('Die Datei wurde bereits gelöscht.'); }

header('Content-Type: ' . $d['mime']);
header('Content-Length: ' . strlen($d['inhalt']));
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $d['name']) . '"');
echo $d['inhalt'];
