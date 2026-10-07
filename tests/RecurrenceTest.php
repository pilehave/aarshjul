<?php
declare(strict_types=1);

// --- Recurrence::occurrences ---

test('Ingen gentagelse: kun på startdatoen', function () {
    $e = ev(['start_date' => '2026-03-10']);
    assert_same(['2026-03-10'], Recurrence::occurrences($e, '2026-01-01', '2026-12-31'));
    assert_same([], Recurrence::occurrences($e, '2027-01-01', '2027-12-31'));
});

test('Flerdagsbegivenhed, der starter før perioden, tæller med', function () {
    $e = ev(['start_date' => '2025-12-30', 'duration_days' => 5]);
    assert_same(['2025-12-30'], Recurrence::occurrences($e, '2026-01-01', '2026-12-31'));
});

test('Hver 2. uge', function () {
    $e = ev(['start_date' => '2026-01-05', 'recurrence' => 'weekly', 'rec_interval' => 2]);
    assert_same(['2026-01-05', '2026-01-19', '2026-02-02', '2026-02-16'],
        Recurrence::occurrences($e, '2026-01-01', '2026-02-28'));
});

test('Ugentlig med start før perioden følger samme ugedag', function () {
    $e = ev(['start_date' => '2025-12-29', 'recurrence' => 'weekly']);
    assert_same(['2026-01-05', '2026-01-12', '2026-01-19'],
        Recurrence::occurrences($e, '2026-01-01', '2026-01-20'));
});

test('Slutdato stopper gentagelsen', function () {
    $e = ev(['start_date' => '2026-01-05', 'recurrence' => 'weekly', 'end_date' => '2026-01-20']);
    assert_same(['2026-01-05', '2026-01-12', '2026-01-19'],
        Recurrence::occurrences($e, '2026-01-01', '2026-12-31'));
});

test('Månedlig d. 31. falder på sidste dag i korte måneder', function () {
    $e = ev(['start_date' => '2026-01-31', 'recurrence' => 'monthly']);
    assert_same(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
        Recurrence::occurrences($e, '2026-01-01', '2026-05-31'));
});

test('Årlig 29/2 bliver 28/2 i ikke-skudår', function () {
    $e = ev(['start_date' => '2024-02-29', 'recurrence' => 'yearly']);
    assert_same(['2025-02-28'], Recurrence::occurrences($e, '2025-01-01', '2025-12-31'));
    assert_same(['2028-02-29'], Recurrence::occurrences($e, '2028-01-01', '2028-12-31'));
});

test('Hvert 2. år', function () {
    $e = ev(['start_date' => '2024-06-01', 'recurrence' => 'yearly', 'rec_interval' => 2]);
    assert_same([], Recurrence::occurrences($e, '2025-01-01', '2025-12-31'));
    assert_same(['2026-06-01'], Recurrence::occurrences($e, '2026-01-01', '2026-12-31'));
});

test('2. tirsdag hver måned', function () {
    $e = ev(['recurrence' => 'nth_weekday', 'rule_nth' => 2, 'rule_weekday' => 2]);
    assert_same(['2026-01-13', '2026-02-10', '2026-03-10'],
        Recurrence::occurrences($e, '2026-01-01', '2026-03-31'));
});

test('Sidste fredag i april', function () {
    $e = ev(['recurrence' => 'nth_weekday', 'rule_nth' => -1, 'rule_weekday' => 5, 'rule_month' => 4]);
    assert_same(['2026-04-24'], Recurrence::occurrences($e, '2026-01-01', '2026-12-31'));
});

test('Sidste uge i maj starter med sidste mandag', function () {
    $e = ev(['recurrence' => 'last_week', 'rule_month' => 5]);
    assert_same(['2026-05-25'], Recurrence::occurrences($e, '2026-01-01', '2026-12-31'));
});

test('Uge 8, mandag', function () {
    $e = ev(['recurrence' => 'week_number', 'rule_nth' => 8, 'rule_weekday' => 1]);
    assert_same(['2026-02-16'], Recurrence::occurrences($e, '2026-01-01', '2026-12-31'));
});

test('Uge 53 springes over i år uden uge 53', function () {
    $e = ev(['recurrence' => 'week_number', 'rule_nth' => 53, 'rule_weekday' => 1]);
    assert_same(['2026-12-28'], Recurrence::occurrences($e, '2026-01-01', '2026-12-31'));
    assert_same([], Recurrence::occurrences($e, '2027-01-01', '2027-12-31'));
});

test('Årshjul, der starter i august, går på tværs af kalenderår', function () {
    set_start_month(8);
    [$from, $to] = year_bounds(2026);
    $e = ev(['start_date' => '2026-01-15', 'recurrence' => 'monthly']);
    $occ = Recurrence::occurrences($e, $from, $to);
    assert_same(12, count($occ));
    assert_same('2026-08-15', $occ[0]);
    assert_same('2027-07-15', $occ[11]);
});

// --- Recurrence::nthWeekdayOfMonth ---

test('5. mandag findes ikke i februar 2026', function () {
    assert_same(null, Recurrence::nthWeekdayOfMonth(2026, 2, 5, 1));
    assert_same('2026-03-30', Recurrence::nthWeekdayOfMonth(2026, 3, 5, 1)?->format('Y-m-d'));
});

// --- year_bounds / year_label ---

test('Kalenderår, når årshjulet starter i januar', function () {
    assert_same(['2026-01-01', '2026-12-31'], year_bounds(2026));
    assert_same('2026', year_label(2026));
});

test('Forskudt år, når årshjulet starter i august', function () {
    set_start_month(8);
    assert_same(['2026-08-01', '2027-07-31'], year_bounds(2026));
    assert_same('2026/27', year_label(2026));
    assert_same('2099/00', year_label(2099));
});

// --- period_da ---

test('Periode med årstal kun efter slutdatoen, når året er det samme', function () {
    assert_same('30. oktober 2026', period_da('2026-10-30', '2026-10-30'));
    assert_same('30. oktober – 2. november 2026', period_da('2026-10-30', '2026-11-02'));
    assert_same('28. december 2026 – 3. januar 2027', period_da('2026-12-28', '2027-01-03'));
});
