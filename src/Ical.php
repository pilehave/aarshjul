<?php
declare(strict_types=1);

/**
 * Kalenderabonnement (iCal/ICS, RFC 5545) med årshjulets forekomster, så de kan ses i Outlook, Google og Apple Kalender.
 *
 * Kalenderprogrammer kan ikke logge ind, så adressen indeholder en hemmelig nøgle pr. bruger (users.ical_token).
 * Feedet virker kun, så længe brugeren er aktiv, og en ny nøgle gør den gamle adresse ugyldig.
 * Nøglen gemmes i klartekst, fordi adressen skal kunne vises igen på forsiden og under Min konto. Den giver kun
 * læseadgang til begivenheder, som enhver indlogget bruger kan se, og den der kan læse databasen, kan læse dem direkte.
 *
 * Gentagelserne sendes som enkelte forekomster i stedet for RRULE, fordi ikke alle regler (fx week_number)
 * kan udtrykkes ens. Hver forekomst har et fast UID (begivenhed + dato), så kalenderne opdaterer uden dubletter.
 */
final class Ical
{
    /** Perioden i feedet, regnet fra i dag */
    public const FROM = '-1 year';
    public const TO = '+2 years';

    /** Brugerens nøgle, eller null hvis brugeren endnu ikke har en kalenderadresse. */
    public static function token(int $userId): ?string
    {
        $st = db()->prepare('SELECT ical_token FROM users WHERE id = ?');
        $st->execute([$userId]);
        $t = $st->fetchColumn();
        return is_string($t) && $t !== '' ? $t : null;
    }

    /** Giver brugeren en ny nøgle. Den gamle adresse holder op med at virke. */
    public static function renewToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        db()->prepare('UPDATE users SET ical_token = ? WHERE id = ?')->execute([$token, $userId]);
        return $token;
    }

    /** Den aktive bruger med nøglen, eller null. */
    public static function userByToken(string $token): ?array
    {
        if (!self::isToken($token)) {
            return null;
        }
        $st = db()->prepare('SELECT * FROM users WHERE ical_token = ? AND active = 1');
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    public static function isToken(string $token): bool
    {
        return (bool)preg_match('/^[0-9a-f]{64}$/', $token);
    }

    /**
     * Abonnementsadressen, evt. filtreret på person og/eller kategori. Bygget fra app_url, ikke Host-headeren.
     * Outlook og Google navngiver kalenderen efter adressens filnavn, ikke X-WR-CALNAME, så $name sættes ind
     * som filnavn efter ical.php/ (PATH_INFO, som ical.php ignorerer): …/ical.php/Aarshjul-Anne-Holm.ics?token=…
     */
    public static function url(string $token, ?int $personId = null, ?int $categoryId = null, string $name = 'Årshjul'): string
    {
        return config('app_url') . '/ical.php/' . self::filename($name) . '?'
            . http_build_query(array_filter(['token' => $token, 'person' => $personId, 'category' => $categoryId]));
    }

    /** Kalenderens navn: topbarens tekst og evt. filteret, fx "Årshjul – Anne Holm". */
    public static function name(?int $personId = null, ?int $categoryId = null): string
    {
        $name = Settings::topbarText() ?: 'Årshjul';
        $filters = [];
        if ($personId) {
            $filters[] = array_column(Events::people(), 'name', 'id')[$personId] ?? null;
        }
        if ($categoryId) {
            $filters[] = Categories::all()[$categoryId]['name'] ?? null;
        }
        $filters = array_filter($filters);
        return $filters ? $name . ' – ' . implode(', ', $filters) : $name;
    }

    /** Et filnavn til adressen ud fra kalenderens navn: kun a-z, 0-9 og bindestreg, så alle programmer kan læse det. */
    public static function filename(string $name): string
    {
        $s = strtr($name, ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'Æ' => 'Ae', 'Ø' => 'Oe', 'Å' => 'Aa']);
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: '';
        $s = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');
        $s = rtrim(substr($s, 0, 80), '-');
        return ($s !== '' ? $s : 'Aarshjul') . '.ics';
    }

    /** @return array{0:string,1:string} første og sidste dato (Y-m-d) i feedet */
    public static function window(string $today): array
    {
        $d = new DateTimeImmutable($today);
        return [$d->modify(self::FROM)->format('Y-m-d'), $d->modify(self::TO)->format('Y-m-d')];
    }

    /**
     * Hele kalenderen som tekst med CRLF-linjeskift.
     * @param array  $occ     forekomster fra Events::occurrencesBetween()
     * @param string $name    kalenderens navn, som programmet viser
     * @param int    $now     Unix-tid til DTSTAMP
     * @param string $baseUrl app_url, til links tilbage til årshjulet og som domæne i UID
     */
    public static function calendar(array $occ, string $name, int $now, string $baseUrl): string
    {
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: 'aarshjul';
        $stamp = gmdate('Ymd\THis\Z', $now);
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Aarshjul//Aarshjul//DA',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::text($name),
            'X-WR-TIMEZONE:Europe/Copenhagen',
            // Hvor ofte kalenderen bør hentes igen. Outlook og Google bestemmer selv, men nogle respekterer det
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];
        foreach ($occ as $o) {
            array_push($lines, ...self::event($o, $stamp, $host, $baseUrl));
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }

    /** Én forekomst som heldagsbegivenhed. DTEND er dagen efter sidste dag (RFC 5545). */
    private static function event(array $o, string $stamp, string $host, string $baseUrl): array
    {
        $e = $o['event'];
        $end = (new DateTimeImmutable($o['end']))->modify('+1 day');
        $lines = [
            'BEGIN:VEVENT',
            'UID:event-' . (int)$e['id'] . '-' . $o['date'] . '@' . $host,
            'DTSTAMP:' . $stamp,
            'DTSTART;VALUE=DATE:' . str_replace('-', '', $o['date']),
            'DTEND;VALUE=DATE:' . $end->format('Ymd'),
            'SUMMARY:' . self::text(($o['done'] ? '✓ ' : '') . $e['title']),
            'DESCRIPTION:' . self::text(self::description($o, $baseUrl)),
            'CATEGORIES:' . self::text((string)$e['category_name']),
            'URL:' . $baseUrl . '/index.php?year=' . wheel_year($o['date']),
            'TRANSP:TRANSPARENT', // heldagsbegivenheder skal ikke vise en som optaget
        ];
        return [...$lines, 'END:VEVENT'];
    }

    /** Beskrivelsen: kategori, personer, status, tekst, note og links, og til sidst et link til årshjulet. */
    public static function description(array $o, string $baseUrl): string
    {
        $e = $o['event'];
        $out = ['Kategori: ' . $e['category_name']];
        if ($people = array_column($e['people'], 'name')) {
            $out[] = 'Personer: ' . implode(', ', $people);
        }
        if ($o['done']) {
            $out[] = 'Status: ' . ($o['completed'] ? Events::completedText($o['completed']) : 'Opfyldt');
        } elseif ($o['overdue']) {
            $out[] = 'Status: Overskredet';
        } elseif ($o['blocked']) {
            $out[] = 'Status: Venter på forudsætning';
        }
        if (($desc = trim((string)$e['description'])) !== '') {
            $out[] = '';
            $out[] = $desc;
        }
        if (($note = trim((string)($o['note'] ?? ''))) !== '') {
            $out[] = '';
            $out[] = 'Note: ' . $note;
        }
        $links = array_merge($e['links'] ?? [], $o['links'] ?? []);
        if ($links) {
            $out[] = '';
            foreach ($links as $l) {
                $out[] = (trim((string)($l['label'] ?? '')) !== '' ? trim((string)$l['label']) . ': ' : '') . $l['url'];
            }
        }
        $out[] = '';
        $out[] = 'Se i årshjulet: ' . $baseUrl . '/index.php?year=' . wheel_year($o['date']);
        return implode("\n", $out);
    }

    /** Escaper en TEXT-værdi: \ ; , og linjeskift (RFC 5545, 3.3.11). */
    public static function text(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return strtr($s, ['\\' => '\\\\', ';' => '\;', ',' => '\,', "\n" => '\n']);
    }

    /** Ombryder en linje til højst 75 bytes pr. linje, uden at dele et UTF-8-tegn (RFC 5545, 3.1). */
    public static function fold(string $line): string
    {
        $parts = [];
        $max = 75;
        while (strlen($line) > $max) {
            $chunk = mb_strcut($line, 0, $max, 'UTF-8');
            $parts[] = $chunk;
            $line = substr($line, strlen($chunk));
            $max = 74; // fortsættelseslinjer starter med et mellemrum
        }
        $parts[] = $line;
        return implode("\r\n ", $parts);
    }
}
