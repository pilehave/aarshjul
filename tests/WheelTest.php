<?php
declare(strict_types=1);

// --- Wheel::svg: ugenumre ---

/** Ugenumrene i hjulet i den rækkefølge, de tegnes */
function wheel_weeks(int $year): array
{
    return wheel_weeks_svg(Wheel::svg($year, [], false));
}

/** Ugenumrene i en færdig SVG */
function wheel_weeks_svg(string $svg): array
{
    preg_match_all('~<text [^>]*font-size="11" fill="#[0-9a-f]{6}"[^>]*>(\d+)</text>~', $svg, $m);
    return array_map('intval', $m[1]);
}

test('Alle uger har et nummer, også en delvis første uge (start i januar)', function () {
    // 1/1 2026 er en torsdag i uge 1; 2026 har 53 uger
    assert_same(range(1, 53), wheel_weeks(2026));
});

test('Alle uger har et nummer, når hjulet starter i august', function () {
    set_start_month(8);
    // 1/8 2026 er en lørdag i uge 31; hjulet slutter 31/7 2027 (en lørdag i uge 30)
    assert_same([...range(31, 53), ...range(1, 30)], wheel_weeks(2026));
});

test('En uge med kun én dag i hjulet får intet nummer', function () {
    set_start_month(6);
    // 1/6 2026 er en mandag i uge 23. Hjulet slutter 31/5 2027, som er mandag i uge 22: kun én dag, for smal til et nummer
    assert_same([...range(23, 53), ...range(1, 21)], wheel_weeks(2026));
});

test('Samme ugenummer i begge ender af hjulet vises kun én gang', function () {
    set_start_month(8);
    // 1/8 2028 er tirsdag i uge 31 (2028), og 31/7 2029 er tirsdag i uge 31 (2029). De to stumper mødes øverst
    // og vises som én uge 31. 2028 har 52 uger
    assert_same([...range(31, 52), ...range(1, 30)], wheel_weeks(2028));
});

test('Alle hjul fra 2020 til 2040 viser hvert ugenummer højst én gang og uden huller', function () {
    foreach ([1, 4, 8, 10] as $month) {
        set_start_month($month);
        for ($y = 2020; $y <= 2040; $y++) {
            $weeks = wheel_weeks($y);
            assert_same(count($weeks), count(array_unique($weeks)), "Dobbelt ugenummer i $y (start $month)");
            foreach (array_slice($weeks, 1) as $i => $w) {
                $prev = $weeks[$i];
                assert_true($w === $prev + 1 || ($w === 1 && $prev >= 52), "Hul mellem uge $prev og $w i $y (start $month)");
            }
        }
    }
});

// --- Wheel::span ---

test('Forekomst, der er begyndt i hjulet før, tegnes kun med sine dage i dette hjul', function () {
    // Sommerferie 19/7–1/8 2027 i hjulet 1/8 2027–31/7 2028: kun 1. august, ikke gjort 3 dage bred
    assert_same([0, 1], Wheel::span('2027-07-19', '2027-08-01', '2027-08-01', '2028-07-31'));
    assert_same([0, 2], Wheel::span('2027-07-30', '2027-08-02', '2027-08-01', '2028-07-31'));
});

test('Korte forekomster gøres 3 dage brede, men ikke ud over hjulets kanter', function () {
    assert_same([10, 13], Wheel::span('2027-08-11', '2027-08-11', '2027-08-01', '2028-07-31'), 'Én dag midt i hjulet');
    assert_same([0, 3], Wheel::span('2027-08-01', '2027-08-01', '2027-08-01', '2028-07-31'), 'Første dag');
    assert_same([363, 366], Wheel::span('2028-07-31', '2028-07-31', '2027-08-01', '2028-07-31'), 'Sidste dag (skudår, 366 dage)');
    assert_same([363, 366], Wheel::span('2028-07-30', '2028-08-02', '2027-08-01', '2028-07-31'), 'Fortsætter i næste hjul');
});

test('Lange forekomster skæres af ved hjulets kanter', function () {
    assert_same([351, 366], Wheel::span('2028-07-17', '2028-08-10', '2027-08-01', '2028-07-31'));
    assert_same([0, 14], Wheel::span('2027-07-19', '2027-08-14', '2027-08-01', '2028-07-31'));
});

// --- Wheel::svg: månedsnavne ---

/** Månedsnavnene i hjulet i den rækkefølge, de tegnes */
function wheel_months(int $year): array
{
    preg_match_all('~<textPath href="#p-m\d+"[^>]*>([^<]*)</textPath>~', Wheel::svg($year, [], false), $m);
    return $m[1];
}

test('Månedsnavne uden årstal, når hjulet starter i januar', function () {
    $months = wheel_months(2027);
    assert_same(['Januar', 'December'], [$months[0], $months[11]]);
});

test('Månedsnavne med årstal, når hjulet går på tværs af to år', function () {
    set_start_month(8);
    assert_same(['August 2027', 'September 2027', 'Oktober 2027', 'November 2027', 'December 2027', 'Januar 2028', 'Februar 2028',
        'Marts 2028', 'April 2028', 'Maj 2028', 'Juni 2028', 'Juli 2028'], wheel_months(2027));
    set_start_month(10);
    $months = wheel_months(2099);
    assert_same(['Oktober 2099', 'September 2100'], [$months[0], $months[11]], 'Århundredskiftet');
});

// --- Ugenumre følger hjulets runding ---

test('Tekst langs hjulet roteres med hjulet og vendes på den nederste halvdel', function () {
    $cases = [0 => 0.0, 45 => 45.0, 90 => 90.0, 135 => -45.0, 180 => 0.0, 225 => 45.0, 270 => -90.0, 315 => -45.0, 360 => 0.0, -2 => -2.0];
    foreach ($cases as $deg => $rot) {
        assert_same($rot, Wheel::textRotation($deg), "$deg°");
    }
});

test('Alle ugenumre i hjulet er roteret om deres eget midtpunkt', function () {
    preg_match_all('~<text x="([\d.]+)" y="([\d.]+)" font-size="11"[^>]*transform="rotate\((-?[\d.]+) ([\d.]+) ([\d.]+)\)"[^>]*>\d+</text>~',
        Wheel::svg(2026, [], false), $m, PREG_SET_ORDER);
    assert_same(53, count($m), 'Alle 53 uger i 2026 har en rotation');
    foreach ($m as [, $x, $y, $rot, $cx, $cy]) {
        assert_same([$x, $y], [$cx, $cy]);
        assert_true(abs((float)$rot) <= 90, "Rotation $rot får teksten til at stå på hovedet");
    }
});

// --- I dag ---

test('Den aktuelle uge er fed og rød, og stregen for i dag stopper ved ugeringen', function () {
    $svg = Wheel::svg((int)date('Y'), [], false); // hjulet starter i januar og indeholder altså i dag
    preg_match_all('~<text [^>]*font-size="11" fill="#d64545" font-weight="bold"[^>]*>(\d+)</text>~', $svg, $m);
    assert_same([(int)date('W')], array_map('intval', $m[1]), 'Kun den aktuelle uge er fremhævet');
    assert_true((bool)preg_match('~<line class="today" x1="([\d.]+)" y1="([\d.]+)" x2="([\d.]+)" y2="([\d.]+)"~', $svg, $l));
    $r = hypot((float)$l[3] - 500, (float)$l[4] - 500);
    assert_true(abs($r - 418) < 0.2, "Stregen slutter ved ugeringens inderkant (r=$r)");
    assert_same(1, preg_match_all('~<path class="today" ~', $svg), 'Én trekant i yderkanten');
});

test('Ingen markering af i dag i et hjul, der ikke indeholder i dag', function () {
    $svg = Wheel::svg((int)date('Y') + 2, [], false);
    assert_true(!str_contains($svg, 'class="today"') && !str_contains($svg, '#d64545'));
});

// --- Skygge ---

test('Hjulet har en skygge, og viewBox giver plads til den', function () {
    $svg = Wheel::svg(2026, [], false);
    assert_true((bool)preg_match('~<filter id="wheel-shadow"[^>]*><feDropShadow dx="0" dy="(\d+)" stdDeviation="(\d+)"~', $svg, $f), 'Filteret findes');
    assert_true((bool)preg_match('~<circle class="wheel-shadow" cx="500" cy="500" r="(\d+)"[^>]*filter="url\(#wheel-shadow\)"~', $svg, $c), 'Cirklen med skygge findes');
    assert_true((bool)preg_match('~viewBox="(-?\d+) (-?\d+) (\d+) (\d+)"~', $svg, $v));
    // Skyggen rækker ca. 3 x stdDeviation ud fra kanten (plus forskydningen nedad)
    $reach = (int)$c[1] + 3 * (int)$f[2] + (int)$f[1];
    assert_true(500 + $reach <= (int)$v[1] + (int)$v[3], "Skyggen ($reach) går ud over viewBox");
    assert_true(500 - $reach + (int)$f[1] >= (int)$v[1], 'Skyggen går ud over viewBox foroven');
});

// --- Skygge ved sammenstødet øverst ---

test('Skyggen ved sammenstødet ligger til højre for toppen, går fra midtercirklen til yderkanten og tager ikke klik', function () {
    $svg = Wheel::svg(2026, [], false);
    assert_true((bool)preg_match('~<linearGradient id="seam-shadow"[^>]*x1="500"[^>]*x2="(\d+)"~', $svg, $g), 'Gradienten findes');
    assert_true((int)$g[1] > 500, 'Udtoner mod højre');
    assert_true((bool)preg_match('~<g class="seam" pointer-events="none"><path d="M500 ([\d.]+) A\d+ \d+ 0 0 1 ([\d.]+) ([\d.]+) L[\d.]+ ([\d.]+)~', $svg, $p));
    assert_same([10.0, (float)$g[1]], [(float)$p[1], (float)$p[2]], 'Starter øverst ved yderkanten (r=490) og er lige så bred som gradienten');
    $w = (float)$p[2] - 500;
    assert_true(abs(hypot($w, 500 - (float)$p[3]) - 490) < 0.1, 'Ydre hjørne ligger på yderkanten');
    assert_true(abs(hypot($w, 500 - (float)$p[4]) - 120) < 0.1, 'Indre hjørne ligger på midtercirklen');
});
