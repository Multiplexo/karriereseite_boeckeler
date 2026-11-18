<?php
declare(strict_types=1);

/**
 * Serverseitige Auswertung des Funnels: Pfad, Validierung der Antworten, Score (0–100) und Status (A/B/C).
 * Folgt denselben Regeln wie src/lib/funnel-logic.js. Konfiguration: src/data/funnel.json.
 */
final class Scoring
{
    public function __construct(private array $funnel) {}

    public function fragen(): array { return $this->funnel['fragen']; }
    public function rolle(string $id): ?array { return $this->funnel['rollen'][$id] ?? null; }

    /** Löst rollenabhängige Varianten auf; null = Frage gilt nicht für diese Rolle. */
    public function aufloesen(array $f, string $rolle): ?array
    {
        if ($f['id'] !== 'stelle' && isset($f['rollen']) && !in_array($rolle, $f['rollen'], true)) {
            return null;
        }
        if (isset($f['varianten'])) {
            if (!isset($f['varianten'][$rolle])) return null;
            $f = array_merge($f, $f['varianten'][$rolle]);
            unset($f['varianten']);
        }
        if (isset($f['punkte_je_rolle'][$rolle]) && isset($f['optionen'])) {
            foreach ($f['optionen'] as &$o) {
                if (isset($f['punkte_je_rolle'][$rolle][$o['wert']])) $o['punkte'] = $f['punkte_je_rolle'][$rolle][$o['wert']];
            }
            unset($o);
        }
        return $f;
    }

    /** @return array<int,array> aufgelöste Fragen im Pfad dieser Rolle (inkl. „stelle“). */
    public function pfad(string $rolle): array
    {
        $out = [];
        foreach ($this->funnel['fragen'] as $f) {
            $r = $this->aufloesen($f, $rolle);
            if ($r !== null) $out[] = $r;
        }
        return $out;
    }

    public function option(array $frage, string $wert): ?array
    {
        foreach ($frage['optionen'] ?? [] as $o) if ($o['wert'] === $wert) return $o;
        return null;
    }

    /**
     * Prüft die Antworten gegen die erlaubten Optionen. Gibt bereinigte Antworten zurück oder wirft InvalidArgumentException.
     * @return array<string,string|array>
     */
    public function bereinige(string $rolle, array $antworten): array
    {
        $sauber = [];
        foreach ($this->pfad($rolle) as $f) {
            $id = $f['id'];
            if ($id === 'stelle' || $f['typ'] === 'kontakt') continue;
            $w = $antworten[$id] ?? null;
            if ($f['typ'] === 'mehrfach') {
                $w = is_array($w) ? array_values(array_unique(array_filter($w, 'is_string'))) : [];
                $max = (int)($f['max'] ?? count($f['optionen']));
                if (count($w) > $max) throw new InvalidArgumentException("Zu viele Antworten bei „{$f['frage']}“.");
                foreach ($w as $x) if (!$this->option($f, $x)) throw new InvalidArgumentException("Ungültige Antwort bei „{$f['frage']}“.");
                $sauber[$id] = $w;
            } else {
                if (!is_string($w) || !$this->option($f, $w)) throw new InvalidArgumentException("Bitte beantworte noch: „{$f['frage']}“.");
                $sauber[$id] = $w;
            }
        }
        return $sauber;
    }

    /** @return array{score:int,status:string,match:string,punkte:int,max:int,details:array} */
    public function bewerte(string $rolle, array $antworten): array
    {
        $summe = 0; $max = 0; $match = 'passend'; $details = [];
        $wegIst = 0; $wegMax = 0;
        foreach ($this->pfad($rolle) as $f) {
            if (($f['typ'] ?? '') !== 'einzel' || empty($f['optionen'])) continue;
            $pkt = array_map(fn($o) => $o['punkte'] ?? null, $f['optionen']);
            $pkt = array_filter($pkt, fn($p) => $p !== null);
            if (!$pkt && ($f['wertung'] ?? '') !== 'abschluss') continue;
            $fmax = $pkt ? max($pkt) : 0;
            $opt = $this->option($f, (string)($antworten[$f['id']] ?? ''));
            $ist = (int)($opt['punkte'] ?? 0);
            $summe += $ist; $max += $fmax;
            $details[$f['id']] = [$ist, $fmax];
            if (($f['wertung'] ?? '') === 'abschluss' && $opt) $match = $opt['match'] ?? 'passend';
            if (($f['wertung'] ?? '') === 'arbeitsweg') { $wegIst = $ist; $wegMax = $fmax; }
        }
        $score = $max > 0 ? (int)round($summe / $max * 100) : 0;
        $status = $this->status($score, $match);

        // AGG: Der Arbeitsweg darf nie allein zu Status C führen.
        if ($status === 'C' && $match !== 'anders' && $max > 0) {
            $ohneWegNachteil = (int)round(($summe - $wegIst + $wegMax) / $max * 100);
            if ($this->status($ohneWegNachteil, $match) !== 'C') $status = 'B';
        }
        // Mindeststatus je Rolle (Initiativbewerbungen immer mindestens B).
        $mindest = $this->funnel['rollen'][$rolle]['mindest_status'] ?? null;
        if ($mindest && strcmp($status, $mindest) > 0) $status = $mindest;

        return ['score' => $score, 'status' => $status, 'match' => $match, 'punkte' => $summe, 'max' => $max, 'details' => $details];
    }

    private function status(int $score, string $match): string
    {
        $s = $this->funnel['schwellen'];
        if ($match === 'anders') return 'C';
        if ($score >= $s['A'] && $match === 'passend') return 'A';
        if ($score >= $s['B']) return 'B';
        return 'C';
    }
}
