<?php
// Kopie als config.php anlegen und ausfüllen. config.php NIE ins Git-Repository einchecken.
return [
    // Zieladresse für Bewerbungen (+ optionale CC, z. B. Vertretung)
    'empfaenger' => ['info@laurentius-apotheke-erwitte.de'],
    'cc' => [],                 // z. B. ['vorname.name@example.de']
    'cc_modus' => 'voll',       // 'voll' = komplette Bewerbung in CC, 'hinweis' = CC erhält nur eine Kurzinfo ohne Bewerberdaten

    // Absender: Adresse auf der eigenen Domain (SPF/DKIM/DMARC!)
    'absender' => ['email' => 'karriere@laurentius-apotheke-erwitte.de', 'name' => 'Karriereseite Laurentius Apotheke'],
    'bestaetigung_absender_name' => 'Dorothee Böckeler | Laurentius Apotheke',

    // Alarm bei Zustellproblemen (technischer Ansprechpartner)
    'technik_alarm' => '',

    // 'brevo' (HTTP-API), 'smtp' oder 'datei' (schreibt .eml-Dateien nach storage/mails, nur Test)
    'transport' => 'smtp',
    'brevo_api_key' => '',
    'smtp' => [
        'host' => 'smtp-relay.brevo.com', // oder SMTP des Hosters
        'port' => 587,
        'secure' => 'tls',                // 'tls' (STARTTLS, Port 587) oder 'ssl' (Port 465)
        'user' => '',
        'pass' => '',
        // Nur falls der SMTP-Dienst nicht selbst DKIM signiert:
        'dkim_domain' => '', 'dkim_selector' => '', 'dkim_private_key' => '',
    ],

    // 32-Byte-Schlüssel (base64) für Verschlüsselung und Signaturen. Erzeugen mit:
    //   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
    'schluessel' => '',

    // Speicherort für verschlüsselte Zwischenablage. Am besten AUSSERHALB des Webroots, z. B. '/home/kunde/karriere-storage'.
    'storage_dir' => __DIR__ . '/storage',

    'anhang_max_mb' => 5,     // größere Lebensläufe kommen als signierter Download-Link statt Anhang
    'download_tage' => 14,
    'rate_limit' => ['max' => 5, 'fenster' => 3600, 'global_max' => 60],
    'ip_header' => null,       // hinter einem Reverse-Proxy z. B. 'X-Forwarded-For'
    'zeitfalle_sek' => 5,
    'clamav' => null,          // z. B. '/usr/bin/clamdscan', falls beim Hoster verfügbar

    // Öffentliche Basis-URL der Karriereseite (für Download-Links und Analytics)
    'basis_url' => 'https://karriere.laurentius-apotheke-erwitte.de/',
    // Erlaubte Origins für das Formular (leer = keine Prüfung)
    'erlaubte_origins' => ['https://karriere.laurentius-apotheke-erwitte.de'],

    // Serverseitige Status-Events (funnel_status_A/B/C) an Plausible – optional
    'plausible' => ['domain' => null, 'api' => 'https://plausible.io/api/event'],
];
