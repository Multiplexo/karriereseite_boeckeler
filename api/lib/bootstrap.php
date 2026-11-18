<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) { http_response_code(500); exit('vendor/ fehlt – im Ordner api/ "composer install --no-dev" ausführen.'); }
require $autoload;
foreach (['Scoring', 'Werktage', 'Krypto', 'Speicher', 'Mailer', 'Vorlagen', 'Zustellung'] as $k) require_once __DIR__ . "/$k.php";

/** Lädt eine Datendatei: im Build liegt sie unter api/data/, im Repository unter src/data/. */
function lade_daten(string $name): array
{
    foreach ([__DIR__ . "/../data/$name.json", __DIR__ . "/../../src/data/$name.json"] as $p) {
        if (is_file($p)) return json_decode((string)file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
    }
    throw new RuntimeException("Datendatei $name.json nicht gefunden.");
}

/**
 * Client-IP: hinter einem Reverse-Proxy (Render) aus dem konfigurierten Header, sonst REMOTE_ADDR.
 * Der Header kann vom Client vorbelegt werden – deshalb gibt es zusätzlich ein globales Limit.
 */
function client_ip(array $cfg): string
{
    $h = $cfg['ip_header'] ?? null;
    if ($h) {
        $v = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))] ?? '';
        $ip = trim(explode(',', $v)[0] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Öffentliche URL der Karriereseite aus der Server-Konfiguration übernehmen (für Links in Mails). */
function site_mit_url(array $site, array $cfg): array
{
    if (!empty($cfg['basis_url'])) $site['karriere_url'] = rtrim($cfg['basis_url'], '/');
    return $site;
}

function lade_config(): array
{
    $p = getenv('KARRIERE_CONFIG') ?: __DIR__ . '/../config.php';
    if (!is_file($p)) throw new RuntimeException('api/config.php fehlt (Vorlage: config.sample.php).');
    $c = require $p;
    $c['storage_dir'] ??= __DIR__ . '/../storage';
    $c['datei_transport_dir'] ??= $c['storage_dir'] . '/mails';
    return $c;
}
