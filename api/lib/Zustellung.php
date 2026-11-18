<?php
declare(strict_types=1);

/** Stellt eine zwischengespeicherte Bewerbung zu (Eingangsmail + Bestätigung) und löscht sie danach. */
final class Zustellung
{
    public function __construct(
        private array $cfg,
        private Speicher $speicher,
        private Mailer $mailer,
        private Vorlagen $vorlagen,
        private array $site,
    ) {}

    /** @return bool true, wenn vollständig zugestellt (und gelöscht) */
    public function zustellen(string $id, int $wiederholungen = 3): bool
    {
        $meta = $this->speicher->meta($id);
        $b = $this->speicher->lesen($id);
        $meta['versuche'] = ($meta['versuche'] ?? 0) + 1;
        $fehler = [];

        if (empty($meta['intern'])) {
            $v = $this->vorlagen->intern($b);
            $anhaenge = [];
            if (!empty($b['anhang']['inhalt'])) {
                $anhaenge[] = ['name' => $b['anhang']['name'], 'inhalt' => base64_decode($b['anhang']['inhalt']), 'mime' => $b['anhang']['mime']];
            }
            $err = $this->mailer->mitWiederholung([
                'an' => $this->cfg['empfaenger'],
                'cc' => $this->cfg['cc'] ?? [],
                'von' => $this->cfg['absender']['email'],
                'von_name' => $this->cfg['absender']['name'],
                'antwort_an' => $b['kontakt']['email'],
                'antwort_an_name' => trim($b['kontakt']['vorname'] . ' ' . $b['kontakt']['nachname']),
                'betreff' => $v['betreff'], 'html' => $v['html'], 'text' => $v['text'], 'anhaenge' => $anhaenge,
            ], $wiederholungen);
            if ($err === null) $meta['intern'] = true; else $fehler[] = "Eingangsmail: $err";
        }

        if (empty($meta['bestaetigung'])) {
            $v = $this->vorlagen->bestaetigung($b);
            $err = $this->mailer->mitWiederholung([
                'an' => $b['kontakt']['email'],
                'von' => $this->cfg['absender']['email'],
                'von_name' => $this->cfg['bestaetigung_absender_name'] ?? $this->cfg['absender']['name'],
                'antwort_an' => $this->cfg['empfaenger'],
                'antwort_an_name' => $this->site['name'],
                'betreff' => $v['betreff'], 'html' => $v['html'], 'text' => $v['text'],
            ], $wiederholungen);
            if ($err === null) $meta['bestaetigung'] = true; else $fehler[] = "Bestätigung: $err";
        }

        if (!empty($meta['intern']) && !empty($meta['bestaetigung'])) {
            $this->speicher->entfernen($id);
            return true;
        }

        $meta['letzter_fehler'] = implode(' | ', $fehler);
        if (empty($meta['alarmiert'])) {
            $this->alarm($id, $meta);
            $meta['alarmiert'] = true;
        }
        $this->speicher->meta($id, $meta);
        return false;
    }

    /** Alarm an den technischen Ansprechpartner – ohne Bewerberdaten. */
    private function alarm(string $id, array $meta): void
    {
        $text = "Eine Bewerbung über die Karriereseite konnte nach mehreren Versuchen nicht vollständig zugestellt werden.\n\n"
            . "Bewerbungs-ID: $id\nEingangsmail zugestellt: " . (!empty($meta['intern']) ? 'ja' : 'NEIN') . "\n"
            . "Bestätigung zugestellt: " . (!empty($meta['bestaetigung']) ? 'ja' : 'NEIN') . "\nFehler: {$meta['letzter_fehler']}\n\n"
            . "Die Bewerbung liegt verschlüsselt in der Warteschlange und wird vom Cronjob (queue-worker.php) automatisch erneut versendet.\n";
        error_log("[karriere] Zustellung fehlgeschlagen für $id: {$meta['letzter_fehler']}");
        if (empty($this->cfg['technik_alarm'])) return;
        $err = $this->mailer->mitWiederholung([
            'an' => $this->cfg['technik_alarm'],
            'von' => $this->cfg['absender']['email'],
            'von_name' => 'Karriereseite – Alarm',
            'betreff' => '[ALARM] Bewerbung nicht zugestellt – ' . $id,
            'html' => '<pre style="font-family:monospace">' . htmlspecialchars($text) . '</pre>',
            'text' => $text,
        ], 1);
        if ($err) error_log("[karriere] Alarm-Mail ebenfalls fehlgeschlagen: $err");
    }
}
