<?php
// Konfiguration aus Umgebungsvariablen (Render, Docker). Wird im Container als config.php verwendet.
// Secrets (BREVO_API_KEY, KARRIERE_SCHLUESSEL, SMTP_PASS) nur als Umgebungsvariable setzen – nie ins Repository.
$env = fn(string $k, ?string $standard = null) => ($v = getenv($k)) !== false && $v !== '' ? $v : $standard;
$liste = fn(?string $v) => array_values(array_filter(array_map('trim', explode(',', (string)$v))));
$siteUrl = rtrim((string)$env('SITE_URL', 'https://karriere.laurentius-apotheke-erwitte.de'), '/');
$basis = rtrim((string)$env('BASE_PATH', '/'), '/');

return [
    'empfaenger' => $liste($env('MAIL_EMPFAENGER', 'info@laurentius-apotheke-erwitte.de')),
    'cc' => $liste($env('MAIL_CC', '')),
    'cc_modus' => $env('MAIL_CC_MODUS', 'voll'),          // 'voll' oder 'hinweis'
    'absender' => [
        'email' => $env('MAIL_ABSENDER', 'karriere@laurentius-apotheke-erwitte.de'),
        'name' => $env('MAIL_ABSENDER_NAME', 'Karriereseite Laurentius Apotheke'),
    ],
    'bestaetigung_absender_name' => $env('MAIL_BESTAETIGUNG_NAME', 'Dorothee Böckeler | Laurentius Apotheke'),
    'technik_alarm' => $env('MAIL_ALARM', ''),

    'transport' => $env('MAIL_TRANSPORT', 'brevo'),       // 'brevo' | 'smtp' | 'datei'
    'brevo_api_key' => $env('BREVO_API_KEY', ''),
    'brevo_api_url' => $env('BREVO_API_URL', 'https://api.brevo.com/v3/smtp/email'),
    'smtp' => [
        'host' => $env('SMTP_HOST', ''), 'port' => (int)$env('SMTP_PORT', '587'), 'secure' => $env('SMTP_SECURE', 'tls'),
        'user' => $env('SMTP_USER', ''), 'pass' => $env('SMTP_PASS', ''),
    ],

    'schluessel' => $env('KARRIERE_SCHLUESSEL', ''),
    'storage_dir' => $env('STORAGE_DIR', '/var/data/karriere'),
    'anhang_max_mb' => (float)$env('ANHANG_MAX_MB', '5'),
    'download_tage' => 14,
    'rate_limit' => ['max' => 5, 'fenster' => 3600, 'global_max' => (int)$env('RATE_LIMIT_GLOBAL', '60')],
    'zeitfalle_sek' => 5,
    'clamav' => null,
    'ip_header' => $env('PROXY_IP_HEADER', 'X-Forwarded-For'),

    'basis_url' => $siteUrl . $basis . '/',
    // Erlaubte Origins: eigene Domain + die von Render vergebene *.onrender.com-Adresse (zum Testen vor dem Domain-Umzug)
    'erlaubte_origins' => array_values(array_unique(array_filter(array_merge(
        $liste($env('ERLAUBTE_ORIGINS', '')),
        array_map(function ($u) { $p = parse_url((string)$u); return isset($p['host']) ? $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') : null; },
            [$siteUrl, $env('RENDER_EXTERNAL_URL')])
    )))),
    'plausible' => ['domain' => $env('PLAUSIBLE_DOMAIN'), 'api' => 'https://plausible.io/api/event'],
];
