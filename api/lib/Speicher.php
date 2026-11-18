<?php
declare(strict_types=1);

/**
 * Verschlüsselte Zwischenablage: Bewerbungen bleiben nur so lange auf dem Server, bis beide Mails zugestellt sind.
 * Große Lebensläufe werden (verschlüsselt) für den signierten Download-Link vorgehalten und nach Ablauf gelöscht.
 */
final class Speicher
{
    public function __construct(private string $dir, private Krypto $krypto)
    {
        foreach (['queue', 'dateien', 'ratelimit'] as $sub) {
            if (!is_dir("$dir/$sub") && !mkdir("$dir/$sub", 0700, true) && !is_dir("$dir/$sub")) {
                throw new RuntimeException("Speicherordner $dir/$sub nicht anlegbar.");
            }
        }
    }

    public static function neueId(): string
    {
        return gmdate('Ymd-His') . '-' . bin2hex(random_bytes(6));
    }

    private function pfad(string $sub, string $id, string $ext): string
    {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/', $id)) throw new InvalidArgumentException('Ungültige ID');
        return "{$this->dir}/$sub/$id.$ext";
    }

    // ---- Warteschlange ----
    public function ablegen(string $id, array $bewerbung): void
    {
        $this->schreibe($this->pfad('queue', $id, 'bin'), $this->krypto->verschluesseln(json_encode($bewerbung, JSON_THROW_ON_ERROR)));
        $this->meta($id, ['erstellt' => time(), 'versuche' => 0, 'intern' => false, 'bestaetigung' => false, 'alarmiert' => false]);
    }

    public function lesen(string $id): array
    {
        return json_decode($this->krypto->entschluesseln((string)file_get_contents($this->pfad('queue', $id, 'bin'))), true, 512, JSON_THROW_ON_ERROR);
    }

    public function meta(string $id, ?array $neu = null): array
    {
        $p = $this->pfad('queue', $id, 'meta.json');
        if ($neu !== null) { $this->schreibe($p, json_encode($neu)); return $neu; }
        return is_file($p) ? (json_decode((string)file_get_contents($p), true) ?: []) : [];
    }

    public function entfernen(string $id): void
    {
        foreach (['bin', 'meta.json'] as $ext) { $p = $this->pfad('queue', $id, $ext); if (is_file($p)) unlink($p); }
    }

    /** @return string[] IDs in der Warteschlange */
    public function offen(): array
    {
        return array_map(fn($p) => basename($p, '.meta.json'), glob("{$this->dir}/queue/*.meta.json") ?: []);
    }

    // ---- Dateien für Download-Link ----
    public function dateiAblegen(string $id, string $name, string $mime, string $inhalt, int $tage): int
    {
        $ablauf = time() + $tage * 86400;
        $this->schreibe($this->pfad('dateien', $id, 'bin'), $this->krypto->verschluesseln(json_encode([
            'name' => $name, 'mime' => $mime, 'ablauf' => $ablauf, 'inhalt' => base64_encode($inhalt),
        ], JSON_THROW_ON_ERROR)));
        return $ablauf;
    }

    public function dateiLesen(string $id): ?array
    {
        $p = $this->pfad('dateien', $id, 'bin');
        if (!is_file($p)) return null;
        $d = json_decode($this->krypto->entschluesseln((string)file_get_contents($p)), true);
        if (!$d || $d['ablauf'] < time()) { @unlink($p); return null; }
        $d['inhalt'] = base64_decode($d['inhalt']);
        return $d;
    }

    /** Löscht abgelaufene Download-Dateien und alte Rate-Limit-Einträge. @return int Anzahl gelöschter Dateien */
    public function aufraeumen(int $downloadTage): int
    {
        $n = 0;
        foreach (glob("{$this->dir}/dateien/*.bin") ?: [] as $p) {
            if (filemtime($p) < time() - $downloadTage * 86400) { unlink($p); $n++; }
        }
        foreach (glob("{$this->dir}/ratelimit/*.json") ?: [] as $p) {
            if (filemtime($p) < time() - 86400) { unlink($p); $n++; }
        }
        return $n;
    }

    // ---- Rate-Limit ----
    /**
     * Prüft, ob unter dem Schlüssel im Zeitfenster noch Platz ist. Mit $zaehlen = true wird der Zugriff gleich mitgezählt.
     * @return bool true = erlaubt
     */
    public function rateLimit(string $schluessel, int $max, int $fenster, bool $zaehlen = true): bool
    {
        $p = "{$this->dir}/ratelimit/$schluessel.json";
        $fh = fopen($p, 'c+');
        if (!$fh) return true;
        flock($fh, LOCK_EX);
        $liste = json_decode((string)stream_get_contents($fh), true) ?: [];
        $liste = array_values(array_filter($liste, fn($t) => $t > time() - $fenster));
        $ok = count($liste) < $max;
        if ($ok && $zaehlen) $liste[] = time();
        ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($liste));
        flock($fh, LOCK_UN); fclose($fh);
        return $ok;
    }

    private function schreibe(string $p, string $inhalt): void
    {
        $tmp = $p . '.tmp' . bin2hex(random_bytes(3));
        if (file_put_contents($tmp, $inhalt, LOCK_EX) === false) throw new RuntimeException("Schreiben fehlgeschlagen: $p");
        chmod($tmp, 0600);
        rename($tmp, $p);
    }
}
