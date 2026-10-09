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
    private const PAD = 32; // margen uden om hjulet til skyggen (viewBox går fra -PAD til SIZE + PAD)
    private const TODAY = '#d64545'; // farven for "i dag" og den aktuelle uge
    private const R_LANES_OUT = 410;
    private const R_LANES_IN = 120;
    private const OVERDUE = '#d64545'; // samme røde som "i dag"-markøren

    /** Korte forekomster tegnes mindst så mange dage brede, så de kan ses og klikkes på */
    private const MIN_SPAN = 3;
    /** Ugedagenes forbogstav i dagringen, når der er zoomet ind på en måned (1 = mandag) */
    private const WEEKDAY_LETTERS = [1 => 'M', 'T', 'O', 'T', 'F', 'L', 'S'];
    /** Højden på navnebåndet yderst i en fast ring (én ring pr. kategori/person) */
    private const RING_NAME_BAND = 12;
    /** Bredden på skyggen ved sammenstødet øverst, hvor årets sidste måned møder den første */
    private const SEAM_WIDTH = 18;

    /**
     * Fordeler forekomsterne i ringe og baner.
     *  auto      Én ring uden navn. Serier, der aldrig overlapper hinanden, deler bane (som før).
     *  category  Én ring pr. kategori med navn og evt. baggrundsfarve (category_ring_color).
     *  person    Én ring pr. person. En forekomst med flere personer kommer i hver af deres ringe,
     *            og forekomster uden personer samles i ringen "Ingen person" til sidst.
     * Ringene sorteres efter navn (som på Kategorier- og Personer-siden), den første yderst.
     * Kategorier og personer uden forekomster får ingen ring. Inden for en ring lægges hver serie i den første bane,
     * hvor den ikke overlapper andre.
     * @return list<array{name:?string,bg:?string,lanes:list<list<array{0:int|string,1:int,2:int}>>}> lanes: [nøgle i $occurrences, start, slut]
     */
    public static function rings(array $occurrences, string $mode, string $from, string $to): array
    {
        $groups = []; // ringnøgle => [name, bg, serier]
        foreach ($occurrences as $k => $occ) {
            $ev = $occ['event'];
            $keys = match ($mode) {
                'category' => [['c' . $ev['category_id'], $ev['category_name'], $ev['category_ring_color'] ?? null]],
                'person'   => $ev['people'] ? array_map(fn($p) => ['p' . $p['id'], $p['name'], null], $ev['people']) : [['none', 'Ingen person', null]],
                default    => [['all', null, null]],
            };
            [$s, $e] = self::span($occ['date'], $occ['end'], $from, $to);
            foreach ($keys as [$key, $name, $bg]) {
                $groups[$key] ??= ['name' => $name, 'bg' => $bg, 'series' => []];
                $groups[$key]['series'][$ev['id']][] = [$k, $s, $e];
            }
        }
        $collator = class_exists('Collator') ? new Collator('da_DK') : null;
        uksort($groups, function ($a, $b) use ($groups, $collator) {
            if (($a === 'none') !== ($b === 'none')) {
                return $a === 'none' ? 1 : -1; // "Ingen person" til sidst
            }
            [$na, $nb] = [(string)$groups[$a]['name'], (string)$groups[$b]['name']];
            return $collator ? $collator->compare($na, $nb) : strcmp(mb_strtolower($na), mb_strtolower($nb));
        });

        $rings = [];
        foreach ($groups as $g) {
            $lanes = []; // bane => liste af [nøgle, start, slut]
            foreach ($g['series'] as $items) {
                for ($lane = 0; ; $lane++) {
                    foreach ($items as [, $s, $e]) {
                        foreach ($lanes[$lane] ?? [] as [, $ls, $le]) {
                            if ($s < $le + 1 && $ls < $e + 1) {
                                continue 3; // overlapper: prøv næste bane
                            }
                        }
                    }
                    break;
                }
                foreach ($items as $item) {
                    $lanes[$lane][] = $item;
                }
            }
            $rings[] = ['name' => $g['name'], 'bg' => $g['bg'], 'lanes' => $lanes];
        }
        return $rings;
    }

    /**
     * Stribe på SEAM_WIDTH px lige til højre for sammenstødet øverst, fra midtercirklen (R_LANES_IN) til yderkanten (R_MONTH_OUT).
     * Siderne følger de to cirkler, så striben slutter præcis ved hjulets kanter.
     */
    private static function seamPath(): string
    {
        $c = self::C;
        $w = self::SEAM_WIDTH;
        $yOut = $c - sqrt(self::R_MONTH_OUT ** 2 - $w ** 2);
        $yIn = $c - sqrt(self::R_LANES_IN ** 2 - $w ** 2);
        return sprintf('M%1$d %2$.2f A%3$d %3$d 0 0 1 %4$d %5$.2f L%4$d %6$.2f A%7$d %7$d 0 0 0 %1$d %8$.2f Z',
            $c, $c - self::R_MONTH_OUT, self::R_MONTH_OUT, $c + $w, $yOut, $yIn, self::R_LANES_IN, $c - self::R_LANES_IN);
    }

    /** Fuld ring mellem $rIn og $rOut som sti (to cirkler, fill-rule="evenodd") */
    private static function annulus(float $rIn, float $rOut): string
    {
        $circle = fn(float $r) => sprintf('M%1$.2f %2$.2f A%3$.2f %3$.2f 0 1 1 %1$.2f %4$.2f A%3$.2f %3$.2f 0 1 1 %1$.2f %2$.2f Z',
            self::C, self::C - $r, $r, self::C + $r);
        return $circle($rOut) . ' ' . $circle($rIn);
    }

    /**
     * Hvor i hjulet [$from, $to] en forekomst fra $date til $end tegnes: [første dag, dag efter sidste], talt i dage fra $from.
     * Forekomsten skæres af ved hjulets kanter. Korte forekomster gøres MIN_SPAN dage brede i et helt år (i et kvartal eller
     * en måned tilsvarende færre, mindst 1 dag), dog aldrig ud over kanterne.
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
        $min = max(1, (int)round(self::MIN_SPAN * $daysInYear / 365));
        if ($date >= $from && $e - $s < $min) {
            $e = min($daysInYear, $s + $min);
            $s = max(0, $e - $min);
        }
        return [$s, $e];
    }

    /**
     * Tegner hjulet for $period (se Period): hele årshjulet for $year, eller et kvartal/en måned, der fylder hele cirklen.
     * Ved et år og et kvartal viser de ydre ringe måneder og ugenumre, ved en måned uger og dage.
     */
    public static function svg(int $year, array $occurrences, bool $links = true, ?array $period = null): string
    {
        $period ??= Period::fromRequest($year, null);
        [$from, $to] = [$period['from'], $period['to']];
        $start = new DateTimeImmutable($from);
        $day = fn(string $ymd) => (int)$start->diff(new DateTimeImmutable($ymd))->format('%r%a'); // dage siden $from
        $daysInYear = $day($to) + 1; // dage i perioden (et helt år, et kvartal eller en måned)
        $ang = fn(float $dayIndex) => $dayIndex / $daysInYear * 360.0; // 0° = periodens første dag, øverst, med uret
        $label = Period::label($period);
        $C = self::C;
        $today = date('Y-m-d');
        $todayInWheel = $today >= $from && $today <= $to;

        $o = [];
        $full = self::SIZE + 2 * self::PAD;
        $o[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="%1$d %1$d %2$d %2$d" width="%2$d" height="%2$d" font-family="Helvetica, Arial, sans-serif" role="img" aria-label="Årshjul %3$s">', -self::PAD, $full, h($label));
        $o[] = '<defs><pattern id="blocked" patternUnits="userSpaceOnUse" width="8" height="8" patternTransform="rotate(45)"><rect width="8" height="8" fill="white" fill-opacity="0"/><line x1="0" y1="0" x2="0" y2="8" stroke="#ffffff" stroke-width="3" stroke-opacity="0.75"/></pattern>'
            // Grå skygge under hjulets yderkant, så hjulet ser ud til at svæve over baggrunden
            . '<filter id="wheel-shadow" x="-10%" y="-10%" width="120%" height="120%"><feDropShadow dx="0" dy="6" stdDeviation="10" flood-color="#000000" flood-opacity="0.3"/></filter>'
            // Skyggen ved sammenstødet øverst: mørkest ved linjen og udtonet mod højre
            . sprintf('<linearGradient id="seam-shadow" gradientUnits="userSpaceOnUse" x1="%1$d" y1="0" x2="%2$d" y2="0">'
                . '<stop offset="0" stop-color="#1f2d48" stop-opacity="0.22"/><stop offset="1" stop-color="#1f2d48" stop-opacity="0"/></linearGradient>',
                self::C, self::C + self::SEAM_WIDTH)
            . '</defs>';
        $o[] = sprintf('<rect x="%1$d" y="%1$d" width="%2$d" height="%2$d" fill="#ffffff"/>', -self::PAD, $full);
        $o[] = sprintf('<circle class="wheel-shadow" cx="%1$d" cy="%1$d" r="%2$d" fill="#ffffff" filter="url(#wheel-shadow)"/>', $C, self::R_MONTH_OUT);

        // Yderste ring: måneder (år og kvartal) eller uger (måned). Hvert afsnit får også sin sektor i begivenhedsområdet.
        // Går årshjulet på tværs af to kalenderår, får hver måned årstallet med, fx "August 2027"
        $isMonth = $period['kind'] === 'month';
        $withYear = Settings::startMonth() !== 1;
        $segments = []; // [første dag, dag efter sidste, tekst, id]
        if ($isMonth) {
            for ($d = $start->modify('-' . ((int)$start->format('N') - 1) . ' days'); $d->format('Y-m-d') <= $to; $d = $d->modify('+7 days')) {
                $segments[] = [max(0, $day($d->format('Y-m-d'))), min($daysInYear, $day($d->format('Y-m-d')) + 7), 'Uge ' . (int)$d->format('W'), 'w' . $d->format('W')];
            }
        } else {
            for ($first = $start->modify('first day of this month'); $first->format('Y-m-d') <= $to; $first = $first->modify('+1 month')) {
                $m = (int)$first->format('n');
                $segments[] = [max(0, $day($first->format('Y-m-d'))), min($daysInYear, $day($first->format('Y-m-d')) + (int)$first->format('t')),
                    ucfirst(MONTHS_DA[$m]) . ($withYear ? ' ' . $first->format('Y') : ''), 'm' . $m];
            }
        }
        foreach ($segments as $i => [$s, $e, $text, $id]) {
            [$a1, $a2] = [$ang($s), $ang($e)];
            $fill = $i % 2 ? '#dce4f2' : '#e9eef7';
            $o[] = sprintf('<path d="%s" fill="%s" stroke="#ffffff" stroke-width="2"/>', self::arc($a1, $a2, self::R_MONTH_IN, self::R_MONTH_OUT), $fill);
            $o[] = sprintf('<path d="%s" fill="#fafbfd" stroke="#e3e7ee" stroke-width="1"/>', self::arc($a1, $a2, self::R_LANES_IN, self::R_LANES_OUT));
            $o[] = self::label($a1, $a2, (self::R_MONTH_IN + self::R_MONTH_OUT) / 2, $text, 20, '#1f2d48', 'bold', $id);
        }

        if ($isMonth) {
            // Dagring ved en måned: dato og ugedagens forbogstav ("8 T"), weekender let grå, også i begivenhedsområdet
            foreach (range(0, $daysInYear - 1) as $i) {
                $d = $start->modify("+$i days");
                [$a1, $a2] = [$ang($i), $ang($i + 1)];
                if ((int)$d->format('N') >= 6) {
                    $o[] = sprintf('<path class="weekend" d="%s" fill="#eef1f6"/>', self::arc($a1, $a2, self::R_WEEK_IN, self::R_MONTH_IN));
                    $o[] = sprintf('<path class="weekend" d="%s" fill="#f1f3f7"/>', self::arc($a1, $a2, self::R_LANES_IN, self::R_LANES_OUT));
                }
                if ($i > 0) {
                    [$x1, $y1] = self::pt($a1, self::R_WEEK_IN);
                    [$x2, $y2] = self::pt($a1, self::R_MONTH_IN);
                    $o[] = sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#a3aec2" stroke-width="1"/>', $x1, $y1, $x2, $y2);
                }
                $a = ($a1 + $a2) / 2;
                $rot = self::textRotation($a);
                [$tx, $ty] = self::pt($a, (self::R_WEEK_IN + self::R_MONTH_IN) / 2);
                $style = $d->format('Y-m-d') === $today ? 'fill="' . self::TODAY . '" font-weight="bold"' : 'fill="#46536b" font-weight="600"';
                $o[] = sprintf('<text class="day" x="%.1f" y="%.1f" font-size="11" %s text-anchor="middle" dominant-baseline="central" transform="rotate(%.1f %.1f %.1f)">%d %s</text>',
                    $tx, $ty, $style, $rot, $tx, $ty, (int)$d->format('j'), self::WEEKDAY_LETTERS[(int)$d->format('N')]);
            }
        }

        // Ugenumre (år og kvartal): en streg ved hver mandag og nummeret midt i den del af ugen, der ligger i hjulet.
        // Starter hjulet midt i en uge, får den delvise første uge også sit nummer (fx uge 31, når 1/8 er en lørdag)
        $weeks = []; // [nummer, første dag, dag efter sidste] (dage siden $from, afskåret til hjulet)
        for ($d = $start->modify('-' . ((int)$start->format('N') - 1) . ' days'); !$isMonth && $d->format('Y-m-d') <= $to; $d = $d->modify('+7 days')) {
            $monday = $day($d->format('Y-m-d'));
            if ($monday >= 0) {
                [$x1, $y1] = self::pt($ang($monday), self::R_WEEK_IN);
                [$x2, $y2] = self::pt($ang($monday), self::R_MONTH_IN);
                $o[] = sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#a3aec2" stroke-width="1.2"/>', $x1, $y1, $x2, $y2);
            }
            $weeks[] = [(int)$d->format('W'), max(0, $monday), min($daysInYear, $monday + 7)];
        }
        // Har ugerne i hver ende samme nummer (fx uge 31 i både 2028 og 2029, når hjulet starter 1/8 2028),
        // mødes de øverst og ligner én uge. Nummeret vises så kun én gang: midt over sammenstødet, hvis begge
        // er delvise, ellers ved den hele uge (fx uge 1 i 2024, hvor 30.-31/12 også er uge 1)
        [$first, $last] = [$weeks[0] ?? null, $weeks[count($weeks) - 1] ?? null];
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
        // Den aktuelle uge vises med fed rød skrift (se også "I dag" nedenfor)
        $thisWeek = $todayInWheel ? (int)date('W') : null;
        foreach ($weeks as [$num, $s, $e]) {
            if ($e - $s < 2) {
                continue; // en enkelt dag er for smal til et nummer
            }
            // Nummeret følger hjulets runding som månedsnavnene og vendes på den nederste halvdel, så det ikke står på hovedet
            $a = $ang(($s + $e) / 2);
            $rot = self::textRotation($a);
            [$tx, $ty] = self::pt($a, (self::R_WEEK_IN + self::R_MONTH_IN) / 2);
            $style = $num === $thisWeek ? 'fill="' . self::TODAY . '" font-weight="bold"' : 'fill="#46536b" font-weight="600"';
            $o[] = sprintf('<text x="%.1f" y="%.1f" font-size="11" %s text-anchor="middle" dominant-baseline="central" transform="rotate(%.1f %.1f %.1f)">%d</text>',
                $tx, $ty, $style, $rot, $tx, $ty, $num);
        }

        // Ringe: automatisk efter plads, eller én ring pr. kategori/person (Settings::ringMode). Ringene tegnes udefra og ind.
        // Faste ringe får et tyndt navnebånd yderst med navnet fire gange, midt i hvert kvartal. Alle baner er lige brede,
        // så en ring med overlappende begivenheder (flere baner) bliver bredere end de andre
        $mode = Settings::ringMode();
        $rings = self::rings($occurrences, $mode, $from, $to);
        $band = $mode === 'person' || ($mode === 'category' && Settings::ringNames()) ? self::RING_NAME_BAND : 0;
        $laneCount = array_sum(array_map(fn($r) => count($r['lanes']), $rings));
        // Faste ringe fylder hele pladsen ind til midtercirklen. Automatisk har en grænse på 44 px, så få baner ikke bliver meget tykke
        $laneW = (self::R_LANES_OUT - self::R_LANES_IN - count($rings) * $band) / max(1, $laneCount);
        if ($mode === 'auto') {
            $laneW = min(44, $laneW);
        }
        $placed = [];
        $rOuter = self::R_LANES_OUT;
        foreach ($rings as $ri => $ring) {
            $rBandIn = $rOuter - $band;
            $rInner = $rBandIn - count($ring['lanes']) * $laneW;
            if ($ring['bg'] !== null) {
                $o[] = sprintf('<path class="ring-bg" d="%s" fill="%s" fill-rule="evenodd"/>', self::annulus($rInner, $rOuter), h($ring['bg']));
            }
            if ($band) {
                // Navnebåndet er lidt mørkere end ringens baggrund
                $o[] = sprintf('<path class="ring-band" d="%s" fill="%s" fill-rule="evenodd"/>', self::annulus($rBandIn, $rOuter), $ring['bg'] !== null ? h($ring['bg']) : '#eef1f6');
                if ($ring['bg'] !== null) {
                    $o[] = sprintf('<path d="%s" fill="#000000" fill-opacity="0.08" fill-rule="evenodd"/>', self::annulus($rBandIn, $rOuter));
                }
                for ($q = 0; $q < 4; $q++) {
                    $o[] = self::label($q * 90 + 5, $q * 90 + 85, ($rBandIn + $rOuter) / 2, $ring['name'], 9, '#46536b', 'bold', "r{$ri}q{$q}");
                }
            }
            if ($mode !== 'auto') {
                $o[] = sprintf('<circle cx="%1$d" cy="%1$d" r="%2$.1f" fill="none" stroke="#dfe4ec" stroke-width="1"/>', $C, $rInner);
            }
            foreach ($ring['lanes'] as $li => $items) {
                foreach ($items as [$k, $s, $e]) {
                    $placed[] = [$occurrences[$k], $s, $e, $rBandIn - $li * $laneW, $k];
                }
            }
            $rOuter = $rInner;
        }
        if ($mode !== 'auto') {
            // Måneds- (eller uge-)stregerne igen, så de også ses hen over ringenes baggrund
            foreach ($segments as [$s]) {
                $a = $ang($s);
                [$x1, $y1] = self::pt($a, self::R_LANES_IN);
                [$x2, $y2] = self::pt($a, self::R_LANES_OUT);
                $o[] = sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#ffffff" stroke-opacity="0.7" stroke-width="1.5"/>', $x1, $y1, $x2, $y2);
            }
        }

        foreach ($placed as $i => [$occ, $s, $e, $laneOut, $k]) {
            $ev = $occ['event'];
            $rOut = $laneOut - 2;
            $rIn = $rOut - $laneW + 4;
            $a1 = $ang($s);
            $a2 = $ang($e);
            $tip = $ev['title'] . ' · ' . $ev['category_name'] . ' · ' . period_da($occ['date'], $occ['end'])
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

        // Skygge ved sammenstødet øverst, hvor årets sidste måned møder den første: en blød stribe til højre for linjen,
        // som om slutningen af året ligger oven på starten. Den ligger over begivenhederne, men tager ikke klik
        $o[] = sprintf('<g class="seam" pointer-events="none"><path d="%1$s" fill="url(#seam-shadow)" fill-rule="evenodd"/>'
            . '<line x1="%2$d" y1="%3$d" x2="%2$d" y2="%4$d" stroke="#1f2d48" stroke-opacity="0.35" stroke-width="1"/></g>',
            self::seamPath(), $C, $C - self::R_MONTH_OUT, $C - self::R_LANES_IN);

        // I dag: en streg hen over begivenhederne, der stopper ved ugeringen, så den ikke dækker uge- og månedsnavne,
        // og en lille trekant i yderkanten, der peger ind mod dagen
        if ($todayInWheel) {
            $a = $ang($day($today) + 0.5);
            [$x1, $y1] = self::pt($a, self::R_LANES_IN - 6);
            [$x2, $y2] = self::pt($a, self::R_WEEK_IN);
            $o[] = sprintf('<line class="today" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="2.5" stroke-linecap="round"/>', $x1, $y1, $x2, $y2, self::TODAY);
            $half = rad2deg(7 / self::R_MONTH_OUT); // trekantens halve bredde (7 px) som vinkel
            [$tx, $ty] = self::pt($a, self::R_MONTH_OUT - 9);
            [$bx1, $by1] = self::pt($a - $half, self::R_MONTH_OUT + 4);
            [$bx2, $by2] = self::pt($a + $half, self::R_MONTH_OUT + 4);
            $o[] = sprintf('<path class="today" d="M%.1f %.1f L%.1f %.1f L%.1f %.1f Z" fill="%s"/>', $tx, $ty, $bx1, $by1, $bx2, $by2, self::TODAY);
        }

        // Midte
        $o[] = sprintf('<circle cx="%1$d" cy="%1$d" r="%2$d" fill="#1f2d48"/>', $C, self::R_LANES_IN - 10);
        // Ved et helt år årstallet og "Årshjul", ved et kvartal/en måned perioden ("K2", "Oktober") over året
        $title = Period::title($period);
        $sub = Period::subtitle($period) ?? 'Årshjul';
        $len = mb_strlen($title);
        $size = $len <= 4 ? 54 : min(44, (int)floor(200 / ($len * 0.62)));
        $o[] = sprintf('<text class="center-title" x="%1$d" y="%2$d" font-size="%3$d" font-weight="bold" fill="#ffffff" text-anchor="middle" dominant-baseline="central">%4$s</text>', $C, $C - 8, $size, h($title));
        $o[] = sprintf('<text class="center-sub" x="%1$d" y="%2$d" font-size="%3$d" fill="#b9c4da" text-anchor="middle">%4$s</text>', $C, $C + 42, $period['kind'] === 'year' ? 16 : 20, h($sub));
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
    /**
     * Rotation (grader med uret) for tekst, der skal stå langs hjulet ved vinklen $deg (0° = øverst).
     * På den nederste halvdel vendes teksten, så den kan læses, ligesom i label().
     */
    public static function textRotation(float $deg): float
    {
        $deg = fmod(fmod($deg, 360) + 360, 360);
        return $deg > 90 && $deg < 270 ? $deg - 180 : ($deg >= 270 ? $deg - 360 : $deg);
    }

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
