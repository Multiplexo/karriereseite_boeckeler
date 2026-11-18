# Karriereseite Laurentius Apotheke Erwitte

Mobile-first Karriereseite mit mehrstufigem Bewerberfunnel (Kurzbewerbung < 2 Minuten), serverseitiger Vorqualifizierung (Status A/B/C) und strukturierter E-Mail an `bewerbung@laurentius-apo-erwitte.de`. Umsetzung des Dev-Briefings vom 10.11.2026.

| Baustein | Umsetzung |
| --- | --- |
| Frontend | Astro (statischer Export), eigenes CSS ohne Framework, ca. 18 KB JavaScript |
| Funnel | Vanilla TypeScript, Fragen/Punkte in `src/data/funnel.json` |
| Backend | PHP 8.1+ (`api/bewerbung.php`), läuft bei jedem deutschen Standard-Hoster |
| Mailversand | PHPMailer über SMTP (Brevo/Mailjet o. ä. mit EU-Datenhaltung oder Hoster-SMTP), kein `mail()` |
| Tracking | optional Plausible (cookielos), serverseitige Status-Events |

## Seiten

| URL | Inhalt |
| --- | --- |
| `/karriere/` | One-Pager: Hero mit Inhaberin, Stellen, Benefits, Team, Botendienst, Ablauf, Standort, FAQ, Abschluss-CTA |
| `/karriere/pta/` · `/pka/` · `/apotheker/` · `/ausbildung/` | Stellenseiten mit JSON-LD `JobPosting` (Google for Jobs) |
| `/karriere/bewerben/?stelle=…` | Funnel (Stelle vorbelegt → Schritt 1 entfällt) |
| `/karriere/datenschutz-bewerbung/` | Datenschutzhinweise für Bewerber/innen (**Entwurf, DSB-Prüfung nötig**) |
| `/karriere/sitemap.xml`, `/robots.txt`, `/404.html` | SEO |

## Pflege ohne Entwickler

Alle Inhalte liegen in `src/data/` und lassen sich z. B. direkt in der GitHub-Weboberfläche bearbeiten. Nach jedem Speichern auf `main` baut und veröffentlicht die GitHub Action die Seite neu.

- **Stelle an/aus:** `src/data/stellen.json`, Feld `status` auf `"offen"`, `"besetzt"` oder `"entwurf"` setzen. Besetzte Stellen verschwinden aus Übersicht, Sitemap und Google for Jobs; ihre Seite bekommt `noindex` und einen Hinweis.
- **Fragen, Antworten, Punkte, Schwellenwerte:** `src/data/funnel.json`. Der Score wird je Pfad auf 0–100 normiert, die Punkte gehen nie an den Browser.
- **Entwürfe freigeben:** Alles mit `"freigegeben": false` (Benefits, FAQ, Teamzitate, Zitat der Inhaberin, Botendienst, Außenansicht) ist nur in der **Vorschau** sichtbar: `npm run build:preview`, gelb markiert und mit `noindex`. Nach Freigabe auf `true` setzen.
- **Gehalt:** je Stelle `gehalt` setzen, z. B. `{ "text": "3.100–3.600 € brutto (VZ)", "min": 3100, "max": 3600, "einheit": "MONTH" }`. Es erscheint dann auf Karte, Stellenseite und im JSON-LD (`baseSalary`).

## Fotos

Fotos werden beim Build automatisch zugeschnitten und als AVIF/WebP mit `srcset` ausgeliefert. Die Originale selbst werden nicht veröffentlicht. Fehlt eine Datei, erscheint ein Platzhalter, und das Layout bleibt gleich.

| Datei | Verwendung |
| --- | --- |
| `src/assets/team/dorothee-boeckeler.jpg` | Hero Startseite, Abschluss-CTA, Danke-Seite, OG-Bilder |
| `src/assets/team/julia-grundmann.jpg` | Team-Bereich + Apotheker-Stellenseite |
| `src/assets/team/mitarbeiterin-1.jpg`, `mitarbeiterin-2.jpg` | Team-Bereich + PTA/PKA-Seiten (Namen/Rollen in `inhalte.json` eintragen) |
| `src/assets/team/botendienst.jpg` | Abschnitt „Botendienst“, wird oben-bündig auf 16:9 beschnitten (**das Kennzeichen wird nicht ausgeliefert**) |
| `src/assets/roh/aussenansicht.jpg` | Standort: Zuschnitt auf die Ladenfront und gedämpfter HDR-Look gemäß `src/data/bilder.json` |
| `public/img/logo.svg` (oder `.png`) | Logo im Header, sonst Wortmarke |
| `public/img/logo-google.png` | quadratisches Logo für Google for Jobs |

Nach neuen Fotos `npm run og` ausführen; das erzeugt die Vorschaubilder für WhatsApp und Social Media neu.

## Lokal entwickeln & testen

```bash
npm install && (cd api && composer install)
```

```bash
npm run dev
```

```bash
npm test
```

Kompletten Ablauf inklusive PHP-Endpunkt testen: Eine `config.php` mit `'transport' => 'datei'` anlegen (Mails landen dann als `.eml` in `storage/mails`), danach:

```bash
npm run build && KARRIERE_CONFIG=/pfad/zu/config.php npm run serve
```

Dann `http://127.0.0.1:8080/karriere/` öffnen.

## Deployment

1. `npm run build`: Der Inhalt von `dist/` gehört nach `/karriere` auf dem Webspace (für die Subdomain-Variante mit `BASE_PATH=/ SITE_URL=https://karriere.laurentius-apo-erwitte.de npm run build` bauen).
2. Auf dem Server `api/config.sample.php` nach `api/config.php` kopieren und ausfüllen: SMTP-Zugang, Schlüssel (`php -r "echo base64_encode(random_bytes(32));"`), Alarm-Adresse. `storage_dir` möglichst **außerhalb** des Webroots legen.
3. Cronjob alle 15 Minuten: `php /pfad/karriere/api/queue-worker.php`. Er stellt fehlgeschlagene Mails erneut zu und löscht Download-Dateien nach 14 Tagen.
4. Für die Absenderdomain SPF, DKIM und DMARC einrichten, sonst landen Mails im Spam.
5. Optional per GitHub Action: Repository-Secrets `SFTP_HOST`, `SFTP_USER`, `SFTP_PASSWORD`, `SFTP_PATH` setzen. Die Action testet, baut und spiegelt `dist/` per SFTP (`config.php` und `storage/` bleiben unangetastet).
6. Search Console: Sitemap einreichen. Läuft die Seite unter `/karriere`, gehört die Sitemap-Zeile auch in die `robots.txt` der Hauptdomain.

Voraussetzungen beim Hoster: PHP ≥ 8.1 mit `sodium`, `fileinfo`, `zip`, `curl`. Apache mit `.htaccess` (HTTPS-Umleitung, CSP/HSTS-Header und Zugriffssperren werden automatisch erzeugt). Unter nginx die Regeln aus `dist/.htaccess` übertragen und `api/` außer `bewerbung.php`/`download.php` sperren.

## Funnel: Sicherheit & Datenschutz

- Score und Status werden nur serverseitig berechnet; Antworten werden gegen die erlaubten Optionen geprüft.
- Spam-Schutz ohne reCAPTCHA: Honeypot, signierte Zeitfalle (< 5 s → sichtbare Bitte um erneutes Absenden, nie stilles Verwerfen echter Bewerbungen), Rate-Limit (5 Bewerbungen bzw. 30 Anfragen pro Stunde und IP, IP nur gehasht), Origin-Prüfung.
- Upload: PDF/JPG/PNG/DOCX, MIME-Prüfung, DOCX ohne Makros, max. 10 MB, optional ClamAV. Dateien über 5 MB kommen als signierter Download-Link (14 Tage) statt als Anhang.
- Bewerbungen liegen nur verschlüsselt (libsodium) auf dem Server, bis beide Mails zugestellt sind. Danach werden sie gelöscht. Nach 3 Fehlversuchen geht ein Alarm an die Technik (ohne Bewerberdaten), der Cronjob versucht es weiter.
- Art. 22 DSGVO: Niemand wird automatisch abgelehnt. AGG: Der Arbeitsweg kann nie allein zu Status C führen, Initiativbewerbungen sind immer mindestens B.

## Offene Punkte (vom Kunden)

Aus dem Briefing: Stellenumfänge/Startdaten bestätigen, Gehalt/Tarif, Benefits, Häufigkeit von Samstagen und Notdiensten, Du/Sie bei Apotheker-Stelle, CMS/Hoster/DNS, Postfach `bewerbung@` inkl. SPF/DKIM/DMARC, WhatsApp, Rückruf-Verantwortliche, Budget für bezahlte Reichweite.

Neu aufgefallen:

- [ ] **Ortsname „Bökela“:** Im Briefing steht „Bökela“ bzw. „Erwitte-Böckeler“. Einen Ortsteil dieses Namens konnten wir nicht verifizieren, die Seite nutzt daher durchgängig „Erwitte“. Bitte bestätigen.
- [ ] **„Sonntag frei“ vs. Notdienste:** Notdienste können auch sonntags anfallen. Die Seite sagt deshalb „Sonntag regulär frei“ und verweist bei Notdiensten auf die FAQ.
- [ ] **Außenfoto:** Wasserzeichen „LOVELT“. Nutzungsrechte beim Fotografen klären und das Original ohne Wasserzeichen in höherer Auflösung anfragen (aktuell ca. 680 px breit).
- [ ] **Fotofreigaben** (schriftlich): Julia Grundmann, die beiden Kolleginnen, die beiden Boten. Dazu Namen/Rollen und echte Zitate aus dem Interview.
- [ ] **Zitat der Inhaberin** (Entwurf): „Bei uns hat Beratung Zeit – und Feierabend ist Feierabend. Ich freue mich darauf, dich kennenzulernen.“
- [ ] **Fahrer/in Botendienst (Minijob):** als Stelle vorbereitet (`status: "entwurf"`). Bei Bedarf Stunden ergänzen und auf `"offen"` setzen.
- [ ] Logo als SVG und exakte Hausfarben. Aktuell wird das Logo-Rot `#e2001a` für CTAs und `#a3121f` für Akzente verwendet.
- [ ] Pendelzeiten, Parkplätze und Busanbindung bestätigen.
- [ ] Datenschutzhinweise durch DSB prüfen lassen und danach `datenschutzhinweise_freigegeben: true` setzen. Impressum-/Datenschutz-URLs der Hauptseite prüfen (`site.json`).
