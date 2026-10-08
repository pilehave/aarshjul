<?php
declare(strict_types=1);

/** Indstillinger for hele årshjulet, gemt som navn/værdi-par i tabellen settings. */
final class Settings
{
    public const DEFAULTS = [
        'start_month' => '1',    // den måned, årshjulet starter med (1 = januar)
        'ring_mode'   => 'auto', // hvordan begivenhederne fordeles i ringe, se RING_MODES
        'ring_names'  => '1',    // vis kategorinavne ved "Én ring pr. kategori" (1/0)
    ];

    /** Fordeling af begivenhederne i hjulets ringe (se Wheel::rings) */
    public const RING_MODES = [
        'auto'     => 'Automatisk (efter hvor der er plads)',
        'category' => 'Én ring pr. kategori',
        'person'   => 'Én ring pr. person',
    ];

    private static ?array $cache = null;

    public static function get(string $name): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (db()->query('SELECT name, value FROM settings') as $r) {
                    self::$cache[$r['name']] = (string)$r['value'];
                }
            } catch (PDOException) {
                // Tabellen findes ikke endnu (migrationen er ikke kørt): brug standardværdierne
            }
        }
        return self::$cache[$name] ?? self::DEFAULTS[$name] ?? '';
    }

    public static function startMonth(): int
    {
        $m = (int)self::get('start_month');
        return ($m >= 1 && $m <= 12) ? $m : 1;
    }

    public static function ringMode(): string
    {
        $mode = self::get('ring_mode');
        return isset(self::RING_MODES[$mode]) ? $mode : 'auto';
    }

    public static function ringNames(): bool
    {
        return self::get('ring_names') !== '0';
    }

    /** Gemmer indstillingssiden. Returnerer fejl[]. */
    public static function saveAll(array $in): array
    {
        $month = (int)($in['start_month'] ?? 0);
        $mode = (string)($in['ring_mode'] ?? 'auto');
        $errors = [];
        if ($month < 1 || $month > 12) {
            $errors[] = 'Vælg en gyldig startmåned.';
        }
        if (!isset(self::RING_MODES[$mode])) {
            $errors[] = 'Vælg en gyldig fordeling af ringe.';
        }
        if ($errors) {
            return $errors;
        }
        try {
            $st = db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
            $st->execute(['start_month', (string)$month]);
            $st->execute(['ring_mode', $mode]);
            $st->execute(['ring_names', empty($in['ring_names']) ? '0' : '1']);
        } catch (PDOException $e) {
            return ['Kunne ikke gemme: ' . $e->getMessage()];
        }
        self::$cache = null;
        return [];
    }
}
