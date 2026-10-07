<?php
declare(strict_types=1);

// --- Wheel::svg: ugenumre ---

/** Ugenumrene i hjulet i den rækkefølge, de tegnes */
function wheel_weeks(int $year): array
{
    preg_match_all('~<text [^>]*font-size="9" fill="#7a8599"[^>]*>(\d+)</text>~', Wheel::svg($year, [], false), $m);
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
    assert_same(['August 27', 'September 27', 'Oktober 27', 'November 27', 'December 27', 'Januar 28', 'Februar 28',
        'Marts 28', 'April 28', 'Maj 28', 'Juni 28', 'Juli 28'], wheel_months(2027));
    set_start_month(10);
    $months = wheel_months(2099);
    assert_same(['Oktober 99', 'September 00'], [$months[0], $months[11]], 'Årtusindskiftet');
});
