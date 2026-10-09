<?php
declare(strict_types=1);

// --- Period: zoom på kvartal og måned (issue #11) ---

/** [fra, til, titel, undertitel] for en periode */
function period_summary(array $p): array
{
    return [$p['from'], $p['to'], Period::title($p), Period::subtitle($p)];
}

test('Hele året uden zoom, og et ugyldigt zoom giver også hele året', function () {
    assert_same(['2026-01-01', '2026-12-31', '2026', null], period_summary(Period::fromRequest(2026, null)));
    foreach (['q5', 'q0', '2026-13', 'abc', ''] as $bad) {
        assert_same('year', Period::fromRequest(2026, $bad)['kind'], $bad);
    }
});

test('Kvartalerne følger startmåneden', function () {
    assert_same(['2026-04-01', '2026-06-30', 'K2', '2026'], period_summary(Period::fromRequest(2026, 'q2')));
    set_start_month(8);
    assert_same(['2026-08-01', '2026-10-31', 'K1', '2026/27'], period_summary(Period::fromRequest(2026, 'q1')));
    assert_same(['2027-05-01', '2027-07-31', 'K4', '2026/27'], period_summary(Period::fromRequest(2026, 'q4')));
});

test('En måned bestemmer selv årshjulets år og kvartal', function () {
    set_start_month(8);
    $p = Period::fromRequest(1999, '2027-02'); // årstallet i adressen ignoreres ved en måned
    assert_same(['2027-02-01', '2027-02-28', 'Februar', '2027'], period_summary($p));
    assert_same([2026, 3], [$p['year'], $p['quarter']], 'Februar 2027 er K3 i 2026/27');
    assert_same([2026, 1], [Period::fromRequest(0, '2026-08')['year'], Period::fromRequest(0, '2026-08')['quarter']]);
    assert_same('2028-02-29', Period::fromRequest(0, '2028-02')['to'], 'Skudår');
});

test('Pilene går til forrige og næste periode, også hen over årsskiftet', function () {
    set_start_month(8);
    $zoom = fn(array $p) => [$p['year'], $p['zoom']];
    assert_same([2027, 'q1'], $zoom(Period::step(Period::quarter(2026, 4), 1)));
    assert_same([2025, 'q4'], $zoom(Period::step(Period::quarter(2026, 1), -1)));
    assert_same([2026, 'q3'], $zoom(Period::step(Period::quarter(2026, 2), 1)));
    assert_same([2027, '2027-08'], $zoom(Period::step(Period::month(2027, 7), 1)), 'Juli 2027 → august 2027 i næste årshjul');
    assert_same([2026, '2026-12'], $zoom(Period::step(Period::month(2027, 1), -1)));
    assert_same([2027, null], $zoom(Period::step(Period::fromRequest(2026, null), 1)), 'Helt år skifter år');
});

test('Et kvartal har tre måneder', function () {
    set_start_month(11);
    assert_same(['2026-11', '2026-12', '2027-01'], array_column(Period::monthsOfQuarter(2026, 1), 'month'));
    assert_same('K1 2026/27', Period::label(Period::quarter(2026, 1)));
    assert_same('November 2026', Period::label(Period::month(2026, 11)));
});

// --- Wheel::svg ved zoom ---

test('Hjulet for en måned viser uger yderst og alle dage med ugedag', function () {
    $svg = Wheel::svg(2026, [], false, Period::month(2026, 10));
    preg_match_all('~<text class="day"[^>]*>(\d+) ([A-Z])</text>~', $svg, $d);
    assert_same(range(1, 31), array_map('intval', $d[1]), 'Alle 31 dage');
    assert_same(['T', 'F', 'L', 'S', 'M'], array_slice($d[2], 0, 5), '1/10 2026 er en torsdag');
    preg_match_all('~<textPath href="#p-w\d+"[^>]*>([^<]*)</textPath>~', $svg, $w);
    assert_same(['Uge 40', 'Uge 41', 'Uge 42', 'Uge 43', 'Uge 44'], $w[1]);
    assert_same(9, preg_match_all('~class="weekend" [^>]*fill="#eef1f6"~', $svg), '9 weekenddage i dagringen');
    assert_true(str_contains($svg, '>Oktober</text>') && str_contains($svg, '>2026</text>'), 'Midten viser perioden over året');
});

test('Hjulet for et kvartal viser tre måneder og deres ugenumre', function () {
    set_start_month(8);
    $svg = Wheel::svg(2026, [], false, Period::quarter(2026, 1));
    preg_match_all('~<textPath href="#p-m\d+"[^>]*>([^<]*)</textPath>~', $svg, $m);
    assert_same(['August 2026', 'September 2026', 'Oktober 2026'], $m[1]);
    assert_same([...range(31, 44)], wheel_weeks_svg($svg));
});

test('Korte forekomster er kun 1 dag brede i en måned, men 3 i et helt år', function () {
    assert_same([5, 6], Wheel::span('2026-10-06', '2026-10-06', '2026-10-01', '2026-10-31'));
    assert_same([278, 281], Wheel::span('2026-10-06', '2026-10-06', '2026-01-01', '2026-12-31'));
});
