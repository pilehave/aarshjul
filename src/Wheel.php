<?php
declare(strict_types=1);

/**
 * Tegner årshjulet som en selvstændig SVG (ingen ekstern CSS), så den både kan vises
 * på siden og eksporteres direkte som SVG/PNG/PDF.
 */
final class Wheel
{
    private const SIZE = 1000;
    private const C = 500;
    private const R_MONTH_OUT = 490;
    private const R_MONTH_IN = 440;
    private const R_WEEK_IN = 418;
    private const R_LANES_OUT = 410;
    private const R_LANES_IN = 150;
    private const OVERDUE = '#d64545'; // samme røde som "i dag"-markøren

    /** Korte forekomster tegnes mindst så mange dage brede, så de kan ses og klikkes på */
    private const MIN_SPAN = 3;

    /**
     * Hvor i hjulet [$from, $to] en forekomst fra $date til $end tegnes: [første dag, dag efter sidste], talt i dage fra $from.
     * Forekomsten skæres af ved hjulets kanter. Korte forekomster gøres MIN_SPAN dage brede, dog aldrig ud over kanterne.
     * En forekomst, der er begyndt i hjulet før, tegnes kun med de dage, der ligger i dette hjul (fx 1 dag).
     * @return array{0:int,1:int}
     */
    public static function span(string $date, string $end, string $from, string $to): array
    {
        $start = new DateTimeImmutable($from);
        $day = fn(string $ymd) => (int)$start->diff(new DateTimeImmutable($ymd))->format('%r%a');
        $daysInYear = $day($to) + 1;
        $s = $day(max($date, $from));
        $e = $day(min($end, $to)) + 1;
        if ($date >= $from && $e - $s < self::MIN_SPAN) {
            $e = min($daysInYear, $s + self::MIN_SPAN);
            $s = max(0, $e - self::MIN_SPAN);
        }
        return [$s, $e];
    }

    public static function svg(int $year, array $occurrences, bool $links = true): string
    {
        [$from, $to] = year_bounds($year);
        $start = new DateTimeImmutable($from);
        $day = fn(string $ymd) => (int)$start->diff(new DateTimeImmutable($ymd))->format('%r%a'); // dage siden $from
        $daysInYear = $day($to) + 1;
        $ang = fn(float $dayIndex) => $dayIndex / $daysInYear * 360.0; // 0° = 1. i startmåneden, øverst, med uret
        $label = year_label($year);
        $C = self::C;

        $o = [];
        $o[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%1$d" height="%1$d" font-family="Helvetica, Arial, sans-serif" role="img" aria-label="Årshjul %2$s">', self::SIZE, h($label));
        $o[] = '<defs><pattern id="blocked" patternUnits="userSpaceOnUse" width="8" height="8" patternTransform="rotate(45)"><rect width="8" height="8" fill="white" fill-opacity="0"/><line x1="0" y1="0" x2="0" y2="8" stroke="#ffffff" stroke-width="3" stroke-opacity="0.75"/></pattern></defs>';
        $o[] = sprintf('<rect width="%1$d" height="%1$d" fill="#ffffff"/>', self::SIZE);

        // Månedsring. Går hjulet på tværs af to kalenderår, får hver måned årstallet med, fx "August 27"
        $withYear = Settings::startMonth() !== 1;
        for ($i = 0; $i < 12; $i++) {
            $first = $start->modify("+$i months");
            $m = (int)$first->format('n');
            $a1 = $ang($day($first->format('Y-m-d')));
            $a2 = $ang($day($first->format('Y-m-d')) + (int)$first->format('t'));
            $fill = $i % 2 ? '#dce4f2' : '#e9eef7';
            $o[] = sprintf('<path d="%s" fill="%s" stroke="#ffffff" stroke-width="2"/>', self::arc($a1, $a2, self::R_MONTH_IN, self::R_MONTH_OUT), $fill);
            $o[] = sprintf('<path d="%s" fill="#fafbfd" stroke="#e3e7ee" stroke-width="1"/>', self::arc($a1, $a2, self::R_LANES_IN, self::R_LANES_OUT));
            $o[] = self::label($a1, $a2, (self::R_MONTH_IN + self::R_MONTH_OUT) / 2, ucfirst(MONTHS_DA[$m]) . ($withYear ? ' ' . $first->format('y') : ''), 20, '#1f2d48', 'bold', 'm' . $m);
        }

        // Ugenumre: en streg ved hver mandag og nummeret midt i den del af ugen, der ligger i hjulet.
        // Starter hjulet midt i en uge, får den delvise første uge også sit nummer (fx uge 31, når 1/8 er en lørdag)
        $weeks = []; // [nummer, første dag, dag efter sidste] (dage siden $from, afskåret til hjulet)
        for ($d = $start->modify('-' . ((int)$start->format('N') - 1) . ' days'); $d->format('Y-m-d') <= $to; $d = $d->modify('+7 days')) {
            $monday = $day($d->format('Y-m-d'));
            if ($monday >= 0) {
                [$x1, $y1] = self::pt($ang($monday), self::R_WEEK_IN);
                [$x2, $y2] = self::pt($ang($monday), self::R_MONTH_IN);
                $o[] = sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#c4ccda" stroke-width="1"/>', $x1, $y1, $x2, $y2);
            }
            $weeks[] = [(int)$d->format('W'), max(0, $monday), min($daysInYear, $monday + 7)];
        }
        // Har ugerne i hver ende samme nummer (fx uge 31 i både 2028 og 2029, når hjulet starter 1/8 2028),
        // mødes de øverst og ligner én uge. Nummeret vises så kun én gang: midt over sammenstødet, hvis begge
        // er delvise, ellers ved den hele uge (fx uge 1 i 2024, hvor 30.-31/12 også er uge 1)
        [$first, $last] = [$weeks[0], $weeks[count($weeks) - 1]];
        $len = fn(array $w) => $w[2] - $w[1];
        if (count($weeks) > 1 && $first[0] === $last[0]) {
            if ($len($first) < 7 && $len($last) < 7) {
                array_pop($weeks);
                $weeks[0][1] = $last[1] - $daysInYear; // negativ: dagene før toppen
            } elseif ($len($first) < $len($last)) {
                array_shift($weeks);
            } else {
                array_pop($weeks);
            }
        }
        foreach ($weeks as [$num, $s, $e]) {
            if ($e - $s < 2) {
                continue; // en enkelt dag er for smal til et nummer
            }
            [$tx, $ty] = self::pt($ang(($s + $e) / 2), (self::R_WEEK_IN + self::R_MONTH_IN) / 2);
            $o[] = sprintf('<text x="%.1f" y="%.1f" font-size="9" fill="#7a8599" text-anchor="middle" dominant-baseline="central">%d</text>', $tx, $ty, $num);
        }

        // Fordel begivenhederne i ringe: serier, der aldrig overlapper hinanden, deler ring
        $series = [];
        foreach ($occurrences as $k => $occ) {
            [$s, $e] = self::span($occ['date'], $occ['end'], $from, $to);
            $series[$occ['event']['id']][] = [$occ, $s, $e, $k];
        }
        $lanes = [];   // lane => liste af [start, slut]
        $placed = [];
        $ownRing = false; // sæt til true for at give hver serie sin egen ring
        foreach (array_values($series) as $idx => $items) {
            for ($lane = $ownRing ? $idx : 0; ; $lane++) {
                $free = true;
                foreach ($items as [, $s, $e]) {
                    foreach ($lanes[$lane] ?? [] as [$ls, $le]) {
                        if ($s < $le + 1 && $ls < $e + 1) {
                            $free = false;
                            break 2;
                        }
                    }
                }
                if ($free) {
                    break;
                }
            }
            foreach ($items as [$occ, $s, $e, $k]) {
                $lanes[$lane][] = [$s, $e];
                $placed[] = [$occ, $s, $e, $lane, $k];
            }
        }
        $laneW = min(44, (self::R_LANES_OUT - self::R_LANES_IN) / max(1, count($lanes)));

        foreach ($placed as $i => [$occ, $s, $e, $lane, $k]) {
            $ev = $occ['event'];
            $rOut = self::R_LANES_OUT - $lane * $laneW - 2;
            $rIn = $rOut - $laneW + 4;
            $a1 = $ang($s);
            $a2 = $ang($e);
            $tip = $ev['title'] . ' · ' . $ev['category_name'] . ' · ' . date_da($occ['date'], true) . ($occ['end'] !== $occ['date'] ? ' – ' . date_da($occ['end'], true) : '')
                . ($ev['people'] ? ' · ' . implode(', ', array_column($ev['people'], 'name')) : '')
                . ($occ['done'] ? ' · ✓ Opfyldt' : '') . ($occ['overdue'] ? ' · Overskredet' : '') . ($occ['blocked'] ? ' · Venter på forudsætning' : '')
                . ($occ['note'] !== null ? ' · Note: ' . mb_strimwidth(preg_replace('/\s+/u', ' ', $occ['note']), 0, 80, '…') : '');
            $opacity = $occ['done'] ? '0.45' : '1';
            $g = sprintf('<g opacity="%s"><title>%s</title>', $opacity, h($tip));
            $g .= sprintf('<path d="%s" fill="%s" stroke="#ffffff" stroke-width="1"/>', self::arc($a1, $a2, $rIn, $rOut), h($ev['color']));
            if ($occ['blocked']) {
                $g .= sprintf('<path d="%s" fill="url(#blocked)"/>', self::arc($a1, $a2, $rIn, $rOut));
            }
            if ($occ['overdue']) {
                // Rød kant lidt inden for buen, så den ikke flyder ind i naboerne. Attributter i stedet for CSS, så den også kommer med i PNG/PDF
                $g .= sprintf('<path d="%s" fill="none" stroke="%s" stroke-width="3" stroke-linejoin="round"/>', self::arc($a1, $a2, $rIn + 1.5, $rOut - 1.5), self::OVERDUE);
            }
            // Lille mærke i buens hjørne, når forekomsten har en note, et link eller en fil. Teksten holdes fri af mærket
            $mark = '';
            $labelEnd = $a2;
            if ($occ['note'] !== null || $occ['links'] || $occ['files']) {
                $r = max(2.5, min(5, $laneW * 0.16));
                $da = rad2deg(($r + 4) / $rOut);
                [$cx, $cy] = self::pt($a2 - $da, $rOut - $r - 4);
                $mark = sprintf('<circle cx="%.1f" cy="%.1f" r="%.1f" fill="#ffffff" stroke="#1f2d48" stroke-width="1.5"/>', $cx, $cy, $r);
                $labelEnd = max($a1, $a2 - 2 * $da);
            }
            $fontSize = min(13, max(8, $laneW * 0.4));
            $g .= self::label($a1, $labelEnd, ($rIn + $rOut) / 2, ($occ['done'] ? '✓ ' : '') . $ev['title'], $fontSize, '#ffffff', 'normal', 'e' . $i);
            $g .= $mark;
            $g .= '</g>';
            if ($links) {
                // data-occ er forekomstens nøgle i $occurrences; forsiden åbner en modal med detaljerne ud fra den
                // Uden JavaScript (eller ved ctrl-klik) går linket til redigering, eller til modalen for ikke-administratorer
                $href = Auth::can('admin') ? sprintf('event.php?id=%d&amp;year=%d', $ev['id'], $year) : sprintf('#forekomst=%d_%s', $ev['id'], $occ['date']);
                $g = sprintf('<a href="%s" data-occ="%s">%s</a>', $href, h((string)$k), $g);
            }
            $o[] = $g;
        }

        // I dag
        $today = date('Y-m-d');
        if ($today >= $from && $today <= $to) {
            $a = $ang($day($today) + 0.5);
            [$x1, $y1] = self::pt($a, self::R_LANES_IN - 6);
            [$x2, $y2] = self::pt($a, self::R_MONTH_OUT);
            $o[] = sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#d64545" stroke-width="2.5" stroke-linecap="round"/>', $x1, $y1, $x2, $y2);
        }

        // Midte
        $o[] = sprintf('<circle cx="%1$d" cy="%1$d" r="%2$d" fill="#1f2d48"/>', $C, self::R_LANES_IN - 10);
        $o[] = sprintf('<text x="%1$d" y="%2$d" font-size="%3$d" font-weight="bold" fill="#ffffff" text-anchor="middle" dominant-baseline="central">%4$s</text>', $C, $C - 8, strlen($label) > 4 ? 52 : 64, h($label));
        $o[] = sprintf('<text x="%1$d" y="%2$d" font-size="18" fill="#b9c4da" text-anchor="middle">Årshjul</text>', $C, $C + 50);
        $o[] = '</svg>';
        return implode("\n", $o);
    }

    private static function pt(float $deg, float $r): array
    {
        $rad = deg2rad($deg - 90);
        return [self::C + $r * cos($rad), self::C + $r * sin($rad)];
    }

    private static function arc(float $a1, float $a2, float $rIn, float $rOut): string
    {
        $a2 = min($a2, $a1 + 359.99);
        $large = ($a2 - $a1) > 180 ? 1 : 0;
        [$x1, $y1] = self::pt($a1, $rOut);
        [$x2, $y2] = self::pt($a2, $rOut);
        [$x3, $y3] = self::pt($a2, $rIn);
        [$x4, $y4] = self::pt($a1, $rIn);
        return sprintf('M%.2f %.2f A%.2f %.2f 0 %d 1 %.2f %.2f L%.2f %.2f A%.2f %.2f 0 %d 0 %.2f %.2f Z',
            $x1, $y1, $rOut, $rOut, $large, $x2, $y2, $x3, $y3, $rIn, $rIn, $large, $x4, $y4);
    }

    /** Tekst langs en bue. Teksten forkortes, så den passer; i nederste halvdel vendes den, så den kan læses. */
    private static function label(float $a1, float $a2, float $r, string $text, float $size, string $fill, string $weight, string $id): string
    {
        $len = deg2rad($a2 - $a1) * $r - 6;
        $maxChars = (int)floor($len / ($size * 0.56));
        if ($maxChars < 5 && mb_strlen($text) > $maxChars) {
            return '';
        }
        if (mb_strlen($text) > $maxChars) {
            $text = rtrim(mb_substr($text, 0, $maxChars - 1)) . '…';
        }
        $mid = fmod(($a1 + $a2) / 2, 360);
        $flip = $mid > 90 && $mid < 270;
        $rr = $flip ? $r + $size * 0.35 : $r - $size * 0.35;
        [$x1, $y1] = self::pt($flip ? $a2 : $a1, $rr);
        [$x2, $y2] = self::pt($flip ? $a1 : $a2, $rr);
        $large = ($a2 - $a1) > 180 ? 1 : 0;
        $path = sprintf('M%.2f %.2f A%.2f %.2f 0 %d %d %.2f %.2f', $x1, $y1, $rr, $rr, $large, $flip ? 0 : 1, $x2, $y2);
        return sprintf('<path id="p-%1$s" d="%2$s" fill="none"/><text font-size="%3$.1f" font-weight="%4$s" fill="%5$s"><textPath href="#p-%1$s" startOffset="50%%" text-anchor="middle">%6$s</textPath></text>',
            $id, $path, $size, $weight, $fill, h($text));
    }
}
