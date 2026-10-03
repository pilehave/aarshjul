<?php
declare(strict_types=1);

/** Indstillinger for hele årshjulet, gemt som navn/værdi-par i tabellen settings. */
final class Settings
{
    public const DEFAULTS = [
        'start_month' => '1', // den måned, årshjulet starter med (1 = januar)
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

    /** Gemmer indstillingssiden. Returnerer fejl[]. */
    public static function saveAll(array $in): array
    {
        $month = (int)($in['start_month'] ?? 0);
        if ($month < 1 || $month > 12) {
            return ['Vælg en gyldig startmåned.'];
        }
        try {
            db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
                ->execute(['start_month', (string)$month]);
        } catch (PDOException $e) {
            return ['Kunne ikke gemme: ' . $e->getMessage()];
        }
        self::$cache = null;
        return [];
    }
}
