<?php
declare(strict_types=1);

/** Indstillinger for hele årshjulet, gemt som navn/værdi-par i tabellen settings. */
final class Settings
{
    public const DEFAULTS = [
        'start_month' => '1',    // den måned, årshjulet starter med (1 = januar)
        'ring_mode'   => 'auto', // hvordan begivenhederne fordeles i ringe, se RING_MODES
        'ring_names'  => '1',    // vis kategorinavne ved "Én ring pr. kategori" (1/0)
        'topbar_bg'   => '#e30613', // baggrundsfarve i topbaren
        'topbar_fg'   => '#ffffff', // tekstfarve i topbaren
        'topbar_text' => 'Årshjul', // teksten i topbaren (ikke titlen i hjulet)
        'topbar_logo' => '',     // logoets navn i upload-mappen ('' = intet logo)
        'topbar_logo_type' => '', // logoets MIME-type
        'topbar_logo_height' => '', // logoets højde i pixels ('' = LOGO_HEIGHT_DEFAULT)
    ];

    /** Billedtyper, der kan bruges som logo */
    public const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
    public const LOGO_MAX_BYTES = 1024 * 1024;
    public const TOPBAR_TEXT_MAX = 60;
    public const LOGO_HEIGHT_DEFAULT = 32;
    public const LOGO_HEIGHT_MIN = 16;
    public const LOGO_HEIGHT_MAX = 120;

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

    public static function isColor(string $c): bool
    {
        return (bool)preg_match('/^#[0-9a-f]{6}$/i', $c);
    }

    public static function topbarBg(): string
    {
        $c = self::get('topbar_bg');
        return self::isColor($c) ? strtolower($c) : self::DEFAULTS['topbar_bg'];
    }

    public static function topbarFg(): string
    {
        $c = self::get('topbar_fg');
        return self::isColor($c) ? strtolower($c) : self::DEFAULTS['topbar_fg'];
    }

    /** Teksten i topbaren. Tom er tilladt, når der er et logo. */
    public static function topbarText(): string
    {
        $t = trim(self::get('topbar_text'));
        return $t !== '' || self::topbarLogo() ? $t : self::DEFAULTS['topbar_text'];
    }

    /** Logoets højde i pixels. Uden en (gyldig) gemt værdi bruges LOGO_HEIGHT_DEFAULT. */
    public static function logoHeight(): int
    {
        $h = self::get('topbar_logo_height');
        return ctype_digit($h) && (int)$h >= self::LOGO_HEIGHT_MIN && (int)$h <= self::LOGO_HEIGHT_MAX
            ? (int)$h : self::LOGO_HEIGHT_DEFAULT;
    }

    /** Logoet i topbaren som ['file' => sti, 'type' => MIME, 'v' => gemt navn til cache], eller null. */
    public static function topbarLogo(): ?array
    {
        $stored = self::get('topbar_logo');
        $type = self::get('topbar_logo_type');
        if (!preg_match('/^[0-9a-f]{32}$/', $stored) || !in_array($type, self::LOGO_TYPES, true)) {
            return null;
        }
        return ['file' => config('upload_dir') . '/' . $stored, 'type' => $type, 'v' => $stored];
    }

    /**
     * Billedtypen for en logofil ud fra indholdet (ikke filnavnet), eller null hvis den ikke kan bruges.
     * finfo kender ikke altid SVG uden XML-erklæring, så tekst med et <svg-element regnes også som SVG.
     */
    public static function logoType(string $path): ?string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if (in_array($mime, ['text/xml', 'application/xml', 'text/plain', 'text/html'], true)
            && preg_match('/<svg[\s>]/i', (string)file_get_contents($path, false, null, 0, 4096))) {
            $mime = 'image/svg+xml';
        }
        return in_array($mime, self::LOGO_TYPES, true) ? $mime : null;
    }

    /** Gemmer indstillingssiden. $logo er $_FILES['logo'] (eller []). Returnerer fejl[]. */
    public static function saveAll(array $in, array $logo = []): array
    {
        $month = (int)($in['start_month'] ?? 0);
        $mode = (string)($in['ring_mode'] ?? 'auto');
        $bg = strtolower(trim((string)($in['topbar_bg'] ?? '')));
        $fg = strtolower(trim((string)($in['topbar_fg'] ?? '')));
        $text = trim((string)($in['topbar_text'] ?? ''));
        $removeLogo = !empty($in['remove_logo']);
        $logoHeight = trim((string)($in['topbar_logo_height'] ?? ''));
        $errors = [];
        if ($month < 1 || $month > 12) {
            $errors[] = 'Vælg en gyldig startmåned.';
        }
        if (!isset(self::RING_MODES[$mode])) {
            $errors[] = 'Vælg en gyldig fordeling af ringe.';
        }
        if (!self::isColor($bg)) {
            $errors[] = 'Vælg en gyldig baggrundsfarve til topbaren.';
        }
        if (!self::isColor($fg)) {
            $errors[] = 'Vælg en gyldig tekstfarve til topbaren.';
        }
        if (mb_strlen($text) > self::TOPBAR_TEXT_MAX) {
            $errors[] = 'Teksten i topbaren må højst være ' . self::TOPBAR_TEXT_MAX . ' tegn.';
        }
        if ($logoHeight !== '' && (!ctype_digit($logoHeight)
            || (int)$logoHeight < self::LOGO_HEIGHT_MIN || (int)$logoHeight > self::LOGO_HEIGHT_MAX)) {
            $errors[] = 'Logoets højde skal være et helt tal mellem ' . self::LOGO_HEIGHT_MIN . ' og ' . self::LOGO_HEIGHT_MAX . ' pixels, eller tom.';
        }

        $newLogo = null;
        $err = $logo['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_NO_FILE) {
            if ($err !== UPLOAD_ERR_OK) {
                $errors[] = "Upload af logoet fejlede (kode $err).";
            } elseif ((int)$logo['size'] > self::LOGO_MAX_BYTES) {
                $errors[] = 'Logoet må højst fylde ' . (self::LOGO_MAX_BYTES / 1024 / 1024) . ' MB.';
            } elseif (!$type = self::logoType((string)$logo['tmp_name'])) {
                $errors[] = 'Logoet skal være en PNG-, JPG-, GIF-, WebP- eller SVG-fil.';
            } else {
                $newLogo = ['tmp' => (string)$logo['tmp_name'], 'type' => $type];
            }
        }
        $hasLogo = $newLogo !== null || (!$removeLogo && self::topbarLogo() !== null);
        if ($text === '' && !$hasLogo) {
            $errors[] = 'Skriv en tekst til topbaren, eller upload et logo.';
        }
        if ($errors) {
            return $errors;
        }

        $old = self::topbarLogo();
        $logoValues = null; // null = uændret
        if ($newLogo) {
            $dir = config('upload_dir');
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                return ['Kunne ikke oprette upload-mappen.'];
            }
            $stored = bin2hex(random_bytes(16));
            if (!move_uploaded_file($newLogo['tmp'], "$dir/$stored")) {
                return ['Kunne ikke gemme logoet.'];
            }
            $logoValues = [$stored, $newLogo['type']];
        } elseif ($removeLogo) {
            $logoValues = ['', ''];
        }

        try {
            $st = db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
            $st->execute(['start_month', (string)$month]);
            $st->execute(['ring_mode', $mode]);
            $st->execute(['ring_names', empty($in['ring_names']) ? '0' : '1']);
            $st->execute(['topbar_bg', $bg]);
            $st->execute(['topbar_fg', $fg]);
            $st->execute(['topbar_text', $text]);
            $st->execute(['topbar_logo_height', $logoHeight === '' ? '' : (string)(int)$logoHeight]);
            if ($logoValues) {
                $st->execute(['topbar_logo', $logoValues[0]]);
                $st->execute(['topbar_logo_type', $logoValues[1]]);
            }
        } catch (PDOException $e) {
            if ($newLogo) {
                @unlink(config('upload_dir') . '/' . $logoValues[0]);
            }
            return ['Kunne ikke gemme: ' . $e->getMessage()];
        }
        if ($logoValues && $old) {
            @unlink($old['file']); // det gamle logo er erstattet eller fjernet
        }
        self::$cache = null;
        return [];
    }
}
