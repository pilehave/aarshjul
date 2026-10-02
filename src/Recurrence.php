<?php
declare(strict_types=1);

/**
 * Udregner forekomster af en begivenhed ud fra dens gentagelsesregel.
 *
 * Regler (kolonnen events.recurrence):
 *  none        Én gang, på start_date.
 *  weekly      Hver rec_interval. uge fra start_date.
 *  monthly     Hver rec_interval. måned på samme dag som start_date (31. → sidste dag i korte måneder).
 *  yearly      Hvert rec_interval. år på samme dato som start_date (29/2 → 28/2 i ikke-skudår).
 *  nth_weekday Den rule_nth. (1-4, -1 = sidste) rule_weekday i måneden rule_month (NULL = hver måned).
 *  last_week   Sidste uge i måneden rule_month (NULL = hver måned): ugen der starter med månedens sidste mandag.
 *  week_number ISO-uge rule_nth på ugedagen rule_weekday, hvert år.
 *
 * start_date er altid den tidligste mulige dato, og end_date (hvis sat) den seneste.
 */
final class Recurrence
{
    public const RULES = [
        'none'        => 'Ingen gentagelse (én gang)',
        'weekly'      => 'Ugentlig',
        'monthly'     => 'Månedlig (samme dato)',
        'yearly'      => 'Årlig (samme dato)',
        'nth_weekday' => 'Bestemt ugedag i måneden (fx 2. tirsdag, sidste fredag)',
        'last_week'   => 'Sidste uge i måneden',
        'week_number' => 'Bestemt ugenummer (fx uge 8)',
    ];

    /** @return string[] startdatoer (Y-m-d) for forekomster, der overlapper perioden [$from, $to] */
    public static function occurrences(array $e, string $from, string $to): array
    {
        $duration = max(1, (int)$e['duration_days']);
        $start    = new DateTimeImmutable($e['start_date']);
        $rangeFrom = (new DateTimeImmutable($from))->modify('-' . ($duration - 1) . ' days');
        $rangeTo   = new DateTimeImmutable($to);
        $last = $rangeTo;
        if (!empty($e['end_date'])) {
            $end = new DateTimeImmutable($e['end_date']);
            if ($end < $last) {
                $last = $end;
            }
        }
        $lower = $start > $rangeFrom ? $start : $rangeFrom;
        if ($lower > $last) {
            return [];
        }
        $interval = max(1, (int)($e['rec_interval'] ?? 1));
        $out = [];

        switch ($e['recurrence']) {
            case 'none':
                $out[] = $start;
                break;

            case 'weekly':
                $step = 7 * $interval;
                $diff = (int)$start->diff($lower)->format('%a');
                $k = intdiv($diff + $step - 1, $step);
                for ($d = $start->modify('+' . ($k * $step) . ' days'); $d <= $last; $d = $d->modify("+$step days")) {
                    $out[] = $d;
                }
                break;

            case 'monthly':
                $day = (int)$start->format('j');
                $base = $start->modify('first day of this month');
                for ($i = 0; ; $i += $interval) {
                    $m = $base->modify("+$i months");
                    if ($m > $last) {
                        break;
                    }
                    $out[] = $m->setDate((int)$m->format('Y'), (int)$m->format('n'), min($day, (int)$m->format('t')));
                }
                break;

            case 'yearly':
                $mon = (int)$start->format('n');
                $day = (int)$start->format('j');
                $y0 = (int)$start->format('Y');
                for ($y = $y0 + intdiv(max(0, (int)$lower->format('Y') - 1 - $y0) , $interval) * $interval; $y <= (int)$last->format('Y'); $y += $interval) {
                    $dim = cal_days_in_month_safe($y, $mon);
                    $out[] = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mon, min($day, $dim)));
                }
                break;

            case 'nth_weekday':
            case 'last_week':
                $nth = $e['recurrence'] === 'last_week' ? -1 : (int)$e['rule_nth'];
                $wd  = $e['recurrence'] === 'last_week' ? 1 : (int)$e['rule_weekday'];
                $ruleMonth = $e['rule_month'] !== null && $e['rule_month'] !== '' ? (int)$e['rule_month'] : null;
                for ($m = $lower->modify('first day of this month'); $m <= $last; $m = $m->modify('+1 month')) {
                    if ($ruleMonth !== null && (int)$m->format('n') !== $ruleMonth) {
                        continue;
                    }
                    $d = self::nthWeekdayOfMonth((int)$m->format('Y'), (int)$m->format('n'), $nth, $wd);
                    if ($d) {
                        $out[] = $d;
                    }
                }
                break;

            case 'week_number':
                $week = (int)$e['rule_nth'];
                $wd = (int)($e['rule_weekday'] ?: 1);
                for ($y = (int)$lower->format('Y') - 1; $y <= (int)$last->format('Y'); $y++) {
                    $d = (new DateTimeImmutable())->setISODate($y, $week, $wd)->setTime(0, 0);
                    if ((int)$d->format('W') === $week) { // uge 53 findes ikke alle år
                        $out[] = $d;
                    }
                }
                break;
        }

        $res = [];
        foreach ($out as $d) {
            if ($d >= $lower && $d <= $last && $d >= $start) {
                $res[] = $d->format('Y-m-d');
            }
        }
        $res = array_values(array_unique($res));
        sort($res);
        return $res;
    }

    /** $nth: 1-5 eller -1 for sidste. $weekday: 1=mandag..7=søndag */
    public static function nthWeekdayOfMonth(int $y, int $m, int $nth, int $weekday): ?DateTimeImmutable
    {
        if ($nth === -1) {
            $d = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, cal_days_in_month_safe($y, $m)));
            $back = ((int)$d->format('N') - $weekday + 7) % 7;
            return $d->modify("-$back days");
        }
        $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m));
        $fwd = ($weekday - (int)$d->format('N') + 7) % 7;
        $d = $d->modify('+' . ($fwd + 7 * ($nth - 1)) . ' days');
        return (int)$d->format('n') === $m ? $d : null;
    }

    public static function describe(array $e): string
    {
        $int = max(1, (int)($e['rec_interval'] ?? 1));
        $monthTxt = fn() => $e['rule_month'] ? 'i ' . MONTHS_DA[(int)$e['rule_month']] : 'hver måned';
        $nthTxt = fn(int $n) => $n === -1 ? 'sidste' : $n . '.';
        $s = new DateTimeImmutable($e['start_date']);
        return match ($e['recurrence']) {
            'none'        => 'Én gang',
            'weekly'      => $int === 1 ? 'Hver uge (' . WEEKDAYS_DA[(int)$s->format('N')] . ')' : "Hver $int. uge (" . WEEKDAYS_DA[(int)$s->format('N')] . ')',
            'monthly'     => ($int === 1 ? 'Hver måned' : "Hver $int. måned") . ' d. ' . $s->format('j') . '.',
            'yearly'      => ($int === 1 ? 'Hvert år' : "Hvert $int. år") . ' d. ' . date_da($e['start_date']),
            'nth_weekday' => ucfirst($nthTxt((int)$e['rule_nth']) . ' ' . WEEKDAYS_DA[(int)$e['rule_weekday']] . ' ' . $monthTxt()),
            'last_week'   => 'Sidste uge ' . $monthTxt(),
            'week_number' => 'Uge ' . (int)$e['rule_nth'] . ', ' . WEEKDAYS_DA[(int)($e['rule_weekday'] ?: 1)] . ', hvert år',
            default       => $e['recurrence'],
        };
    }
}

function cal_days_in_month_safe(int $y, int $m): int
{
    return (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
}
