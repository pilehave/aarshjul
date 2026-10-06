<?php
declare(strict_types=1);

// --- Events::prerequisites ---

test('Forudsætning er seneste forekomst på eller før datoen', function () {
    $a = ev(['id' => 1, 'start_date' => '2025-03-01', 'recurrence' => 'yearly']);
    $b = ev(['id' => 2, 'start_date' => '2026-06-01', 'depends_on' => [1]]);
    $all = [1 => $a, 2 => $b];

    $p = Events::prerequisites($b, '2026-06-01', $all, []);
    assert_same(1, count($p));
    assert_same('2026-03-01', $p[0]['date']);
    assert_same(false, $p[0]['done']);

    $p = Events::prerequisites($b, '2026-06-01', $all, ['1|2026-03-01' => true]);
    assert_same(true, $p[0]['done']);

    // Afkrydsning af sidste års forekomst tæller ikke
    $p = Events::prerequisites($b, '2026-06-01', $all, ['1|2025-03-01' => true]);
    assert_same(false, $p[0]['done']);
});

test('Forudsætning samme dag tæller med', function () {
    $a = ev(['id' => 1, 'start_date' => '2026-06-01']);
    $b = ev(['id' => 2, 'start_date' => '2026-06-01', 'depends_on' => [1]]);
    $p = Events::prerequisites($b, '2026-06-01', [1 => $a, 2 => $b], []);
    assert_same('2026-06-01', $p[0]['date']);
});

test('Forudsætning uden forekomst inden for et år blokerer ikke', function () {
    $a = ev(['id' => 1, 'start_date' => '2024-01-01']);
    $b = ev(['id' => 2, 'start_date' => '2026-06-01', 'depends_on' => [1]]);
    $p = Events::prerequisites($b, '2026-06-01', [1 => $a, 2 => $b], []);
    assert_same(null, $p[0]['date']);
    assert_same(true, $p[0]['done']);
});

test('Slettet forudsætning ignoreres', function () {
    $b = ev(['id' => 2, 'depends_on' => [99]]);
    assert_same([], Events::prerequisites($b, '2026-06-01', [2 => $b], []));
});

// --- Events::wouldCreateCycle ---

test('Cirkulære afhængigheder opdages', function () {
    $all = [
        1 => ev(['id' => 1, 'depends_on' => [2]]),
        2 => ev(['id' => 2, 'depends_on' => [3]]),
        3 => ev(['id' => 3, 'depends_on' => []]),
    ];
    assert_true(Events::wouldCreateCycle(3, [1], $all), '3 → 1 → 2 → 3 er en cyklus');
    assert_true(Events::wouldCreateCycle(1, [1], $all), 'En begivenhed kan ikke afhænge af sig selv');
    assert_true(!Events::wouldCreateCycle(3, [], $all), 'Ingen afhængigheder giver ingen cyklus');
    assert_true(!Events::wouldCreateCycle(4, [1], $all), 'Ny begivenhed, der afhænger af 1, er ok');
});
