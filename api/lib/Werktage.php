<?php
declare(strict_types=1);

/** Werktage (Mo–Fr) ohne gesetzliche Feiertage in NRW. */
final class Werktage
{
    private const TAGE = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    private const MONATE = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public static function plus(DateTimeImmutable $start, int $n): DateTimeImmutable
    {
        $d = $start->setTime(12, 0);
        while ($n > 0) {
            $d = $d->modify('+1 day');
            if (self::istWerktag($d)) $n--;
        }
        return $d;
    }

    public static function istWerktag(DateTimeImmutable $d): bool
    {
        return (int)$d->format('N') <= 5 && !in_array($d->format('m-d'), self::feiertage((int)$d->format('Y')), true);
    }

    /** @return string[] Feiertage NRW im Format m-d */
    public static function feiertage(int $jahr): array
    {
        $ostern = self::ostersonntag($jahr);
        $rel = fn(int $t) => $ostern->modify(($t >= 0 ? '+' : '') . $t . ' days')->format('m-d');
        return ['01-01', $rel(-2), $rel(1), '05-01', $rel(39), $rel(50), $rel(60), '10-03', '11-01', '12-25', '12-26'];
    }

    /** Gaußsche Osterformel (anonymer gregorianischer Algorithmus). */
    public static function ostersonntag(int $y): DateTimeImmutable
    {
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
        $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $monat = intdiv($h + $l - 7 * $m + 114, 31); $tag = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 12:00:00', $y, $monat, $tag), new DateTimeZone('Europe/Berlin'));
    }

    public static function deutsch(DateTimeImmutable $d): string
    {
        return self::TAGE[(int)$d->format('w')] . ', ' . (int)$d->format('j') . '. ' . self::MONATE[(int)$d->format('n')];
    }
}
