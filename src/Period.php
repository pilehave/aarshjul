<?php
declare(strict_types=1);

/**
 * Den periode, hjulet og listen viser: hele årshjulet, et kvartal eller en måned (zoom, issue #11).
 * Zoomet står i adressen som ?zoom=q1..q4 (kvartal i årshjulet) eller ?zoom=YYYY-MM (måned).
 * Kvartalerne følger startmåneden: med august som start er K1 august–oktober.
 *
 * En periode er et array: kind (year|quarter|month), year (årshjulets år), zoom (null, "q2" eller "2026-10"),
 * from/to (Y-m-d), quarter (1-4 eller null) og month (YYYY-MM eller null).
 */
final class Period
{
    /** Perioden ud fra årshjulets år og ?zoom. Et ugyldigt zoom giver hele året. En måned bestemmer selv året. */
    public static function fromRequest(int $year, ?string $zoom): array
    {
        $zoom = (string)$zoom;
        if (preg_match('/^q([1-4])$/', $zoom, $m)) {
            return self::quarter($year, (int)$m[1]);
        }
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $zoom, $m)) {
            return self::month((int)$m[1], (int)$m[2]);
        }
        [$from, $to] = year_bounds($year);
        return ['kind' => 'year', 'year' => $year, 'zoom' => null, 'from' => $from, 'to' => $to, 'quarter' => null, 'month' => null];
    }

    public static function quarter(int $year, int $q): array
    {
        $from = (new DateTimeImmutable(year_bounds($year)[0]))->modify('+' . (3 * ($q - 1)) . ' months');
        return ['kind' => 'quarter', 'year' => $year, 'zoom' => "q$q", 'from' => $from->format('Y-m-d'),
            'to' => $from->modify('+3 months -1 day')->format('Y-m-d'), 'quarter' => $q, 'month' => null];
    }

    /** En kalendermåned. Årshjulets år og kvartal udregnes ud fra startmåneden. */
    public static function month(int $y, int $m): array
    {
        $start = Settings::startMonth();
        $year = $m < $start ? $y - 1 : $y;
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m));
        return ['kind' => 'month', 'year' => $year, 'zoom' => $from->format('Y-m'), 'from' => $from->format('Y-m-d'),
            'to' => $from->format('Y-m-t'), 'quarter' => intdiv(($m - $start + 12) % 12, 3) + 1, 'month' => $from->format('Y-m')];
    }

    /** Forrige (-1) eller næste (+1) periode af samme slags. Kvartaler og måneder fortsætter ind i nabo-årene. */
    public static function step(array $p, int $dir): array
    {
        return match ($p['kind']) {
            'quarter' => $p['quarter'] + $dir < 1 ? self::quarter($p['year'] - 1, 4)
                : ($p['quarter'] + $dir > 4 ? self::quarter($p['year'] + 1, 1) : self::quarter($p['year'], $p['quarter'] + $dir)),
            'month'   => (fn(DateTimeImmutable $d) => self::month((int)$d->format('Y'), (int)$d->format('n')))
                ((new DateTimeImmutable($p['from']))->modify(($dir > 0 ? '+' : '-') . '1 month')),
            default   => self::fromRequest($p['year'] + $dir, null),
        };
    }

    /** De tre måneder i et kvartal i årshjulet $year. */
    public static function monthsOfQuarter(int $year, int $q): array
    {
        $first = new DateTimeImmutable(self::quarter($year, $q)['from']);
        return array_map(fn($i) => self::month((int)$first->modify("+$i months")->format('Y'), (int)$first->modify("+$i months")->format('n')), [0, 1, 2]);
    }

    /** Overskrift: "2026/27", "K2" eller "Oktober" */
    public static function title(array $p): string
    {
        return match ($p['kind']) {
            'quarter' => 'K' . $p['quarter'],
            'month'   => ucfirst(MONTHS_DA[(int)substr($p['month'], 5)]),
            default   => year_label($p['year']),
        };
    }

    /** Linjen under overskriften: årshjulets år ved et kvartal, kalenderåret ved en måned, ellers null */
    public static function subtitle(array $p): ?string
    {
        return match ($p['kind']) {
            'quarter' => year_label($p['year']),
            'month'   => substr($p['month'], 0, 4),
            default   => null,
        };
    }

    /** Hele navnet på én linje, fx "K2 2026/27" eller "Oktober 2026" */
    public static function label(array $p): string
    {
        return trim(self::title($p) . ' ' . self::subtitle($p));
    }
}
