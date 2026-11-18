<?php
declare(strict_types=1);

/** Symmetrische Verschlüsselung (libsodium secretbox) und HMAC-Signaturen mit dem Schlüssel aus config.php. */
final class Krypto
{
    private string $key;

    public function __construct(string $base64Key)
    {
        $k = base64_decode($base64Key, true);
        if ($k === false || strlen($k) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException('config.php: "schluessel" fehlt oder ist ungültig (32 Byte, base64).');
        }
        $this->key = $k;
    }

    public function verschluesseln(string $klartext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($klartext, $nonce, $this->key);
    }

    public function entschluesseln(string $daten): string
    {
        $nonce = substr($daten, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($daten, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $r = sodium_crypto_secretbox_open($box, $nonce, $this->key);
        if ($r === false) throw new RuntimeException('Entschlüsselung fehlgeschlagen.');
        return $r;
    }

    public function signiere(string $nachricht): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $nachricht, $this->key, true)), '+/', '-_'), '=');
    }

    public function pruefe(string $nachricht, string $sig): bool
    {
        return hash_equals($this->signiere($nachricht), $sig);
    }

    /** Zeitfallen-Token: signierter Ausgabezeitpunkt. */
    public function token(): string
    {
        $t = (string)time();
        return $t . '.' . $this->signiere('token:' . $t);
    }

    /** @return int|null Alter des Tokens in Sekunden oder null, wenn ungültig. */
    public function tokenAlter(string $token): ?int
    {
        if (!preg_match('/^(\d{9,11})\.([A-Za-z0-9_-]+)$/', $token, $m)) return null;
        if (!$this->pruefe('token:' . $m[1], $m[2])) return null;
        return time() - (int)$m[1];
    }

    public function ipHash(string $ip): string
    {
        return substr(hash_hmac('sha256', 'ip:' . $ip, $this->key), 0, 32);
    }
}
