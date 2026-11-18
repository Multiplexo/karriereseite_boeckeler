<?php
declare(strict_types=1);

/** E-Mail-Vorlagen: Eingangsmail an die Apotheke und Bestätigung an Bewerber/in (HTML + Plain-Text). */
final class Vorlagen
{
    public function __construct(private Scoring $scoring, private array $funnel, private array $site) {}

    private static function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

    /** Klartext-Label einer Antwort. */
    private function label(array $frage, string $wert): string
    {
        return $this->scoring->option($frage, $wert)['label'] ?? $wert;
    }

    private function kurz(array $b, string $id): ?string
    {
        foreach ($this->scoring->pfad($b['stelle']) as $f) {
            if ($f['id'] === $id && isset($b['antworten'][$id])) {
                $o = $this->scoring->option($f, (string)$b['antworten'][$id]);
                return $o['kurz'] ?? $o['label'] ?? null;
            }
        }
        return null;
    }

    /** Betreff z. B. „[A] Neue Bewerbung PTA – Maria Muster – Start sofort, Teilzeit 20–30 h“ */
    public function betreffIntern(array $b): string
    {
        $k = $b['kontakt'];
        $fakten = $b['stelle'] === 'ausbildung'
            ? array_filter([$this->kurz($b, 'art'), $this->kurz($b, 'zeitraum')])
            : array_filter([$this->kurz($b, 'start'), $this->kurz($b, 'stunden')]);
        $name = trim($k['vorname'] . ' ' . $k['nachname']);
        return sprintf('[%s] Neue Bewerbung %s – %s%s', $b['bewertung']['status'], $b['stelle_label'], $name, $fakten ? ' – ' . implode(', ', $fakten) : '');
    }

    /** @return array<int,array{0:string,1:string}> Frage → Antwort in Klartext */
    private function tabelle(array $b): array
    {
        $zeilen = [['Stelle', $b['stelle_label']]];
        foreach ($this->scoring->pfad($b['stelle']) as $f) {
            $id = $f['id'];
            if ($id === 'stelle' || $f['typ'] === 'kontakt') continue;
            $w = $b['antworten'][$id] ?? null;
            if (is_array($w)) $text = $w ? implode(', ', array_map(fn($x) => $this->label($f, $x), $w)) : '–';
            else $text = $w !== null ? $this->label($f, $w) : '–';
            if (isset($f['zusatz']) && !empty($b['zusatz'][$f['zusatz']['id']])) $text .= ' (PLZ ' . $b['zusatz'][$f['zusatz']['id']] . ')';
            if (isset($f['freitext']) && !empty($b['zusatz']['freitext'])) $text .= ' – „' . $b['zusatz']['freitext'] . '“';
            $zeilen[] = [$f['frage'], $text];
        }
        if ($b['kontakt']['nachricht'] !== '') $zeilen[] = ['Nachricht', $b['kontakt']['nachricht']];
        $zeilen[] = ['Talentpool (12 Monate)', $b['kontakt']['talentpool'] ? 'Ja, Einwilligung erteilt' : 'Nein'];
        return $zeilen;
    }

    private function metaZeilen(array $b): array
    {
        $q = $b['quelle'];
        $utm = array_filter([
            $q['utm_source'] ?? null ? 'Quelle: ' . $q['utm_source'] : null,
            $q['utm_medium'] ?? null ? 'Medium: ' . $q['utm_medium'] : null,
            $q['utm_campaign'] ?? null ? 'Kampagne: ' . $q['utm_campaign'] : null,
            $q['utm_term'] ?? null ? 'Begriff: ' . $q['utm_term'] : null,
            $q['utm_content'] ?? null ? 'Inhalt: ' . $q['utm_content'] : null,
        ]);
        return [
            ['Eingang', $b['eingang_anzeige']],
            ['Herkunft', $utm ? implode(' · ', $utm) : (!empty($q['referrer']) ? 'Verweis: ' . $q['referrer'] : 'direkt / unbekannt')],
            ['Einstiegsseite', $q['einstieg'] ?? '–'],
            ['Bewerbungs-ID', $b['id']],
        ];
    }

    public function intern(array $b): array
    {
        $k = $b['kontakt'];
        $bw = $b['bewertung'];
        $st = $this->funnel['status_texte'][$bw['status']];
        $tel = preg_replace('/[^+0-9]/', '', $k['telefon']);
        $name = trim($k['vorname'] . ' ' . $k['nachname']);
        $anhangHinweis = match (true) {
            !empty($b['anhang']['link']) => 'Lebenslauf (' . $b['anhang']['name'] . ') zu groß für den Anhang – Download bis ' . $b['anhang']['ablauf_anzeige'] . ': ' . $b['anhang']['link'],
            !empty($b['anhang']['name']) => 'Lebenslauf im Anhang: ' . $b['anhang']['name'],
            default => 'Kein Lebenslauf hochgeladen.',
        };

        // ---- HTML ----
        $e = fn($s) => self::e($s);
        $rows = '';
        foreach ($this->tabelle($b) as [$f, $a]) {
            $rows .= '<tr><td style="padding:8px 10px;border-bottom:1px solid #eee;color:#555;vertical-align:top;width:42%">' . $e($f) . '</td>'
                . '<td style="padding:8px 10px;border-bottom:1px solid #eee;font-weight:600;vertical-align:top">' . nl2br($e($a)) . '</td></tr>';
        }
        $meta = '';
        foreach ($this->metaZeilen($b) as [$f, $a]) {
            $meta .= '<tr><td style="padding:3px 10px 3px 0;color:#777">' . $e($f) . '</td><td style="padding:3px 0;color:#555">' . $e($a) . '</td></tr>';
        }
        $anhangHtml = !empty($b['anhang']['link'])
            ? 'Lebenslauf zu groß für den Anhang: <a href="' . $e($b['anhang']['link']) . '">' . $e($b['anhang']['name']) . ' herunterladen</a> (Link gültig bis ' . $e($b['anhang']['ablauf_anzeige']) . ')'
            : $e($anhangHinweis);

        $html = '<!doctype html><html lang="de"><body style="margin:0;background:#f4f2f0;font-family:Arial,Helvetica,sans-serif;color:#1f1b1a">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f2f0;padding:20px 0"><tr><td align="center">'
            . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#fff;border-radius:10px;overflow:hidden">'
            . '<tr><td style="padding:20px 24px;border-top:6px solid ' . $st['farbe'] . '">'
            . '<span style="display:inline-block;background:' . $st['farbe'] . ';color:#fff;font-weight:700;font-size:18px;padding:6px 14px;border-radius:6px">' . $bw['status'] . ' – ' . $e($st['label']) . '</span>'
            . '<span style="margin-left:10px;color:#555">Score ' . $bw['score'] . '/100 · ' . $e($st['aktion']) . '</span>'
            . '<h1 style="font-size:22px;margin:16px 0 4px">' . $e($name) . '</h1>'
            . '<p style="margin:0 0 12px;color:#555">Bewerbung als <strong>' . $e($b['stelle_label']) . '</strong></p>'
            . '<p style="margin:0;font-size:16px;line-height:1.7">📞 <a href="tel:' . $e($tel) . '" style="color:#a3121f;font-weight:700">' . $e($k['telefon']) . '</a><br>'
            . '✉️ <a href="mailto:' . $e($k['email']) . '" style="color:#a3121f">' . $e($k['email']) . '</a><br>'
            . '🕑 Beste Rückrufzeit: <strong>' . $e($k['rueckruf'] ?: 'egal') . '</strong></p>'
            . '</td></tr>'
            . '<tr><td style="padding:0 24px 8px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:15px">' . $rows . '</table></td></tr>'
            . '<tr><td style="padding:12px 24px;font-size:15px">' . $anhangHtml . '</td></tr>'
            . '<tr><td style="padding:12px 24px 20px;background:#faf8f6;font-size:12px"><table role="presentation" cellpadding="0" cellspacing="0">' . $meta . '</table>'
            . '<p style="color:#777;margin:10px 0 0">Der Status ist nur eine Priorisierungshilfe – entschieden wird immer persönlich. „Antworten“ geht direkt an die Bewerberin/den Bewerber.</p></td></tr>'
            . '</table></td></tr></table></body></html>';

        // ---- Text ----
        $t = "[{$bw['status']}] {$st['label']} – Score {$bw['score']}/100 – {$st['aktion']}\n\n"
            . "$name – {$b['stelle_label']}\n"
            . "Telefon: {$k['telefon']}\nE-Mail: {$k['email']}\nBeste Rückrufzeit: " . ($k['rueckruf'] ?: 'egal') . "\n\n";
        foreach ($this->tabelle($b) as [$f, $a]) $t .= "$f\n  → $a\n";
        $t .= "\n$anhangHinweis\n\n";
        foreach ($this->metaZeilen($b) as [$f, $a]) $t .= "$f: $a\n";
        $t .= "\nDer Status ist nur eine Priorisierungshilfe – entschieden wird immer persönlich.\n";

        return ['betreff' => $this->betreffIntern($b), 'html' => $html, 'text' => $t];
    }

    public function bestaetigung(array $b): array
    {
        $k = $b['kontakt'];
        $s = $this->site;
        $e = fn($x) => self::e($x);
        $inh = $s['inhaberin'];
        $betreff = 'Danke für deine Bewerbung, ' . $k['vorname'] . '!';
        $html = '<!doctype html><html lang="de"><body style="margin:0;background:#f4f2f0;font-family:Arial,Helvetica,sans-serif;color:#1f1b1a">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:20px 0"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:10px;border-top:6px solid #e2001a">'
            . '<tr><td style="padding:24px;font-size:16px;line-height:1.6">'
            . '<p style="font-weight:700;color:#e2001a;letter-spacing:.04em;margin:0 0 16px">LAURENTIUS APOTHEKE</p>'
            . '<p>Hallo ' . $e($k['vorname']) . ',</p>'
            . '<p>vielen Dank für deine Bewerbung als <strong>' . $e($b['stelle_label']) . '</strong>. Sie ist bei uns angekommen.</p>'
            . '<p style="background:#fbeceb;border-radius:8px;padding:14px 16px"><strong>So geht es weiter:</strong> Wir rufen dich bis spätestens <strong>' . $e($b['rueckruf_bis']) . '</strong> an'
            . ($k['rueckruf'] && $k['rueckruf'] !== 'Egal' ? ' – am liebsten ' . $e(mb_strtolower(preg_replace('/\s*\(.*\)$/', '', $k['rueckruf']))) : '') . '. Danach lernen wir uns gern persönlich kennen – auf Wunsch mit Probetag.</p>'
            . '<p>Fragen in der Zwischenzeit? Ruf uns an: <a href="tel:' . $e(preg_replace('/\s+/', '', $s['telefon'])) . '" style="color:#a3121f;font-weight:700">' . $e($s['telefon_anzeige']) . '</a> oder antworte einfach auf diese E-Mail.</p>'
            . '<p>Herzliche Grüße<br>' . $e($inh['name']) . ' und das Team der ' . $e($s['name']) . '</p>'
            . '<p style="font-size:12px;color:#777;border-top:1px solid #eee;padding-top:12px;margin-top:20px">' . $e($s['name']) . ' · ' . $e($s['adresse']['strasse']) . ' · ' . $e($s['adresse']['plz'] . ' ' . $s['adresse']['ort']) . '<br>'
            . 'Wie wir mit deinen Daten umgehen und wann wir sie löschen, liest du in unseren <a href="' . $e($s['karriere_url']) . '/datenschutz-bewerbung/" style="color:#777">Datenschutzhinweisen für Bewerber/innen</a>. '
            . 'Kurz: Ohne Talentpool-Einwilligung löschen wir deine Unterlagen spätestens 6 Monate nach Abschluss des Verfahrens.</p>'
            . '</td></tr></table></td></tr></table></body></html>';
        $text = "Hallo {$k['vorname']},\n\nvielen Dank für deine Bewerbung als {$b['stelle_label']}. Sie ist bei uns angekommen.\n\n"
            . "So geht es weiter: Wir rufen dich bis spätestens {$b['rueckruf_bis']} an. Danach lernen wir uns gern persönlich kennen – auf Wunsch mit Probetag.\n\n"
            . "Fragen in der Zwischenzeit? Ruf uns an: {$s['telefon_anzeige']} – oder antworte einfach auf diese E-Mail.\n\n"
            . "Herzliche Grüße\n{$inh['name']} und das Team der {$s['name']}\n\n--\n{$s['name']} · {$s['adresse']['strasse']} · {$s['adresse']['plz']} {$s['adresse']['ort']}\n"
            . "Datenschutzhinweise: {$s['karriere_url']}/datenschutz-bewerbung/\n"
            . "Ohne Talentpool-Einwilligung löschen wir deine Unterlagen spätestens 6 Monate nach Abschluss des Verfahrens.\n";
        return ['betreff' => $betreff, 'html' => $html, 'text' => $text];
    }
}
